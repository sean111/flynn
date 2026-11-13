<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class AuthController extends ApiController
{
    public function register(Request $request)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);
        $user = User::create(['name' => trim($data['name']), 'email' => $data['email'], 'password' => $data['password']]);
        $request->session()->regenerate();
        auth()->login($user);

        return $this->ok($this->account($user), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('invalid_credentials', 'The email or password is incorrect.', 401);
        }
        auth()->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return $this->ok($this->account($user));
    }

    public function logout(Request $request)
    {
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return $this->ok($this->account($request->user()));
    }

    public function update(Request $request)
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'min:2', 'max:100']]);
        $request->user()->update($data);

        return $this->ok($this->account($request->user()->fresh()));
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);
        if (! Hash::check($data['currentPassword'], $request->user()->password)) {
            return $this->error('current_password_invalid', 'Current password is incorrect.', 422, ['currentPassword' => ['Current password is incorrect.']]);
        }
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->user()->tokens()->delete();

        return $this->ok(['passwordUpdated' => true, 'tokensRevoked' => true]);
    }

    public function discordRedirect(Request $request)
    {
        if (! config('services.discord.client_id') || ! config('services.discord.redirect')) {
            return $this->error('discord_not_configured', 'Discord linking is not configured on this server yet.', 503);
        }
        $state = Str::random(48);
        $request->session()->put('discord_link_state', hash('sha256', $state));
        $url = 'https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.discord.client_id'), 'redirect_uri' => config('services.discord.redirect'),
            'response_type' => 'code', 'scope' => 'identify', 'state' => $state,
        ]);

        return redirect()->away($url);
    }

    public function discordCallback(Request $request)
    {
        $state = $request->query('state');
        $expected = $request->session()->pull('discord_link_state');
        if (! is_string($state) || ! is_string($expected) || ! hash_equals($expected, hash('sha256', $state)) || ! $request->query('code')) {
            return $this->error('invalid_oauth_state', 'Discord linking could not be verified. Please retry from account settings.', 422);
        }
        $token = Http::asForm()->timeout(10)->post('https://discord.com/api/oauth2/token', [
            'client_id' => config('services.discord.client_id'), 'client_secret' => config('services.discord.client_secret'),
            'grant_type' => 'authorization_code', 'code' => $request->query('code'), 'redirect_uri' => config('services.discord.redirect'),
        ]);
        if (! $token->successful() || ! $token->json('access_token')) {
            return $this->error('discord_oauth_failed', 'Discord authorization could not be completed.', 422);
        }
        $profile = Http::withToken($token->json('access_token'))->timeout(10)->get('https://discord.com/api/users/@me');
        if (! $profile->successful() || ! preg_match('/^\d{17,20}$/', (string) $profile->json('id'))) {
            return $this->error('discord_oauth_failed', 'Discord did not return a valid identity.', 422);
        }
        $discordId = (string) $profile->json('id');
        try {
            DB::transaction(function () use ($request, $discordId, $profile) {
                DB::table('discord_identities')->updateOrInsert(['user_id' => $request->user()->id], [
                    'id' => (string) Str::uuid(), 'discord_user_id' => $discordId,
                    'handle' => substr((string) ($profile->json('global_name') ?: $profile->json('username')), 0, 128),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error('discord_identity_in_use', 'That Discord account is already linked to another Flynn account.', 409);
        }

        return redirect('/account?discord=linked');
    }

    public function unlinkDiscord(Request $request)
    {
        DB::table('discord_identities')->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }

    public function tokens(Request $request)
    {
        $tokens = $request->user()->tokens()->orderBy('id')->cursorPaginate(50, ['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at']);

        return $this->ok($tokens->getCollection(), 200, ['nextCursor' => $tokens->nextCursor()?->encode()]);
    }

    public function revokeToken(Request $request, string $id)
    {
        $deleted = $request->user()->tokens()->whereKey($id)->delete();

        return $deleted ? response()->noContent() : $this->error('not_found', 'Token was not found.', 404);
    }

    public function desktopAuthorize(Request $request)
    {
        if ($request->isMethod('GET')) {
            return redirect('/desktop/authorize?'.$request->getQueryString());
        }
        $data = $request->validate([
            'codeChallenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'state' => ['required', 'string', 'min:16', 'max:256'],
            'redirectUri' => ['required', 'url'],
        ]);
        $allowed = config('services.flynn.desktop_redirect_uri');
        if (! $allowed || ! hash_equals($allowed, $data['redirectUri'])) {
            return $this->error('invalid_redirect_uri', 'Desktop redirect URI is not registered.', 422);
        }
        $code = Str::random(64);
        $id = (string) Str::uuid();
        DB::table('desktop_authorization_codes')->insert([
            'id' => $id, 'user_id' => $request->user()->id, 'code_hash' => hash('sha256', $code),
            'code_challenge' => $data['codeChallenge'], 'state_hash' => hash('sha256', $data['state']),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->away($data['redirectUri'].'?'.http_build_query(['code' => $code, 'state' => $data['state']]));
    }

    public function desktopToken(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string'], 'codeVerifier' => ['required', 'string', 'min:43', 'max:128']]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['codeVerifier'], true)), '+/', '-_'), '=');
        $token = DB::transaction(function () use ($data, $challenge) {
            $code = DB::table('desktop_authorization_codes')->where('code_hash', hash('sha256', $data['code']))->lockForUpdate()->first();
            if (! $code || $code->exchanged_at || now()->greaterThan($code->expires_at) || ! hash_equals($code->code_challenge, $challenge)) {
                return null;
            }
            DB::table('desktop_authorization_codes')->where('id', $code->id)->update(['exchanged_at' => now(), 'updated_at' => now()]);
            $user = User::find($code->user_id);

            return $user?->createToken('Flynn Desktop', ['account:read', 'dumps:write'], now()->addDays(30));
        });
        if (! $token) {
            return $this->error('invalid_authorization_code', 'Authorization code is invalid, expired, or already used.', 401);
        }

        return $this->ok(['accessToken' => $token->plainTextToken, 'tokenType' => 'Bearer', 'expiresAt' => $token->accessToken->expires_at?->toISOString(), 'abilities' => $token->accessToken->abilities]);
    }

    private function account(User $user): array
    {
        $discord = DB::table('discord_identities')->where('user_id', $user->id)->first();

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'discord' => $discord ? ['discordUserID' => $discord->discord_user_id, 'handle' => $discord->handle] : null];
    }
}
