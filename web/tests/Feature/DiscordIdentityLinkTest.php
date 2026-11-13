<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DiscordIdentityLinkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_oauth_link_persists_the_discord_identity_verified_by_discord(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://discord.com/api/oauth2/token' => Http::response(['access_token' => 'verified-access-token', 'token_type' => 'Bearer'], 200),
            'https://discord.com/api/users/@me' => Http::response(['id' => '123456789012345678', 'username' => 'runesmith', 'global_name' => 'Rune Smith'], 200),
        ]);
        $user = User::factory()->create();
        config()->set('services.discord.client_id', 'client-id');
        config()->set('services.discord.client_secret', 'client-secret');
        config()->set('services.discord.redirect', 'http://localhost/api/v1/auth/discord/callback');
        $this->actingAs($user);

        $redirect = $this->withHeader('Origin', 'http://localhost')->get('/api/v1/auth/discord/redirect');
        $redirect->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = $this->withHeader('Origin', 'http://localhost')->get('/api/v1/auth/discord/callback?'.http_build_query(['state' => $query['state'], 'code' => 'discord-code']));

        $callback->assertRedirect('/account?discord=linked');
        $this->assertDatabaseHas('discord_identities', ['user_id' => $user->id, 'discord_user_id' => '123456789012345678', 'handle' => 'Rune Smith']);
    }

    public function test_oauth_callback_rejects_a_mismatched_state_without_calling_discord(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        config()->set('services.discord.client_id', 'client-id');
        config()->set('services.discord.client_secret', 'client-secret');
        config()->set('services.discord.redirect', 'http://localhost/api/v1/auth/discord/callback');
        $this->actingAs($user);

        $response = $this->withHeader('Origin', 'http://localhost')->get('/api/v1/auth/discord/callback?state=wrong&code=discord-code');

        $response->assertUnprocessable()->assertJsonPath('error.code', 'invalid_oauth_state');
        $this->assertDatabaseCount('discord_identities', 0);
    }
}
