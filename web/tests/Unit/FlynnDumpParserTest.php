<?php

namespace Tests\Unit;

use App\Support\FlynnDumpParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FlynnDumpParserTest extends TestCase
{
    public function test_parses_a_valid_saved_variables_literal_without_execution(): void
    {
        $document = (new FlynnDumpParser)->parseLua($this->savedVariables());

        $this->assertSame(1, $document['schemaVersion']);
        $this->assertSame('Examplemage', $document['characters']['retail']['us']['Area 52']['Examplemage']['characterName']);
    }

    public function test_rejects_executable_lua_syntax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlynnDumpParser)->parseLua('FlynnDB = os.execute("bad")');
    }

    public function test_rejects_duplicate_lua_table_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlynnDumpParser)->parseLua('FlynnDB = { ["schemaVersion"] = 1, ["schemaVersion"] = 1 }');
    }

    public function test_rejects_multiple_saved_variables_assignments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlynnDumpParser)->parseLua("FlynnDB = {}\nFlynnDB = {}");
    }

    public function test_rejects_sparse_lua_array_indices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlynnDumpParser)->parseLua('FlynnDB = { ["schemaVersion"] = 1, ["characters"] = { [1] = "a", [3] = "b" } }');
    }

    public function test_rejects_duplicate_json_object_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlynnDumpParser)->parseJson('{"schemaVersion":1,"schemaVersion":1,"characters":{}}');
    }

    public function test_rejects_unsupported_schema_with_actionable_error(): void
    {
        try {
            (new FlynnDumpParser)->parseJson('{"schemaVersion":2,"characters":{"retail":{"us":{"Area 52":{"Mage":{"gameFlavor":"retail","region":"us","realm":"Area 52","characterName":"Mage","guid":"Player-123","faction":"horde","class":"MAGE","capturedAt":1794528000,"professions":[]}}}}}}');
            $this->fail('Unsupported schema should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unsupported_schema_version', $exception->getMessage());
            $this->assertStringContainsString('Update the Flynn addon', $exception->getMessage());
        }
    }

    public function test_parses_a_deflate_export_string(): void
    {
        $json = json_encode(['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Area 52' => ['Examplemage' => [
            'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => 'Examplemage',
            'guid' => 'Player-3676-0ABCDEF0', 'faction' => 'horde', 'class' => 'MAGE', 'capturedAt' => 1794528000, 'professions' => [],
        ]]]]]], JSON_THROW_ON_ERROR);
        $encoded = 'FLYNN1:'.base64_encode(gzdeflate($json));

        $document = (new FlynnDumpParser)->parseExport($encoded);

        $this->assertSame(1, $document['schemaVersion']);
    }

    public function test_stops_inflating_when_export_exceeds_decoded_limit(): void
    {
        $oversized = json_encode(['schemaVersion' => 1, 'characters' => [], 'padding' => str_repeat('x', 21 * 1024 * 1024)], JSON_THROW_ON_ERROR);
        $encoded = 'FLYNN1:'.base64_encode(gzdeflate($oversized, 9));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload_too_large');
        (new FlynnDumpParser)->parseExport($encoded);
    }

    public function test_export_json_may_exceed_structured_json_limit_but_stays_within_inflated_limit(): void
    {
        $document = ['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Area 52' => ['Examplemage' => [
            'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => 'Examplemage',
            'guid' => 'Player-3676-0ABCDEF0', 'faction' => 'horde', 'class' => 'MAGE', 'capturedAt' => 1794528000, 'professions' => [],
        ]]]]], 'padding' => str_repeat('x', 11 * 1024 * 1024)];
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $parser = new FlynnDumpParser;

        try {
            $parser->parseJson($json);
            $this->fail('Structured JSON must retain its 10 MiB limit.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('payload_too_large', $exception->getMessage());
        }

        $decoded = $parser->parseExport('FLYNN1:'.base64_encode(gzdeflate($json, 9)));
        $this->assertSame(1, $decoded['schemaVersion']);
    }

    private function savedVariables(): string
    {
        return <<<'LUA'
-- Flynn data export
FlynnDB = {
  ["schemaVersion"] = 1,
  ["characters"] = {
    ["retail"] = {
      ["us"] = {
        ["Area 52"] = {
          ["Examplemage"] = {
            ["gameFlavor"] = "retail", ["region"] = "us", ["realm"] = "Area 52",
            ["characterName"] = "Examplemage", ["guid"] = "Player-3676-0ABCDEF0",
            ["faction"] = "horde", ["class"] = "MAGE", ["capturedAt"] = 1794528000,
            ["professions"] = {},
          },
        },
      },
    },
  },
}
LUA;
    }
}
