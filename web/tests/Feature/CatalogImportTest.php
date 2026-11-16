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
        $retailPath = $directory.'/retail-catalog.json';
        $foreverPath = $directory.'/forever-catalog.json';
        file_put_contents($retailPath, json_encode([
            'professions' => [['skillLineID' => 164, 'name' => 'Blacksmithing', 'icon' => 'icons/blacksmithing']],
            'spells' => [['spellID' => 10001, 'name' => 'Copper Bracers', 'description' => 'A sturdy pair of bracers.', 'quality' => 3, 'icon' => 'spells/bracers']],
            'items' => [['itemID' => 9001, 'name' => 'Copper Bar', 'quality' => 2, 'icon' => 'items/copper-bar']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($foreverPath, json_encode([
            'professions' => [['skillLineID' => 90000, 'name' => 'Camelot Smithing', 'icon' => 'icons/camelot-smithing']],
            'spells' => [['spellID' => 20001, 'name' => 'Camelot Plate Greaves', 'description' => 'A test-only Forever catalog entry.', 'quality' => 1, 'icon' => 'spells/camelot-greaves']],
            'items' => [['itemID' => 29001, 'name' => 'Camelot Iron Ingot', 'quality' => 1, 'icon' => 'items/camelot-ingot']],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->assertSame(0, Artisan::call('flynn:catalog-import', ['file' => $retailPath, '--game-flavor' => 'retail']));
            $this->assertDatabaseHas('catalog_spells', ['game_flavor' => 'retail', 'spell_id' => 10001, 'name' => 'Copper Bracers', 'quality' => 3]);
            $this->assertDatabaseHas('catalog_items', ['game_flavor' => 'retail', 'item_id' => 9001, 'name' => 'Copper Bar']);
            $this->assertSame(0, Artisan::call('flynn:catalog-import', ['file' => $retailPath, '--game-flavor' => 'retail']));
            $this->assertDatabaseCount('catalog_import_runs', 1);
            $this->assertSame(0, Artisan::call('flynn:catalog-import', ['file' => $foreverPath, '--game-flavor' => 'forever']));
            $this->assertDatabaseHas('catalog_professions', ['game_flavor' => 'forever', 'skill_line_id' => 90000, 'name' => 'Camelot Smithing']);
            $this->assertDatabaseHas('catalog_spells', ['game_flavor' => 'forever', 'spell_id' => 20001, 'name' => 'Camelot Plate Greaves']);
            $this->assertDatabaseHas('catalog_items', ['game_flavor' => 'forever', 'item_id' => 29001, 'name' => 'Camelot Iron Ingot']);
            $this->assertDatabaseMissing('catalog_professions', ['game_flavor' => 'retail', 'skill_line_id' => 90000]);
            $this->assertDatabaseMissing('catalog_professions', ['game_flavor' => 'forever', 'skill_line_id' => 164]);
            $this->assertDatabaseMissing('catalog_spells', ['game_flavor' => 'retail', 'spell_id' => 20001]);
            $this->assertDatabaseMissing('catalog_spells', ['game_flavor' => 'forever', 'spell_id' => 10001]);
            $this->assertDatabaseCount('catalog_import_runs', 2);
        } finally {
            @unlink($retailPath);
            @unlink($foreverPath);
            @rmdir($directory);
        }
    }
}
