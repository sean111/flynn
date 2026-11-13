<?php
declare(strict_types=1);

$encoded = file_get_contents($argv[1] ?? '');
if (!is_string($encoded) || !str_starts_with($encoded, 'FLYNN1:')) {
    fwrite(STDERR, "missing export prefix\n");
    exit(1);
}
if (strlen($encoded) > 4 * 1024 * 1024) {
    fwrite(STDERR, "export exceeds the API cap\n");
    exit(1);
}
$deflate = base64_decode(substr($encoded, 7), true);
if (!is_string($deflate)) {
    fwrite(STDERR, "invalid RFC4648 Base64\n");
    exit(1);
}
$json = gzinflate($deflate, 20 * 1024 * 1024);
if (!is_string($json)) {
    fwrite(STDERR, "invalid or oversized raw DEFLATE document\n");
    exit(1);
}
$document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
if (($document['schemaVersion'] ?? null) !== 1 || count($document['characters'] ?? []) !== 2) {
    fwrite(STDERR, "schema or character count mismatch\n");
    exit(1);
}
if (!isset($document['characters']['retail']['us']['Area 52']['Mérida']) || !isset($document['characters']['classic_era']['eu']['Auberdine']['Étoile'])) {
    fwrite(STDERR, "UTF-8 character namespace mismatch\n");
    exit(1);
}
if ($document['characters']['retail']['us']['Area 52']['Mérida']['professions'] !== [] ||
    $document['characters']['classic_era']['eu']['Auberdine']['Étoile']['professions'][0]['recipes'][0]['outputs'] !== []) {
    fwrite(STDERR, "empty arrays were not preserved\n");
    exit(1);
}
echo "PHP gzinflate/JSON export compatibility passed\n";
