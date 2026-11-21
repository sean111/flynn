<?php

namespace Tests\Unit;

use App\Support\FlynnDumpParser;
use App\Support\FlynnJsonParser;
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

    public function test_accepts_forever_as_an_independent_character_namespace(): void
    {
        $document = (new FlynnDumpParser)->parseJson(json_encode([
            'schemaVersion' => 1,
            'characters' => ['forever' => ['us' => ['Camelot' => ['Anvil' => [
                'gameFlavor' => 'forever', 'region' => 'us', 'realm' => 'Camelot', 'characterName' => 'Anvil',
                'guid' => 'Player-18-ghi', 'faction' => 'alliance', 'class' => 'WARRIOR', 'capturedAt' => 1794528000, 'professions' => [],
            ]]]]],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('forever', $document['characters']['forever']['us']['Camelot']['Anvil']['gameFlavor']);
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

    public function test_parses_privacy_safe_realistic_lua_and_json_fixtures(): void
    {
        $parser = new FlynnDumpParser;
        $lua = file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.lua');
        $json = file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.json');

        $luaDocument = $parser->parseLua($lua);
        $jsonDocument = $parser->parseJson($json);

        $this->assertSame('Examplewarlock', $luaDocument['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['characterName']);
        $this->assertSame($luaDocument['characters'], $jsonDocument['characters']);
        $this->assertSame([], $luaDocument['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['professions'][2]['recipes'][0]['outputs']);
        $this->assertArrayHasKey('settings', $luaDocument);
    }

    public function test_parses_literal_decimal_hex_and_raw_unit_separator_bytes(): void
    {
        $lua = file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.lua');
        $lua = str_replace('retail\\031us', 'retail\\x1fus', $lua);
        $lua = str_replace('Example Realm PvE\\031Examplewarlock', "Example Realm PvE\x1FExamplewarlock", $lua);

        $document = (new FlynnDumpParser)->parseLua($lua);
        $settings = $document['settings'];

        $this->assertArrayHasKey("retail\x1Fus\x1FExample Realm PvE\x1FExamplewarlock", $settings['tracked']);
        $this->assertArrayHasKey("retail\x1Fus\x1FExample Realm PvE\x1FExamplewarlock", $settings['capturedProfessions']);
    }

    public function test_rejects_malformed_or_truncated_hex_string_escapes(): void
    {
        foreach (['\\x', '\\x1', '\\xGG'] as $escape) {
            try {
                (new FlynnDumpParser)->parseLua(str_replace('Examplewarlock', 'Example'.$escape.'warlock', file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.lua')));
                $this->fail('Malformed hexadecimal escape should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('invalid_saved_variables', $exception->getMessage());
            }
        }
    }

    public function test_sorts_out_of_order_integer_indices_and_keeps_numeric_string_keys_named(): void
    {
        $lua = str_replace('["settings"] = {', '["settings"] = { ["order"] = { [2] = "b", [1] = "a" }, ["numeric"] = { ["1"] = "named" },', file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.lua'));

        $document = (new FlynnDumpParser)->parseLua($lua);

        $this->assertSame(['a', 'b'], $document['settings']['order']);
        $this->assertSame('named', $document['settings']['numeric'][1]);
    }

    public function test_skips_empty_namespace_branches_but_rejects_a_document_without_characters(): void
    {
        $document = $this->realisticDocument();
        $document['characters']['classic'] = [];
        $document['characters']['retail']['eu'] = [];
        $document['characters']['retail']['us']['Empty Realm'] = [];

        $parsed = (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));

        $this->assertArrayNotHasKey('classic', $parsed['characters']);
        $this->assertArrayNotHasKey('eu', $parsed['characters']['retail']);
        $this->assertArrayNotHasKey('Empty Realm', $parsed['characters']['retail']['us']);

        try {
            (new FlynnDumpParser)->parseJson(json_encode(['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Empty Realm' => []]]]], JSON_THROW_ON_ERROR));
            $this->fail('An all-empty namespace must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('No character records', $exception->getMessage());
        }
    }

    public function test_counts_character_leaves_across_all_namespaces(): void
    {
        $document = ['schemaVersion' => 1, 'characters' => []];
        for ($index = 1; $index <= 100; $index++) {
            $name = 'Examplewarlock'.$index;
            $flavor = $index <= 50 ? 'retail' : 'classic';
            $document['characters'][$flavor]['us']['Example Realm PvE'][$name] = $this->characterRecord($name, 'Player-0000-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT), $flavor);
        }
        (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));

        $name = 'Examplewarlock101';
        $document['characters']['forever']['us']['Example Realm PvE'][$name] = $this->characterRecord($name, 'Player-0000-00000101', 'forever');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100 character records total');
        (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function test_validates_optional_per_character_schema_versions(): void
    {
        $parser = new FlynnDumpParser;
        $document = $this->realisticDocument();
        $parser->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
        unset($document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['schemaVersion']);
        $parser->parseJson(json_encode($document, JSON_THROW_ON_ERROR));

        $document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['schemaVersion'] = '1';
        try {
            $parser->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
            $this->fail('Malformed per-character schema version should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('invalid_schema_version', $exception->getMessage());
        }

        $document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['schemaVersion'] = 2;
        try {
            $parser->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
            $this->fail('Mismatched per-character schema version should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('unsupported_schema_version', $exception->getMessage());
        }
    }

    public function test_deduplicates_slot_options_after_enforcing_raw_count_cap(): void
    {
        $document = $this->realisticDocument();
        $options = &$document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['professions'][1]['recipes'][0]['reagentSlots'][1]['options'];
        $options[] = ['itemID' => 194123];
        $parsed = (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
        $parsedOptions = $parsed['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['professions'][1]['recipes'][0]['reagentSlots'][1]['options'];
        $this->assertSame([['itemID' => 194123], ['itemID' => 194124]], $parsedOptions);

        $document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['professions'][1]['recipes'][0]['reagentSlots'][1]['options'] = array_fill(0, 101, ['itemID' => 194123]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100 records');
        (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function test_json_number_parser_does_not_skip_malformed_prefixes_to_find_a_number(): void
    {
        try {
            (new FlynnJsonParser('{"value":x1}'))->parse();
            $this->fail('Malformed JSON value must not be interpreted as the number that follows it.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid JSON value', $exception->getMessage());
        }
    }

    public function test_current_skill_may_not_exceed_max_skill(): void
    {
        $document = $this->realisticDocument();
        $document['characters']['retail']['us']['Example Realm PvE']['Examplewarlock']['professions'][0]['currentSkill'] = 101;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currentSkill may not exceed maxSkill');
        (new FlynnDumpParser)->parseJson(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function test_export_preserves_schema_and_domain_validation_error_codes(): void
    {
        $parser = new FlynnDumpParser;
        $document = $this->realisticDocument();
        $document['schemaVersion'] = 2;
        $this->assertExportErrorCode($parser, $document, 'unsupported_schema_version');
        unset($document['schemaVersion']);
        $this->assertExportErrorCode($parser, $document, 'invalid_schema_version');
        $document = ['schemaVersion' => 1, 'characters' => []];
        $this->assertExportErrorCode($parser, $document, 'invalid_payload');
    }

    private function realisticDocument(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/Flynn.realistic.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function characterRecord(string $name, string $guid, string $flavor = 'retail'): array
    {
        return [
            'schemaVersion' => 1,
            'gameFlavor' => $flavor,
            'region' => 'us',
            'realm' => 'Example Realm PvE',
            'characterName' => $name,
            'guid' => $guid,
            'faction' => 'alliance',
            'class' => 'WARLOCK',
            'capturedAt' => 1794528000,
            'professions' => [],
        ];
    }

    private function assertExportErrorCode(FlynnDumpParser $parser, array $document, string $code): void
    {
        try {
            $parser->parseExport('FLYNN1:'.base64_encode(gzdeflate(json_encode($document, JSON_THROW_ON_ERROR))));
            $this->fail("Expected {$code} export validation error.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($code, $exception->getMessage());
        }
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
