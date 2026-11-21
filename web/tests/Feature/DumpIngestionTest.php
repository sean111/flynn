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

    public function test_login_session_authenticates_me_and_export_string_upload_and_status_poll_on_same_host(): void
    {
        Storage::fake('local');
        Queue::fake([IngestDump::class]);
        $user = User::factory()->create(['email' => 'session-export@example.com', 'password' => 'Correct-Password-892']);
        $host = 'flynn.test';
        $origin = 'https://'.$host;

        $loginResponse = $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->postJson($origin.'/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-Password-892'])
            ->assertOk();

        $this->withCookie(config('session.cookie'), $loginResponse->getCookie(config('session.cookie'))->getValue())
            ->withCredentials();
        $this->app->forgetInstance('auth');
        $this->app['session']->forgetDrivers();

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->getJson($origin.'/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
        $this->assertTrue($this->app['request']->attributes->get('sanctum', false));

        $this->app['session']->forgetDrivers();
        $this->app['auth']->forgetGuards();

        $response = $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->postJson($origin.'/api/v1/dumps', [
                'format' => 'exportString',
                'payload' => $this->validExportString(),
            ]);

        $this->assertTrue($this->app['request']->attributes->get('sanctum', false));
        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        $dumpId = $response->json('data.dumpId');
        $this->assertDatabaseHas('dumps', ['id' => $dumpId, 'uploader_id' => $user->id, 'status' => 'queued']);
        Queue::assertPushed(IngestDump::class, fn (IngestDump $job): bool => $job->format === 'exportString');

        $this->app['session']->forgetDrivers();
        $this->app['auth']->forgetGuards();

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->getJson($origin.'/api/v1/dumps/'.$dumpId)
            ->assertOk()
            ->assertJsonPath('data.dumpId', $dumpId)
            ->assertJsonPath('data.status', 'queued');
        $this->assertTrue($this->app['request']->attributes->get('sanctum', false));
    }

    public function test_guest_cannot_upload_export_string(): void
    {
        $this->postJson('/api/v1/dumps', [
            'format' => 'exportString',
            'payload' => $this->validExportString(),
        ])->assertUnauthorized()->assertJsonPath('error.message', 'Authentication is required.');
    }

    public function test_unlisted_host_with_matching_origin_is_not_stateful_and_cannot_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'attacker-origin@example.com', 'password' => 'Correct-Password-892']);
        $host = 'flynn.test';
        $origin = 'https://'.$host;

        $loginResponse = $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->postJson($origin.'/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-Password-892'])
            ->assertOk();

        $this->withCookie(config('session.cookie'), $loginResponse->getCookie(config('session.cookie'))->getValue())
            ->withCredentials();
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->getJson($origin.'/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
        $this->assertTrue($this->app['request']->attributes->get('sanctum', false));

        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();

        $unlistedHost = 'attacker-controlled.test';

        $response = $this->withServerVariables(['HTTP_HOST' => $unlistedHost])
            ->withHeader('Origin', 'https://'.$unlistedHost)
            ->getJson('https://'.$unlistedHost.'/api/v1/me');

        $response->assertUnauthorized();

        $this->assertFalse($this->app['request']->attributes->get('sanctum', false));
    }

    public function test_authenticated_session_receives_export_validation_error_for_malformed_string(): void
    {
        $user = User::factory()->create(['email' => 'invalid-export@example.com', 'password' => 'Correct-Password-892']);
        $host = 'flynn.test';
        $origin = 'https://'.$host;

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->postJson($origin.'/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-Password-892'])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withServerVariables(['HTTP_HOST' => $host])
            ->withHeader('Origin', $origin)
            ->postJson($origin.'/api/v1/dumps', ['format' => 'exportString', 'payload' => 'not-a-valid-export'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_export_string');

        $this->assertTrue($this->app['request']->attributes->get('sanctum', false));
        $this->assertDatabaseCount('dumps', 0);
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

    private function validExportString(): string
    {
        $document = ['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Area 52' => ['Examplemage' => [
            'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => 'Examplemage',
            'guid' => 'Player-3676-0ABCDEF0', 'faction' => 'horde', 'class' => 'MAGE', 'capturedAt' => 1794528000, 'professions' => [],
        ]]]]]];

        return 'FLYNN1:'.base64_encode(gzdeflate(json_encode($document, JSON_THROW_ON_ERROR)));
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
