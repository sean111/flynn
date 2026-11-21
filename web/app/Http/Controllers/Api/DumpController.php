<?php

namespace App\Http\Controllers\Api;

use App\Jobs\IngestDump;
use App\Support\FlynnDumpParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DumpController extends ApiController
{
    public function store(Request $request, FlynnDumpParser $parser): JsonResponse
    {
        $user = $request->user();
        if ($request->user()->currentAccessToken() && ! $request->user()->tokenCan('dumps:write')) {
            return $this->error('insufficient_scope', 'This desktop token cannot upload dumps.', 403);
        }
        $format = null;
        $payload = null;
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            if (! $file->isValid()) {
                return $this->error('invalid_saved_variables', 'Could not read the uploaded file. Verify it came from Flynn and retry after /reload or logout.', 422);
            }
            if ($file->getSize() > 10 * 1024 * 1024) {
                return $this->error('payload_too_large', 'Flynn.lua files are limited to 10 MiB.', 413, ['limitBytes' => 10 * 1024 * 1024]);
            }
            $path = $file->getRealPath();
            if (! is_string($path) || ! is_readable($path)) {
                return $this->error('invalid_saved_variables', 'Could not read the uploaded file. Verify it came from Flynn and retry after /reload or logout.', 422);
            }
            try {
                $payload = $file->getContent();
            } catch (\Throwable) {
                return $this->error('invalid_saved_variables', 'Could not read the uploaded file. Verify it came from Flynn and retry after /reload or logout.', 422);
            }
            if (! is_string($payload)) {
                return $this->error('invalid_saved_variables', 'Could not read the uploaded file. Verify it came from Flynn and retry after /reload or logout.', 422);
            }
            try {
                $parser->parseLua($payload);
            } catch (\InvalidArgumentException $exception) {
                return $this->parserError($exception);
            }
            $format = 'lua';
        } else {
            $raw = $request->getContent();
            if (strlen($raw) > 11 * 1024 * 1024) {
                return $this->error('payload_too_large', 'Dump API request is larger than the allowed upload limit.', 413, ['limitBytes' => 11 * 1024 * 1024]);
            }
            try {
                $envelope = $parser->decodeJsonValue($raw);
            } catch (\InvalidArgumentException $exception) {
                return $this->parserError($exception);
            }
            if (! is_array($envelope) || ! is_string($envelope['format'] ?? null) || ! array_key_exists('payload', $envelope)) {
                return $this->error('invalid_payload', 'Provide format and payload, or multipart file.', 422);
            }
            $format = $envelope['format'];
            if ($format === 'structuredJson') {
                if (! is_array($envelope['payload'])) {
                    return $this->error('invalid_payload', 'structuredJson payload must be a data-contract JSON object.', 422);
                }
                $payload = json_encode($envelope['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($payload) > 10 * 1024 * 1024) {
                    return $this->error('payload_too_large', 'Structured JSON documents are limited to 10 MiB.', 413, ['limitBytes' => 10 * 1024 * 1024]);
                }
                // Validate before accepting so malformed dumps do not occupy queue capacity.
                try {
                    $parser->parseJson($payload);
                } catch (\InvalidArgumentException $exception) {
                    return $this->parserError($exception);
                }
            } elseif ($format === 'exportString') {
                if (! is_string($envelope['payload'])) {
                    return $this->error('invalid_export_string', 'Export payload must be a string.', 422);
                }
                $payload = $envelope['payload'];
                try {
                    $parser->parseExport($payload);
                } catch (\InvalidArgumentException $exception) {
                    return $this->parserError($exception);
                }
            } else {
                return $this->error('invalid_payload', 'Supported dump formats are structuredJson and exportString.', 422);
            }
        }
        $id = (string) Str::uuid();
        $path = 'private/dumps/'.$id.'.payload';
        Storage::disk('local')->put($path, $payload);
        DB::table('dumps')->insert([
            'id' => $id, 'uploader_id' => $user->id, 'staging_path' => $path, 'checksum' => hash('sha256', $payload),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        IngestDump::dispatch($id, $path, $format);

        return $this->ok(['dumpId' => $id, 'status' => 'queued'], 202);
    }

    public function show(Request $request, string $dumpId): JsonResponse
    {
        $dump = DB::table('dumps')->where('id', $dumpId)->where('uploader_id', $request->user()->id)->first();
        if (! $dump) {
            return $this->error('not_found', 'Dump was not found.', 404);
        }

        return $this->ok([
            'dumpId' => $dump->id, 'status' => $dump->status, 'charactersSeen' => $dump->characters_seen,
            'charactersUpdated' => $dump->characters_updated, 'error' => $dump->error ? json_decode($dump->error, true) : null,
            'results' => DB::table('dump_character_results')->where('dump_id', $dumpId)->get()->map(fn ($result) => [
                'identity' => $result->identity, 'status' => $result->status, 'code' => $result->code,
                'message' => $result->message, 'characterId' => $result->character_id,
            ]),
        ]);
    }

    private function parserError(\InvalidArgumentException $exception): JsonResponse
    {
        [$code, $message] = array_pad(explode(':', $exception->getMessage(), 2), 2, 'The dump could not be parsed.');
        $code = trim($code);
        $status = $code === 'payload_too_large' ? 413 : 422;
        $details = [];
        if ($code === 'payload_too_large') {
            preg_match('/limited to ([0-9]+) MiB/', $message, $match);
            if ($match) {
                $details['limitBytes'] = (int) $match[1] * 1024 * 1024;
            } else {
                $details['limitBytes'] = 11 * 1024 * 1024;
            }
        }
        if ($code === 'unsupported_schema_version' && preg_match('/schema version ([0-9]+)/i', $message, $match)) {
            $details = ['receivedSchemaVersion' => (int) $match[1], 'supportedSchemaVersions' => [1], 'action' => 'update_addon_or_server'];
        }

        return $this->error($code, trim($message), $status, $details);
    }
}
