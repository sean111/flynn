<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class FlynnAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (($bearer && $this->isServiceToken($bearer)) || ($request->hasHeader('X-Flynn-Signature') && config('services.flynn.bot_hmac_secret'))) {
            return app(RequireBotService::class)->handle($request, fn (Request $req) => app(ResolveBotActor::class)->handle($req, $next));
        }
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            throw new AuthenticationException('Unauthenticated.');
        }
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function isServiceToken(string $token): bool
    {
        $configured = (string) config('services.flynn.bot_token');

        return ($configured !== '' && hash_equals($configured, $token)) || DB::table('service_credentials')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->exists();
    }
}
