<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ApiAuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_me_endpoint_returns_a_contract_shaped_401_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')->assertJsonStructure(['error' => ['code', 'message', 'details', 'requestId']]);
    }

    public function test_registration_creates_a_user_and_starts_a_session(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost')->postJson('/api/v1/auth/register', [
            'name' => 'Tessera', 'email' => 'TESSERA@example.com', 'password' => 'Long-Passphrase-907', 'password_confirmation' => 'Long-Passphrase-907',
        ]);

        $response->assertCreated()->assertJsonPath('data.email', 'tessera@example.com');
        $this->assertDatabaseHas('users', ['email' => 'tessera@example.com']);
    }

    public function test_login_accepts_the_account_password_and_rejects_an_incorrect_password(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => 'Correct-Password-892']);

        $this->withHeader('Origin', 'http://localhost')->postJson('/api/v1/auth/login', ['email' => 'login@example.com', 'password' => 'wrong-password'])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/v1/auth/login', ['email' => 'LOGIN@example.com', 'password' => 'Correct-Password-892'])->assertOk()->assertJsonPath('data.email', 'login@example.com');
    }

    public function test_password_change_requires_the_current_password_and_revokes_desktop_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Old-Password-892']);
        $user->createToken('Flynn Desktop', ['account:read', 'dumps:write']);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/v1/me/password', [
            'currentPassword' => 'wrong-password', 'password' => 'New-Password-123', 'password_confirmation' => 'New-Password-123',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'current_password_invalid');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->patchJson('/api/v1/me/password', [
            'currentPassword' => 'Old-Password-892', 'password' => 'New-Password-123', 'password_confirmation' => 'New-Password-123',
        ])->assertOk()->assertJsonPath('data.passwordUpdated', true);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
