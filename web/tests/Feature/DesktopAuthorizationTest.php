<?php

namespace Tests\Feature;

use App\Jobs\IngestDump;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DesktopAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pkce_authorization_code_is_exchanged_once_for_a_scoped_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        config()->set('services.flynn.desktop_redirect_uri', 'http://127.0.0.1:51337/oauth/callback');
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $authorization = $this->postJson('/api/v1/auth/desktop/authorize', [
            'codeChallenge' => $challenge, 'state' => 'client-state-123456789', 'redirectUri' => 'http://127.0.0.1:51337/oauth/callback',
        ]);
        $authorization->assertRedirect();
        parse_str((string) parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $redirectParameters);
        $this->assertSame('client-state-123456789', $redirectParameters['state']);
        $code = $redirectParameters['code'];

        $exchange = $this->postJson('/api/v1/auth/desktop/token', ['code' => $code, 'codeVerifier' => $verifier]);

        $exchange->assertOk()->assertJsonPath('data.tokenType', 'Bearer')->assertJsonPath('data.abilities', ['account:read', 'dumps:write']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Flynn Desktop']);
        $tokenId = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('id');
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/v1/me/tokens')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/auth/tokens/'.$tokenId)->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->postJson('/api/v1/auth/desktop/token', ['code' => $code, 'codeVerifier' => $verifier])->assertUnauthorized();
    }

    public function test_expired_sanctum_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Expired Desktop', ['account:read'], now()->subMinute());

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_desktop_scopes_allow_account_and_dump_operations_but_reject_domain_routes(): void
    {
        Queue::fake([IngestDump::class]);
        $user = User::factory()->create();
        $token = $user->createToken('Flynn Desktop', ['account:read', 'dumps:write']);
        $client = $this->withToken($token->plainTextToken);

        $client->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $dump = $client->postJson('/api/v1/dumps', ['format' => 'structuredJson', 'payload' => $this->dumpDocument()]);
        $dump->assertAccepted();
        $client->getJson('/api/v1/dumps/'.$dump->json('data.dumpId'))->assertOk()->assertJsonPath('data.status', 'queued');

        foreach ([
            ['GET', '/api/v1/guilds'], ['GET', '/api/v1/characters'], ['POST', '/api/v1/guilds'],
            ['GET', '/api/v1/guilds/'.(string) Str::uuid()],
            ['GET', '/api/v1/guilds/'.(string) Str::uuid().'/cart'],
            ['POST', '/api/v1/guilds/'.(string) Str::uuid().'/cart/checkout'],
            ['POST', '/api/v1/requests/'.(string) Str::uuid().'/accept'],
            ['PATCH', '/api/v1/me'], ['DELETE', '/api/v1/me/discord'],
        ] as [$method, $uri]) {
            $client->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertForbidden();
        }
    }

    private function dumpDocument(): array
    {
        return ['schemaVersion' => 1, 'characters' => ['retail' => ['us' => ['Area 52' => ['Examplemage' => [
            'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52', 'characterName' => 'Examplemage',
            'guid' => 'Player-3676-0ABCDEF0', 'faction' => 'horde', 'class' => 'MAGE', 'capturedAt' => 1794528000, 'professions' => [],
        ]]]]]];
    }
}
