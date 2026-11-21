<?php

namespace App\Jobs;

use App\Support\FlynnDumpParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class IngestDump implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 90;

    public array $backoff = [10, 30, 90, 300];

    public function __construct(public readonly string $dumpId, public readonly string $stagingPath, public readonly string $format)
    {
        $this->onQueue('ingestion');
    }

    public function handle(FlynnDumpParser $parser): void
    {
        DB::table('dumps')->where('id', $this->dumpId)->update(['status' => 'processing', 'updated_at' => now()]);
        try {
            $payload = Storage::disk('local')->get($this->stagingPath);
            $document = match ($this->format) {
                'lua' => $parser->parseLua($payload),
                'structuredJson' => $parser->parseJson($payload),
                'exportString' => $parser->parseExport($payload),
                default => throw new \InvalidArgumentException('invalid_payload: Unsupported dump format.'),
            };
        } catch (\InvalidArgumentException $exception) {
            $parts = explode(':', $exception->getMessage(), 2);
            DB::table('dumps')->where('id', $this->dumpId)->update([
                'status' => 'failed', 'error' => json_encode(['code' => trim($parts[0]), 'message' => trim($parts[1] ?? 'The dump could not be processed.')]), 'updated_at' => now(),
            ]);
            Storage::disk('local')->delete($this->stagingPath);

            return;
        }
        $this->persist($document);
        DB::table('dumps')->where('id', $this->dumpId)->update(['status' => 'completed', 'updated_at' => now()]);
        Storage::disk('local')->delete($this->stagingPath);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('dumps')->where('id', $this->dumpId)->update([
            'status' => 'failed', 'error' => json_encode(['code' => 'ingestion_failed', 'message' => 'The dump could not be processed. Retry later or contact support with the dump ID.']), 'updated_at' => now(),
        ]);
        Storage::disk('local')->delete($this->stagingPath);
    }

    private function persist(array $document): void
    {
        $characterCount = 0;
        $updated = 0;
        DB::transaction(function () use ($document, &$characterCount, &$updated) {
            DB::table('dump_character_results')->where('dump_id', $this->dumpId)->delete();
            foreach ($document['characters'] as $flavor => $regions) {
                foreach ($regions as $region => $realms) {
                    foreach ($realms as $realm => $records) {
                        foreach ($records as $name => $record) {
                            $characterCount++;
                            $character = DB::table('characters')->where([
                                'game_flavor' => $flavor, 'region' => $region, 'realm' => $realm, 'character_name' => $name,
                            ])->lockForUpdate()->first();
                            $identity = implode('/', [$flavor, $region, $realm, $name]);
                            if ($character && $character->owner_id && $character->owner_id !== DB::table('dumps')->where('id', $this->dumpId)->value('uploader_id')) {
                                $this->result($identity, 'ownership_conflict', 'character_owned', 'This character is claimed by another Flynn account; its data was not changed.', $character->id);

                                continue;
                            }
                            $uploaderId = DB::table('dumps')->where('id', $this->dumpId)->value('uploader_id');
                            if ($character && $record['capturedAt'] < $character->captured_at) {
                                $this->result($identity, 'stale_snapshot', 'stale_snapshot', 'This export was captured before the current character snapshot; newer data was preserved.', $character->id);

                                continue;
                            }
                            $values = [
                                'game_flavor' => $flavor, 'region' => $region, 'realm' => $realm, 'character_name' => $name,
                                'guid' => $record['guid'], 'faction' => $record['faction'], 'class' => $record['class'],
                                'captured_at' => $record['capturedAt'], 'last_synced_at' => now(), 'updated_at' => now(),
                            ];
                            if (! $character) {
                                $characterId = (string) Str::uuid();
                                DB::table('characters')->insert($values + ['id' => $characterId, 'owner_id' => null, 'created_at' => now()]);
                                $character = (object) ['id' => $characterId, 'owner_id' => null];
                            } else {
                                $characterId = $character->id;
                                DB::table('characters')->where('id', $characterId)->update($values);
                            }
                            if (! $character->owner_id) {
                                DB::table('character_import_authorizations')->updateOrInsert(
                                    ['character_id' => $characterId, 'uploader_id' => $uploaderId],
                                    ['id' => (string) Str::uuid(), 'claimed_at' => null, 'created_at' => now(), 'updated_at' => now()],
                                );
                            }
                            $skillLineIds = array_column($record['professions'], 'skillLineID');
                            $professionPrune = DB::table('character_professions')->where('character_id', $characterId);
                            if ($skillLineIds !== []) {
                                $professionPrune->whereNotIn('skill_line_id', $skillLineIds);
                            }
                            $professionPrune->delete();
                            foreach ($record['professions'] as $profession) {
                                $this->persistProfession($characterId, $flavor, $profession);
                            }
                            $this->result($identity, $character->owner_id ? 'updated' : 'claimable', null, $character->owner_id ? 'Character snapshot updated.' : 'Imported. Claim this character explicitly to associate it with guilds.', $characterId);
                            $updated++;
                        }
                    }
                }
            }
            DB::table('dumps')->where('id', $this->dumpId)->update(['characters_seen' => $characterCount, 'characters_updated' => $updated, 'updated_at' => now()]);
        });
    }

    private function persistProfession(string $characterId, string $flavor, array $profession): void
    {
        $existingProfession = DB::table('character_professions')->where('character_id', $characterId)->where('skill_line_id', $profession['skillLineID'])->first();
        $professionId = $existingProfession?->id ?? (string) Str::uuid();
        $professionValues = [
            'character_id' => $characterId, 'skill_line_id' => $profession['skillLineID'],
            'current_skill' => $profession['currentSkill'], 'max_skill' => $profession['maxSkill'],
            'specialization' => isset($profession['specialization']) ? json_encode($profession['specialization']) : null,
            'updated_at' => now(),
        ];
        if ($existingProfession) {
            DB::table('character_professions')->where('id', $professionId)->update($professionValues);
        } else {
            DB::table('character_professions')->insert($professionValues + ['id' => $professionId, 'created_at' => now()]);
        }
        $spellIds = array_column($profession['recipes'], 'spellID');
        $recipePrune = DB::table('recipes')->where('profession_id', $professionId);
        if ($spellIds !== []) {
            $recipePrune->whereNotIn('spell_id', $spellIds);
        }
        $recipePrune->delete();
        foreach ($profession['recipes'] as $recipe) {
            $existingRecipe = DB::table('recipes')->where('profession_id', $professionId)->where('spell_id', $recipe['spellID'])->first();
            $recipeId = $existingRecipe?->id ?? (string) Str::uuid();
            $recipeValues = ['game_flavor' => $flavor, 'profession_id' => $professionId, 'spell_id' => $recipe['spellID'], 'quality' => isset($recipe['quality']) ? json_encode($recipe['quality']) : null, 'updated_at' => now()];
            if ($existingRecipe) {
                DB::table('recipes')->where('id', $recipeId)->update($recipeValues);
                DB::table('recipe_outputs')->where('recipe_id', $recipeId)->delete();
                DB::table('recipe_reagents')->where('recipe_id', $recipeId)->delete();
                DB::table('recipe_reagent_slots')->where('recipe_id', $recipeId)->delete();
            } else {
                DB::table('recipes')->insert($recipeValues + ['id' => $recipeId, 'created_at' => now()]);
            }
            foreach ($recipe['outputs'] as $output) {
                DB::table('recipe_outputs')->insert(['id' => (string) Str::uuid(), 'recipe_id' => $recipeId, 'item_id' => $output['itemID'], 'quantity' => $output['quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($recipe['reagents'] as $reagent) {
                DB::table('recipe_reagents')->insert(['id' => (string) Str::uuid(), 'recipe_id' => $recipeId, 'item_id' => $reagent['itemID'], 'quantity' => $reagent['quantity'], 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($recipe['reagentSlots'] ?? [] as $slot) {
                $slotId = (string) Str::uuid();
                DB::table('recipe_reagent_slots')->insert(['id' => $slotId, 'recipe_id' => $recipeId, 'slot_index' => $slot['slotIndex'], 'kind' => $slot['kind'], 'quantity' => $slot['quantity'], 'created_at' => now(), 'updated_at' => now()]);
                $seenOptionItemIds = [];
                foreach ($slot['options'] as $option) {
                    if (isset($seenOptionItemIds[$option['itemID']])) {
                        continue;
                    }
                    $seenOptionItemIds[$option['itemID']] = true;
                    DB::table('recipe_slot_options')->insert(['id' => (string) Str::uuid(), 'slot_id' => $slotId, 'item_id' => $option['itemID'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    private function result(string $identity, string $status, ?string $code, string $message, ?string $characterId): void
    {
        DB::table('dump_character_results')->insert([
            'id' => (string) Str::uuid(), 'dump_id' => $this->dumpId, 'identity' => $identity, 'status' => $status,
            'code' => $code, 'message' => $message, 'character_id' => $characterId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
