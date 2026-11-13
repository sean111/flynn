<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequireBotService
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $credential = $token ? DB::table('service_credentials')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first() : null;
        $configured = (string) config('services.flynn.bot_token');
        $configuredBearer = $token && $configured !== '' && hash_equals($configured, $token);
        $hmacSecret = (string) config('services.flynn.bot_hmac_secret');
        $authenticatedByHmac = false;
        if (! $credential && ! $configuredBearer && $hmacSecret !== '') {
            $timestamp = (string) $request->header('X-Flynn-Timestamp');
            $nonce = (string) $request->header('X-Flynn-Nonce');
            $signature = (string) $request->header('X-Flynn-Signature');
            if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300 || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || ! preg_match('/^[A-Fa-f0-9]{64}$/', $signature)) {
                return $this->unauthenticated($request, 'Bot HMAC timestamp, nonce, or signature is invalid.');
            }
            $canonicalRequest = implode("\n", [
                $request->method(), $request->getPathInfo(), $request->getQueryString() ?? '', $timestamp, $nonce,
                hash('sha256', $request->getContent()),
                (string) $request->header('X-Flynn-Discord-User-ID'), (string) $request->header('X-Flynn-Discord-Guild-ID'),
            ]);
            $expected = hash_hmac('sha256', $canonicalRequest, $hmacSecret);
            if (! hash_equals($expected, strtolower($signature))) {
                return $this->unauthenticated($request, 'Bot HMAC signature is invalid.');
            }
            if (! Cache::add('flynn-hmac-nonce:'.hash('sha256', $nonce), true, now()->addMinutes(10))) {
                return response()->json(['error' => ['code' => 'request_replay', 'message' => 'This signed bot request has already been used.', 'details' => (object) [], 'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20))]], 409);
            }
            $authenticatedByHmac = true;
        }
        if (! $credential && ! $configuredBearer && ! $authenticatedByHmac) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Bot service credential is invalid.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 401);
        }
        $requiredAbility = match (true) {
            $request->isMethod('GET') && str_contains($request->path(), '/recipes') => 'recipes:read',
            $request->isMethod('GET') && str_contains($request->path(), '/cart') => 'cart:read',
            $request->isMethod('GET') && preg_match('#/guilds/[^/]+$#', $request->path()) => 'guilds:read',
            $request->isMethod('GET') => 'requests:read',
            str_contains($request->path(), '/cart') => 'cart:write',
            default => 'requests:write',
        };
        $configuredAbilities = $authenticatedByHmac
            ? config('services.flynn.bot_hmac_abilities', [])
            : config('services.flynn.bot_service_abilities', []);
        $abilities = $credential ? (json_decode($credential->abilities, true) ?: []) : $configuredAbilities;
        if (! in_array('*', $abilities, true) && ! in_array($requiredAbility, $abilities, true)) {
            return response()->json(['error' => ['code' => 'insufficient_scope', 'message' => 'Bot service credential is missing the required '.$requiredAbility.' scope.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 403);
        }
        if ($credential) {
            DB::table('service_credentials')->where('id', $credential->id)->update(['last_used_at' => now()]);
        }
        $request->attributes->set('bot_credential', $credential);

        return $next($request);
    }

    private function unauthenticated(Request $request, string $message): Response
    {
        return response()->json(['error' => [
            'code' => 'unauthenticated', 'message' => $message, 'details' => (object) [],
            'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20)),
        ]], 401);
    }
}
