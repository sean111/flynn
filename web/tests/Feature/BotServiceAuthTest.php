<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BotServiceAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scoped_bot_credential_and_approved_discord_actor_can_read_guild_recipes(): void
    {
        $user = User::factory()->create();
        $guildId = $this->createLinkedGuild($user, ['recipes:read']);
        $response = $this->withToken('test-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '123456789012345678',
            'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ])->getJson('/api/v1/guilds/'.$guildId.'/recipes');

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_bot_requests_require_both_discord_actor_headers(): void
    {
        $user = User::factory()->create();
        $guildId = $this->createLinkedGuild($user, ['recipes:read']);
        $response = $this->withToken('test-bot-token')->getJson('/api/v1/guilds/'.$guildId.'/recipes');

        $response->assertUnprocessable()->assertJsonPath('error.code', 'invalid_actor_context');
    }

    public function test_bot_credential_cannot_use_an_endpoint_outside_its_scope(): void
    {
        $user = User::factory()->create();
        $guildId = $this->createLinkedGuild($user, ['recipes:read']);
        $response = $this->withToken('test-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '123456789012345678',
            'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ])->getJson('/api/v1/guilds/'.$guildId.'/cart');

        $response->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_signed_bot_requests_require_timestamp_signature_and_one_time_nonce(): void
    {
        $user = User::factory()->create();
        $guildId = $this->createLinkedGuild($user, []);
        $secret = 'long-test-hmac-secret';
        config()->set('services.flynn.bot_hmac_secret', $secret);
        config()->set('services.flynn.bot_hmac_abilities', ['recipes:read']);
        Cache::flush();
        $timestamp = (string) now()->timestamp;
        $nonce = 'unique-test-nonce-123456';
        $path = '/api/v1/guilds/'.$guildId.'/recipes';
        $canonical = implode("\n", ['GET', $path, '', $timestamp, $nonce, hash('sha256', '[]'), '123456789012345678', '223456789012345678']);
        $signature = hash_hmac('sha256', $canonical, $secret);
        $headers = [
            'X-Flynn-Timestamp' => $timestamp, 'X-Flynn-Nonce' => $nonce, 'X-Flynn-Signature' => $signature,
            'X-Flynn-Discord-User-ID' => '123456789012345678', 'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ];

        $this->withHeaders($headers)->getJson($path)->assertOk();
        $this->withHeaders($headers)->getJson($path)->assertConflict()->assertJsonPath('error.code', 'request_replay');
    }

    private function createLinkedGuild(User $user, array $abilities): string
    {
        config()->set('services.flynn.bot_token', '');
        $guildId = (string) Str::uuid();
        DB::table('discord_identities')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'discord_user_id' => '123456789012345678',
            'handle' => 'test-user', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('guilds')->insert([
            'id' => $guildId, 'name' => 'Guild of Tests', 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'owner_id' => $user->id, 'discord_guild_id' => '223456789012345678', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('guild_memberships')->insert([
            'id' => (string) Str::uuid(), 'guild_id' => $guildId, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_credentials')->insert([
            'id' => (string) Str::uuid(), 'name' => 'Test bot', 'token_hash' => hash('sha256', 'test-bot-token'),
            'abilities' => json_encode($abilities), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $guildId;
    }
}
