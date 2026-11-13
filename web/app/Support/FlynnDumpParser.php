<?php

namespace App\Support;

use InvalidArgumentException;

/** Parses Flynn exports as data. Uploaded Lua is never evaluated. */
final class FlynnDumpParser
{
    private const MAX_LUA_BYTES = 10 * 1024 * 1024;

    private const MAX_JSON_BYTES = 10 * 1024 * 1024;

    private const MAX_EXPORT_BYTES = 4 * 1024 * 1024;

    private const MAX_INFLATED_BYTES = 20 * 1024 * 1024;

    private const MAX_ENTRIES = 500_000;

    private const MAX_DEPTH = 32;

    public function parseLua(string $source): array
    {
        if (strlen($source) > self::MAX_LUA_BYTES) {
            throw new InvalidArgumentException('payload_too_large: Flynn.lua files are limited to 10 MiB.');
        }
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('invalid_saved_variables: Flynn.lua must be valid UTF-8.');
        }

        $parser = new FlynnLiteralParser($source);
        $value = $parser->parseSavedVariables();
        $this->validateDocument($value);

        return $value;
    }

    public function parseJson(string $source, int $maxBytes = self::MAX_JSON_BYTES): array
    {
        if (strlen($source) > $maxBytes) {
            $limit = $maxBytes > self::MAX_JSON_BYTES ? '20 MiB' : '10 MiB';
            throw new InvalidArgumentException('payload_too_large: JSON documents are limited to '.$limit.'.');
        }

        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('invalid_payload: The document must be valid UTF-8.');
        }

        $parser = new FlynnJsonParser($source);
        $value = $parser->parse();
        $this->validateDocument($value);

        return $value;
    }

    public function decodeJsonValue(string $source): mixed
    {
        if (strlen($source) > self::MAX_JSON_BYTES + 1024 * 1024) {
            throw new InvalidArgumentException('payload_too_large: Dump API request is larger than the allowed structured document envelope.');
        }
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('invalid_payload: The document must be valid UTF-8.');
        }

        return (new FlynnJsonParser($source))->parse();
    }

    public function parseExport(string $export): array
    {
        if (strlen($export) > self::MAX_EXPORT_BYTES) {
            throw new InvalidArgumentException('payload_too_large: FLYNN1 exports are limited to 4 MiB.');
        }
        if (! str_starts_with($export, 'FLYNN1:')) {
            throw new InvalidArgumentException('invalid_export_string: Export must start with FLYNN1:.');
        }

        $encoded = substr($export, 7);
        if (! preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $encoded)) {
            throw new InvalidArgumentException('invalid_export_string: Export contains invalid Base64 data.');
        }
        $compressed = base64_decode($encoded, true);
        if ($compressed === false || $compressed === '') {
            throw new InvalidArgumentException('invalid_export_string: Export contains invalid Base64 data.');
        }

        $json = $this->inflateBounded($compressed);

        try {
            return $this->parseJson($json, self::MAX_INFLATED_BYTES);
        } catch (InvalidArgumentException $exception) {
            if (str_starts_with($exception->getMessage(), 'payload_too_large:')) {
                throw $exception;
            }
            throw new InvalidArgumentException('invalid_export_string: Export compression or JSON data is invalid.');
        }
    }

    private function inflateBounded(string $compressed): string
    {
        foreach ([ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE] as $encoding) {
            $context = @inflate_init($encoding);
            if ($context === false) {
                continue;
            }
            $output = '';
            $length = strlen($compressed);
            for ($offset = 0; $offset < $length; $offset += 16_384) {
                $chunk = substr($compressed, $offset, 16_384);
                $mode = $offset + strlen($chunk) >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
                $part = @inflate_add($context, $chunk, $mode);
                if ($part === false) {
                    break;
                }
                $output .= $part;
                if (strlen($output) > self::MAX_INFLATED_BYTES) {
                    throw new InvalidArgumentException('payload_too_large: Decompressed FLYNN1 JSON is limited to 20 MiB.');
                }
            }
            if (inflate_get_status($context) === ZLIB_STREAM_END && inflate_get_read_len($context) === $length && preg_match('//u', $output) === 1) {
                return $output;
            }
        }

        throw new InvalidArgumentException('invalid_export_string: Export has a corrupt or truncated Deflate stream.');
    }

    private function validateDocument(mixed $document): void
    {
        if (! is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('invalid_payload: Flynn data must be an object.');
        }
        $version = $document['schemaVersion'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new InvalidArgumentException('invalid_schema_version: A numeric schemaVersion is required. Update or re-export with the Flynn addon.');
        }
        if ($version !== 1) {
            throw new InvalidArgumentException("unsupported_schema_version: This Flynn data uses schema version {$version}, but this server supports version 1. Update the Flynn addon or use a server release that supports this data version, then export again.");
        }
        $characters = $document['characters'] ?? null;
        if (! is_array($characters) || $characters === [] || count($characters) > 100) {
            throw new InvalidArgumentException('invalid_payload: characters must contain between 1 and 100 character records.');
        }

        $recipesTotal = 0;
        foreach ($characters as $flavor => $regions) {
            $this->assertOneOf($flavor, ['retail', 'classic_era', 'classic'], 'characters.gameFlavor');
            if (! is_array($regions) || $regions === []) {
                $this->invalid('characters: flavor entries must contain region maps.');
            }
            foreach ($regions as $region => $realms) {
                $this->assertOneOf($region, ['us', 'eu', 'kr', 'tw', 'cn'], 'characters.region');
                if (! is_array($realms) || $realms === []) {
                    $this->invalid('characters: region entries must contain realm maps.');
                }
                foreach ($realms as $realm => $records) {
                    $this->assertName($realm, 128, 'realm');
                    if (! is_array($records) || $records === []) {
                        $this->invalid('characters: realm entries must contain character maps.');
                    }
                    foreach ($records as $name => $character) {
                        $this->assertName($name, 128, 'characterName');
                        if (! is_array($character) || array_is_list($character)) {
                            $this->invalid("characters.{$name}: expected an object.");
                        }
                        foreach (['gameFlavor' => $flavor, 'region' => $region, 'realm' => $realm, 'characterName' => $name] as $field => $expected) {
                            if (($character[$field] ?? null) !== $expected) {
                                $this->invalid("characters.{$name}.{$field}: must match its namespace key.");
                            }
                        }
                        $this->assertName($character['guid'] ?? null, 128, 'guid');
                        $this->assertOneOf($character['faction'] ?? null, ['alliance', 'horde', 'neutral', 'unknown'], 'faction');
                        if (! is_string($character['class'] ?? null) || ! preg_match('/^(?:[A-Z][A-Z_]{1,23}|UNKNOWN)$/D', $character['class'])) {
                            $this->invalid('class must be a WoW class token or UNKNOWN.');
                        }
                        $this->integer($character['capturedAt'] ?? null, 1, 9_007_199_254_740_991, 'capturedAt');
                        $professions = $character['professions'] ?? null;
                        if (! is_array($professions) || ! array_is_list($professions) || count($professions) > 20) {
                            $this->invalid('professions must be an array of at most 20 records.');
                        }
                        $seenProfessions = [];
                        foreach ($professions as $profession) {
                            if (! is_array($profession)) {
                                $this->invalid('profession must be an object.');
                            }
                            if (isset($profession['specialization']) && $profession['specialization'] !== null) {
                                $specialization = $profession['specialization'];
                                if (! is_array($specialization)) {
                                    $this->invalid('specialization must be an object or null.');
                                }
                                if (array_key_exists('id', $specialization)) {
                                    $this->integer($specialization['id'], 1, 9_007_199_254_740_991, 'specialization.id');
                                }
                                if (isset($specialization['name'])) {
                                    $this->assertName($specialization['name'], 128, 'specialization.name');
                                }
                            }
                            $skill = $this->integer($profession['skillLineID'] ?? null, 1, 9_007_199_254_740_991, 'skillLineID');
                            if (isset($seenProfessions[$skill])) {
                                $this->invalid('duplicate skillLineID in character.');
                            }
                            $seenProfessions[$skill] = true;
                            $current = $this->integer($profession['currentSkill'] ?? null, 0, 9_007_199_254_740_991, 'currentSkill');
                            $max = $this->integer($profession['maxSkill'] ?? null, 0, 9_007_199_254_740_991, 'maxSkill');
                            if ($current > $max) {
                                $this->invalid('currentSkill may not exceed maxSkill.');
                            }
                            $recipes = $profession['recipes'] ?? null;
                            if (! is_array($recipes) || ! array_is_list($recipes) || count($recipes) > 5000) {
                                $this->invalid('recipes must be an array of at most 5,000 records.');
                            }
                            $recipesTotal += count($recipes);
                            if ($recipesTotal > 50_000) {
                                $this->invalid('dump contains more than 50,000 recipes.');
                            }
                            $seenRecipes = [];
                            foreach ($recipes as $recipe) {
                                if (! is_array($recipe)) {
                                    $this->invalid('recipe must be an object.');
                                }
                                $spell = $this->integer($recipe['spellID'] ?? null, 1, 9_007_199_254_740_991, 'spellID');
                                if (isset($seenRecipes[$spell])) {
                                    $this->invalid('duplicate spellID in profession.');
                                }
                                $seenRecipes[$spell] = true;
                                foreach (['outputs' => 20, 'reagents' => 100] as $field => $limit) {
                                    $items = $recipe[$field] ?? null;
                                    if (! is_array($items) || ! array_is_list($items) || count($items) > $limit) {
                                        $this->invalid("recipe.{$field} must be an array of at most {$limit} records.");
                                    }
                                    foreach ($items as $item) {
                                        if (! is_array($item)) {
                                            $this->invalid("recipe.{$field} entry must be an object.");
                                        }
                                        $this->integer($item['itemID'] ?? null, 1, 9_007_199_254_740_991, 'itemID');
                                        $this->integer($item['quantity'] ?? null, 1, 1_000_000, 'quantity');
                                    }
                                }
                                if (isset($recipe['quality'])) {
                                    $quality = $recipe['quality'];
                                    if (! is_array($quality)) {
                                        $this->invalid('quality must be an object.');
                                    }
                                    $maxRank = $this->integer($quality['maxRank'] ?? null, 1, 9_007_199_254_740_991, 'quality.maxRank');
                                    $ranks = [];
                                    $qualityOutputs = $quality['outputs'] ?? [];
                                    if (! is_array($qualityOutputs) || ! array_is_list($qualityOutputs) || count($qualityOutputs) > 20) {
                                        $this->invalid('quality.outputs must be an array of at most 20 records.');
                                    }
                                    foreach ($qualityOutputs as $output) {
                                        if (! is_array($output)) {
                                            $this->invalid('quality output must be an object.');
                                        }
                                        $rank = $this->integer($output['rank'] ?? null, 1, 9_007_199_254_740_991, 'quality.outputs.rank');
                                        if ($rank > $maxRank || isset($ranks[$rank])) {
                                            $this->invalid('quality output ranks must be unique and not exceed maxRank.');
                                        }
                                        $ranks[$rank] = true;
                                        $this->integer($output['itemID'] ?? null, 1, 9_007_199_254_740_991, 'itemID');
                                        $this->integer($output['quantity'] ?? null, 1, 1_000_000, 'quantity');
                                    }
                                }
                                $slots = $recipe['reagentSlots'] ?? [];
                                if (! is_array($slots) || ! array_is_list($slots) || count($slots) > 100) {
                                    $this->invalid('reagentSlots must be an array of at most 100 records.');
                                }
                                $indices = [];
                                foreach ($slots as $slot) {
                                    if (! is_array($slot)) {
                                        $this->invalid('reagent slot must be an object.');
                                    }
                                    $index = $this->integer($slot['slotIndex'] ?? null, 0, 9_007_199_254_740_991, 'slotIndex');
                                    if (isset($indices[$index])) {
                                        $this->invalid('reagent slot indices must be unique.');
                                    }
                                    $indices[$index] = true;
                                    $this->assertOneOf($slot['kind'] ?? null, ['required', 'optional', 'modifying'], 'reagentSlots.kind');
                                    $this->integer($slot['quantity'] ?? null, 1, 1_000_000, 'reagentSlots.quantity');
                                    $options = $slot['options'] ?? null;
                                    if (! is_array($options) || ! array_is_list($options) || count($options) > 100) {
                                        $this->invalid('slot options must be an array of at most 100 records.');
                                    }
                                    foreach ($options as $option) {
                                        if (! is_array($option)) {
                                            $this->invalid('reagent slot option must be an object.');
                                        }
                                        $this->integer($option['itemID'] ?? null, 1, 9_007_199_254_740_991, 'itemID');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function assertName(mixed $value, int $max, string $field): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('//u', $value) !== 1) {
            $this->invalid("{$field} must be a non-empty string of at most {$max} characters.");
        }
    }

    private function assertOneOf(mixed $value, array $allowed, string $field): void
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->invalid("{$field} is not supported.");
        }
    }

    private function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $this->invalid("{$field} must be an integer between {$min} and {$max}.");
        }

        return $value;
    }

    private function invalid(string $message): never
    {
        throw new InvalidArgumentException('invalid_payload: '.$message);
    }
}

/** Minimal bounded parser for the literal subset used by SavedVariables. */
final class FlynnLiteralParser
{
    private int $position = 0;

    private int $entries = 0;

    private int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    public function parseSavedVariables(): array
    {
        $this->space();
        if (! $this->consumeIdentifier('FlynnDB')) {
            $this->fail('Expected one FlynnDB assignment.');
        }
        $this->space();
        $this->expect('=');
        $value = $this->value(0);
        $this->space();
        if ($this->peek() === ';') {
            $this->position++;
            $this->space();
        }
        if ($this->position !== $this->length) {
            $this->fail('Unexpected syntax after FlynnDB assignment.');
        }
        if (! is_array($value)) {
            $this->fail('FlynnDB must be a table.');
        }

        return $value;
    }

    private function value(int $depth): mixed
    {
        if ($depth > 32) {
            $this->fail('Maximum nesting depth exceeded.');
        }
        $this->space();
        $char = $this->peek();
        if ($char === '{') {
            return $this->table($depth + 1);
        }
        if ($char === '"' || $char === "'") {
            return $this->string();
        }
        if ($this->consumeIdentifier('true')) {
            return true;
        }
        if ($this->consumeIdentifier('false')) {
            return false;
        }
        $remaining = substr($this->source, $this->position);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $remaining, $match)) {
            $this->position += strlen($match[0]);
            $number = strpbrk($match[0], '.eE') === false ? filter_var($match[0], FILTER_VALIDATE_INT) : (float) $match[0];
            if ($number === false || (is_float($number) && ! is_finite($number))) {
                $this->fail('Invalid or non-finite numeric literal.');
            }

            return $number;
        }
        $this->fail('Only strings, booleans, finite numbers, and tables are allowed.');
    }

    private function table(int $depth): array
    {
        $this->expect('{');
        $entries = [];
        $nextIndex = 1;
        while (true) {
            $this->space();
            if ($this->peek() === '}') {
                $this->position++;
                break;
            }
            if ($this->position >= $this->length) {
                $this->fail('Unterminated table.');
            }
            if (++$this->entries > 500_000) {
                $this->fail('Maximum entry count exceeded.');
            }
            $key = null;
            $valueStart = $this->position;
            if ($this->peek() === '[') {
                $this->position++;
                $this->space();
                $key = $this->value($depth);
                if (! is_string($key) && (! is_int($key) || $key < 1)) {
                    $this->fail('Table keys must be strings or positive integer indices.');
                }
                $this->space();
                $this->expect(']');
                $this->space();
                $this->expect('=');
                $value = $this->value($depth);
            } else {
                $value = $this->value($depth);
                $key = $nextIndex++;
            }
            if (array_key_exists($key, $entries)) {
                $this->fail('Duplicate table key.');
            }
            $entries[$key] = $value;
            $this->space();
            if ($this->peek() === ',') {
                $this->position++;

                continue;
            }
            if ($this->peek() === ';') {
                $this->position++;

                continue;
            }
            if ($this->peek() !== '}') {
                $this->fail('Expected comma or table terminator.');
            }
        }
        $keys = array_keys($entries);
        $integerKeys = array_filter($keys, 'is_int');
        $stringKeys = array_filter($keys, 'is_string');
        if ($integerKeys !== [] && $stringKeys !== []) {
            $this->fail('Tables may not mix indexed and named keys.');
        }
        if ($integerKeys !== []) {
            sort($integerKeys);
            if ($integerKeys !== range(1, count($integerKeys))) {
                $this->fail('Integer-indexed arrays must be contiguous and start at 1.');
            }

            return array_values($entries);
        }

        return $entries;
    }

    private function string(): string
    {
        $quote = $this->peek();
        $this->position++;
        $result = '';
        while ($this->position < $this->length) {
            $char = $this->source[$this->position++];
            if ($char === $quote) {
                if (preg_match('//u', $result) !== 1) {
                    $this->fail('Lua string is not valid UTF-8.');
                }

                return $result;
            }
            if ($char === "\n" || $char === "\r") {
                $this->fail('Unescaped newline in string.');
            }
            if ($char !== '\\') {
                $result .= $char;

                continue;
            }
            if ($this->position >= $this->length) {
                $this->fail('Unterminated escape sequence.');
            }
            $escape = $this->source[$this->position++];
            $simple = ['a' => "\x07", 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\x0b", '\\' => '\\', '"' => '"', "'" => "'"];
            if (isset($simple[$escape])) {
                $result .= $simple[$escape];

                continue;
            }
            if ($escape === "\n") {
                $result .= "\n";

                continue;
            }
            if (ctype_digit($escape)) {
                $digits = $escape;
                for ($i = 0; $i < 2 && ctype_digit($this->source[$this->position] ?? ''); $i++) {
                    $digits .= $this->source[$this->position++];
                }
                if ((int) $digits > 255) {
                    $this->fail('Decimal string escape is out of range.');
                }
                $result .= chr((int) $digits);

                continue;
            }
            $this->fail('Unsupported Lua string escape.');
        }
        $this->fail('Unterminated string.');
    }

    private function space(): void
    {
        while ($this->position < $this->length) {
            if (ctype_space($this->source[$this->position])) {
                $this->position++;

                continue;
            }
            if (substr($this->source, $this->position, 2) === '--') {
                $this->position += 2;
                if ($this->peek() === '[') {
                    $equals = 0;
                    $cursor = $this->position + 1;
                    while (($this->source[$cursor] ?? '') === '=') {
                        $equals++;
                        $cursor++;
                    }
                    if (($this->source[$cursor] ?? '') === '[') {
                        $this->position = $cursor + 1;
                        $closing = ']'.str_repeat('=', $equals).']';
                        $end = strpos($this->source, $closing, $this->position);
                        if ($end === false) {
                            $this->fail('Unterminated block comment.');
                        }
                        $this->position = $end + strlen($closing);

                        continue;
                    }
                }
                while ($this->position < $this->length && $this->source[$this->position] !== "\n") {
                    $this->position++;
                }

                continue;
            }
            break;
        }
    }

    private function consumeIdentifier(string $identifier): bool
    {
        if (substr($this->source, $this->position, strlen($identifier)) !== $identifier) {
            return false;
        }
        $next = $this->source[$this->position + strlen($identifier)] ?? '';
        if ($next !== '' && preg_match('/[A-Za-z0-9_]/', $next)) {
            return false;
        }
        $this->position += strlen($identifier);

        return true;
    }

    private function peek(): string
    {
        return $this->source[$this->position] ?? '';
    }

    private function expect(string $character): void
    {
        if ($this->peek() !== $character) {
            $this->fail("Expected '{$character}'.");
        } $this->position++;
    }

    private function fail(string $message): never
    {
        throw new InvalidArgumentException('invalid_saved_variables: '.$message);
    }
}

/** A bounded JSON subset parser rejects duplicate object properties before PHP can collapse them. */
final class FlynnJsonParser
{
    private int $position = 0;

    private int $entries = 0;

    private int $length;

    public function __construct(private readonly string $source)
    {
        $this->length = strlen($source);
    }

    public function parse(): mixed
    {
        $value = $this->value(0);
        $this->space();
        if ($this->position !== $this->length) {
            $this->fail('Trailing content after JSON value.');
        }

        return $value;
    }

    private function value(int $depth): mixed
    {
        if ($depth > 32) {
            $this->fail('Maximum nesting depth exceeded.');
        }
        $this->space();
        $char = $this->source[$this->position] ?? '';
        if ($char === '{') {
            return $this->object($depth + 1);
        }
        if ($char === '[') {
            return $this->array($depth + 1);
        }
        if ($char === '"') {
            return $this->string();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $token => $value) {
            if (substr($this->source, $this->position, strlen($token)) === $token) {
                $this->position += strlen($token);

                return $value;
            }
        }
        if (preg_match('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr($this->source, $this->position), $match)) {
            $this->position += strlen($match[0]);
            $number = strpbrk($match[0], '.eE') === false ? filter_var($match[0], FILTER_VALIDATE_INT) : (float) $match[0];
            if ($number === false || (is_float($number) && ! is_finite($number))) {
                $this->fail('Invalid or non-finite number.');
            }

            return $number;
        }
        $this->fail('Invalid JSON value.');
    }

    private function object(int $depth): array
    {
        $this->position++;
        $result = [];
        $this->space();
        if (($this->source[$this->position] ?? '') === '}') {
            $this->position++;

            return $result;
        }
        while (true) {
            $this->space();
            if (($this->source[$this->position] ?? '') !== '"') {
                $this->fail('Object keys must be strings.');
            }
            $key = $this->string();
            if (array_key_exists($key, $result)) {
                $this->fail('Duplicate JSON object key.');
            }
            $this->space();
            $this->expect(':');
            if (++$this->entries > 500_000) {
                $this->fail('Maximum entry count exceeded.');
            }
            $result[$key] = $this->value($depth);
            $this->space();
            if (($this->source[$this->position] ?? '') === '}') {
                $this->position++;
                break;
            }
            $this->expect(',');
        }

        return $result;
    }

    private function array(int $depth): array
    {
        $this->position++;
        $result = [];
        $this->space();
        if (($this->source[$this->position] ?? '') === ']') {
            $this->position++;

            return $result;
        }
        while (true) {
            if (++$this->entries > 500_000) {
                $this->fail('Maximum entry count exceeded.');
            }
            $result[] = $this->value($depth);
            $this->space();
            if (($this->source[$this->position] ?? '') === ']') {
                $this->position++;
                break;
            }
            $this->expect(',');
        }

        return $result;
    }

    private function string(): string
    {
        $start = $this->position++;
        $escaped = false;
        while ($this->position < $this->length) {
            $char = $this->source[$this->position++];
            if (! $escaped && $char === '"') {
                try {
                    $decoded = json_decode(substr($this->source, $start, $this->position - $start), true, 2, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->fail('Invalid JSON string escape.');
                }
                if (! is_string($decoded)) {
                    $this->fail('Invalid string.');
                }

                return $decoded;
            }
            if (! $escaped && ord($char) < 0x20) {
                $this->fail('Unescaped control character in string.');
            }
            if (! $escaped && $char === '\\') {
                $escaped = true;
            } else {
                $escaped = false;
            }
        }
        $this->fail('Unterminated string.');
    }

    private function space(): void
    {
        while (isset($this->source[$this->position]) && str_contains(" \t\r\n", $this->source[$this->position])) {
            $this->position++;
        }
    }

    private function expect(string $char): void
    {
        if (($this->source[$this->position] ?? '') !== $char) {
            $this->fail("Expected '{$char}'.");
        } $this->position++;
    }

    private function fail(string $message): never
    {
        throw new InvalidArgumentException('invalid_payload: '.$message);
    }
}
