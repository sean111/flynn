<?php

namespace Tests\Feature;

use App\Jobs\IngestDump;
use App\Models\User;
use App\Support\FlynnDumpParser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DumpIngestionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_forever_dump_ingestion_keeps_the_forever_flavor_namespace(): void
    {
        Storage::fake('local');
        $uploader = User::factory()->create();
        $document = ['schemaVersion' => 1, 'characters' => ['forever' => ['us' => ['Camelot' => ['Anvil' => [
            'gameFlavor' => 'forever', 'region' => 'us', 'realm' => 'Camelot', 'characterName' => 'Anvil',
            'guid' => 'Player-18-ANVIL', 'faction' => 'alliance', 'class' => 'WARRIOR', 'capturedAt' => 1794528000,
            'professions' => [['skillLineID' => 164, 'currentSkill' => 50, 'maxSkill' => 100, 'recipes' => []]],
        ]]]]]];
        $dumpId = (string) Str::uuid();
        $path = 'private/dumps/'.$dumpId.'.json';
        Storage::disk('local')->put($path, json_encode($document, JSON_THROW_ON_ERROR));
        DB::table('dumps')->insert(['id' => $dumpId, 'uploader_id' => $uploader->id, 'staging_path' => $path, 'checksum' => hash('sha256', Storage::disk('local')->get($path)), 'created_at' => now(), 'updated_at' => now()]);

        (new IngestDump($dumpId, $path, 'structuredJson'))->handle(app(FlynnDumpParser::class));

        $this->assertDatabaseHas('characters', ['game_flavor' => 'forever', 'region' => 'us', 'realm' => 'Camelot', 'character_name' => 'Anvil']);
        $this->assertDatabaseMissing('characters', ['game_flavor' => 'retail', 'character_name' => 'Anvil']);
    }

    public function test_structured_dump_is_staged_then_upserted_with_claim_grants_and_ownership_conflicts(): void
    {
        Storage::fake('local');
        Queue::fake([IngestDump::class]);
        $uploader = User::factory()->create();
        $claimedOwner = User::factory()->create();
        $this->createClaimedCharacter($claimedOwner);
        $staleCharacterId = $this->createNewerCharacter($uploader);
        Sanctum::actingAs($uploader, ['*']);
        $document = ['schemaVersion' => 1, 'characters' => [
            'retail' => ['us' => ['Area 52' => [
                'Newmage' => $this->record('Newmage', 'Player-1234-NEW'),
                'Alreadyclaimed' => $this->record('Alreadyclaimed', 'Player-1234-OLD'),
                'Stalemage' => $this->record('Stalemage', 'Player-1234-STALE'),
            ]]],
        ]];

        $response = $this->postJson('/api/v1/dumps', ['format' => 'structuredJson', 'payload' => $document]);

        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(IngestDump::class);
        $dump = DB::table('dumps')->where('id', $response->json('data.dumpId'))->first();
        Storage::disk('local')->assertExists($dump->staging_path);
        (new IngestDump($dump->id, $dump->staging_path, 'structuredJson'))->handle(app(FlynnDumpParser::class));

        $this->assertDatabaseHas('dumps', ['id' => $dump->id, 'status' => 'completed', 'characters_seen' => 3, 'characters_updated' => 1]);
        $newCharacter = DB::table('characters')->where('character_name', 'Newmage')->first();
        $this->assertNotNull($newCharacter);
        $this->assertNull($newCharacter->owner_id);
        $this->getJson('/api/v1/characters')->assertOk()->assertJsonFragment(['id' => $newCharacter->id, 'claimStatus' => 'claimable']);
        $this->assertDatabaseHas('character_import_authorizations', ['character_id' => $newCharacter->id, 'uploader_id' => $uploader->id]);
        $this->assertDatabaseHas('dump_character_results', ['dump_id' => $dump->id, 'status' => 'ownership_conflict', 'code' => 'character_owned']);
        $this->assertDatabaseHas('dump_character_results', ['dump_id' => $dump->id, 'status' => 'stale_snapshot', 'code' => 'stale_snapshot']);
        $this->assertDatabaseHas('characters', ['character_name' => 'Alreadyclaimed', 'guid' => 'Player-1234-ORIGINAL', 'owner_id' => $claimedOwner->id]);
        $this->assertDatabaseHas('characters', ['id' => $staleCharacterId, 'guid' => 'Player-1234-CURRENT', 'captured_at' => 1794529000]);
    }

    public function test_reimport_keeps_cart_recipe_ids_stable_and_prunes_cart_lines_for_unlearned_recipes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $guildId = (string) Str::uuid();
        $characterId = (string) Str::uuid();
        $professionId = (string) Str::uuid();
        $recipeId = (string) Str::uuid();
        $cartId = (string) Str::uuid();
        $cartItemId = (string) Str::uuid();
        DB::table('guilds')->insert(['id' => $guildId, 'name' => 'Reimport guild', 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'owner_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('guild_memberships')->insert(['id' => (string) Str::uuid(), 'guild_id' => $guildId, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('characters')->insert(['id' => $characterId, 'owner_id' => $owner->id, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'character_name' => 'Smithy', 'guid' => 'Player-1234-SMITHY', 'faction' => 'alliance', 'class' => 'WARRIOR', 'captured_at' => 1794528000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('character_guild')->insert(['character_id' => $characterId, 'guild_id' => $guildId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('character_professions')->insert(['id' => $professionId, 'character_id' => $characterId, 'skill_line_id' => 164, 'current_skill' => 100, 'max_skill' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recipes')->insert(['id' => $recipeId, 'game_flavor' => 'retail', 'profession_id' => $professionId, 'spell_id' => 10001, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('carts')->insert(['id' => $cartId, 'guild_id' => $guildId, 'user_id' => $owner->id, 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cart_items')->insert(['id' => $cartItemId, 'cart_id' => $cartId, 'recipe_id' => $recipeId, 'crafter_character_id' => $characterId, 'quantity' => 1, 'materials_provided' => 'requester', 'created_at' => now(), 'updated_at' => now()]);

        $job = function (array $recipes) use ($owner): void {
            $document = ['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Area 52' => ['Smithy' => [
                'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => 'Smithy', 'guid' => 'Player-1234-SMITHY',
                'faction' => 'alliance', 'class' => 'WARRIOR', 'capturedAt' => 1794528000,
                'professions' => [['skillLineID' => 164, 'currentSkill' => 100, 'maxSkill' => 100, 'recipes' => $recipes]],
            ]]]]]];
            $dumpId = (string) Str::uuid();
            $path = 'private/dumps/'.$dumpId.'.json';
            Storage::disk('local')->put($path, json_encode($document, JSON_THROW_ON_ERROR));
            DB::table('dumps')->insert(['id' => $dumpId, 'uploader_id' => $owner->id, 'staging_path' => $path, 'checksum' => hash('sha256', Storage::disk('local')->get($path)), 'created_at' => now(), 'updated_at' => now()]);
            (new IngestDump($dumpId, $path, 'structuredJson'))->handle(app(FlynnDumpParser::class));
        };

        $job([['spellID' => 10001, 'outputs' => [['itemID' => 9002, 'quantity' => 1]], 'reagents' => [['itemID' => 9001, 'quantity' => 3]]]]);
        $this->assertDatabaseHas('recipes', ['id' => $recipeId, 'profession_id' => $professionId]);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItemId, 'recipe_id' => $recipeId]);

        // A subsequent snapshot that no longer knows the spell prunes it, and
        // the FK cascade removes the now-invalid cart line rather than a live one.
        $job([]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipeId]);
        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }

    private function createClaimedCharacter(User $owner): void
    {
        DB::table('characters')->insert([
            'id' => (string) Str::uuid(), 'owner_id' => $owner->id, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'character_name' => 'Alreadyclaimed', 'guid' => 'Player-1234-ORIGINAL', 'faction' => 'horde', 'class' => 'MAGE',
            'captured_at' => 1794528000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function record(string $name, string $guid): array
    {
        return [
            'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => $name,
            'guid' => $guid, 'faction' => 'horde', 'class' => 'MAGE', 'capturedAt' => 1794528000, 'professions' => [],
        ];
    }

    private function createNewerCharacter(User $owner): string
    {
        $id = (string) Str::uuid();
        DB::table('characters')->insert([
            'id' => $id, 'owner_id' => $owner->id, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'character_name' => 'Stalemage', 'guid' => 'Player-1234-CURRENT', 'faction' => 'horde', 'class' => 'MAGE',
            'captured_at' => 1794529000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
