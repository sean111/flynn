<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

#[Signature('flynn:catalog-import {file} {--game-flavor=} : Load an offline normalized game catalog export')]
#[Description('Import flavor-aware profession, spell, and item metadata from a trusted offline JSON catalog')]
final class ImportFlynnCatalog extends Command
{
    private const MAX_BYTES = 100 * 1024 * 1024;

    public function handle(): int
    {
        $path = $this->argument('file');
        $flavor = $this->option('game-flavor');
        if (! in_array($flavor, ['retail', 'classic_era', 'classic', 'forever'], true)) {
            $this->error('Provide --game-flavor=retail, --game-flavor=classic_era, --game-flavor=classic, or --game-flavor=forever.');

            return self::FAILURE;
        }
        if (! is_file($path) || ! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            $this->error('Catalog file must be readable and no larger than 100 MiB.');

            return self::FAILURE;
        }
        $source = file_get_contents($path);
        if ($source === false || preg_match('//u', $source) !== 1) {
            $this->error('Catalog file must contain valid UTF-8 JSON.');

            return self::FAILURE;
        }
        try {
            $document = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->error('Catalog file is malformed JSON.');

            return self::FAILURE;
        }
        if (! is_array($document)) {
            $this->error('Catalog document must be a JSON object.');

            return self::FAILURE;
        }
        $checksum = hash('sha256', $source);
        $existingRun = DB::table('catalog_import_runs')->where('game_flavor', $flavor)->where('checksum', $checksum)->first();
        if ($existingRun?->status === 'completed') {
            $this->info('This catalog snapshot has already been imported for '.$flavor.'.');

            return self::SUCCESS;
        }
        $runId = $existingRun?->id ?? (string) Str::uuid();
        if ($existingRun) {
            DB::table('catalog_import_runs')->where('id', $runId)->update(['status' => 'processing', 'error' => null, 'finished_at' => null, 'updated_at' => now()]);
        } else {
            DB::table('catalog_import_runs')->insert([
                'id' => $runId, 'game_flavor' => $flavor, 'checksum' => $checksum,
                'source_name' => mb_substr(basename($path), 0, 255), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        try {
            $counts = DB::transaction(function () use ($document, $flavor) {
                $counts = ['professions' => 0, 'spells' => 0, 'items' => 0];
                $totalRecords = 0;
                foreach ([
                    'professions' => ['catalog_professions', 'skillLineID'],
                    'spells' => ['catalog_spells', 'spellID'],
                    'items' => ['catalog_items', 'itemID'],
                ] as $collection => [$table, $idField]) {
                    $records = $document[$collection] ?? [];
                    if (! is_array($records) || ! array_is_list($records) || count($records) > 500_000) {
                        throw new \InvalidArgumentException($collection.' must be a JSON array with no more than 500,000 entries.');
                    }
                    $rows = [];
                    $seenIds = [];
                    foreach ($records as $record) {
                        if (! is_array($record) || ! is_int($record[$idField] ?? null) || $record[$idField] < 1 || $record[$idField] > 9_007_199_254_740_991) {
                            throw new \InvalidArgumentException($collection.' entries require a positive safe integer '.$idField.'.');
                        }
                        if (isset($seenIds[$record[$idField]])) {
                            throw new \InvalidArgumentException($collection.' contains a duplicate '.$idField.'.');
                        }
                        $seenIds[$record[$idField]] = true;
                        if (++$totalRecords > 500_000) {
                            throw new \InvalidArgumentException('Catalog snapshot may contain no more than 500,000 total entries.');
                        }
                        if (! is_string($record['name'] ?? null) || trim($record['name']) === '' || mb_strlen($record['name']) > 255) {
                            throw new \InvalidArgumentException($collection.' entries require a non-empty name of at most 255 characters.');
                        }
                        if (isset($record['description']) && (! is_string($record['description']) || mb_strlen($record['description']) > 10000)) {
                            throw new \InvalidArgumentException($collection.' descriptions must be at most 10,000 characters.');
                        }
                        if (isset($record['quality']) && (! is_int($record['quality']) || $record['quality'] < 0 || $record['quality'] > 255)) {
                            throw new \InvalidArgumentException($collection.' quality must be an integer from 0 to 255.');
                        }
                        if (isset($record['icon']) && (! is_string($record['icon']) || mb_strlen($record['icon']) > 255)) {
                            throw new \InvalidArgumentException($collection.' icon must be a string of at most 255 characters.');
                        }
                        $databaseIdField = match ($idField) {
                            'skillLineID' => 'skill_line_id',
                            'spellID' => 'spell_id',
                            default => 'item_id',
                        };
                        $row = [
                            'id' => (string) Str::uuid(), 'game_flavor' => $flavor, $databaseIdField => $record[$idField],
                            'name' => trim($record['name']), 'description' => $record['description'] ?? null,
                            'icon' => $record['icon'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                        ];
                        if ($table !== 'catalog_professions') {
                            $row['quality'] = $record['quality'] ?? null;
                        }
                        $rows[] = $row;
                        if (count($rows) === 500) {
                            $this->upsert($table, $databaseIdField, $rows);
                            $counts[$collection] += count($rows);
                            $rows = [];
                        }
                    }
                    if ($rows !== []) {
                        $this->upsert($table, $databaseIdField, $rows);
                        $counts[$collection] += count($rows);
                    }
                }

                return $counts;
            });
            DB::table('catalog_import_runs')->where('id', $runId)->update([
                'status' => 'completed', 'professions_imported' => $counts['professions'], 'spells_imported' => $counts['spells'],
                'items_imported' => $counts['items'], 'finished_at' => now(), 'updated_at' => now(),
            ]);
            $this->info('Imported '.$counts['professions'].' professions, '.$counts['spells'].' spells, and '.$counts['items'].' items for '.$flavor.'.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::table('catalog_import_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000), 'finished_at' => now(), 'updated_at' => now(),
            ]);
            $this->error('Catalog import failed validation or could not be stored. Review catalog_import_runs for operator details.');

            return self::FAILURE;
        }
    }

    private function upsert(string $table, string $conflictColumn, array $rows): void
    {
        $updates = ['name', 'description', 'icon', 'updated_at'];
        if ($table !== 'catalog_professions') {
            $updates[] = 'quality';
        }
        DB::table($table)->upsert($rows, ['game_flavor', $conflictColumn], $updates);
    }
}
