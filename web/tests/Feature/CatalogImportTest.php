<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CatalogImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_catalog_import_upserts_flavor_scoped_metadata_and_skips_identical_snapshots(): void
    {
        $directory = storage_path('app/private/test-fixtures');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/catalog.json';
        file_put_contents($path, json_encode([
            'professions' => [['skillLineID' => 164, 'name' => 'Blacksmithing', 'icon' => 'icons/blacksmithing']],
            'spells' => [['spellID' => 10001, 'name' => 'Copper Bracers', 'description' => 'A sturdy pair of bracers.', 'quality' => 3, 'icon' => 'spells/bracers']],
            'items' => [['itemID' => 9001, 'name' => 'Copper Bar', 'quality' => 2, 'icon' => 'items/copper-bar']],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->assertSame(0, Artisan::call('flynn:catalog-import', ['file' => $path, '--game-flavor' => 'retail']));
            $this->assertDatabaseHas('catalog_spells', ['game_flavor' => 'retail', 'spell_id' => 10001, 'name' => 'Copper Bracers', 'quality' => 3]);
            $this->assertDatabaseHas('catalog_items', ['game_flavor' => 'retail', 'item_id' => 9001, 'name' => 'Copper Bar']);
            $this->assertSame(0, Artisan::call('flynn:catalog-import', ['file' => $path, '--game-flavor' => 'retail']));
            $this->assertDatabaseCount('catalog_import_runs', 1);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
