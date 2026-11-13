<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/** Keep browser-only account and domain operations off desktop bearer tokens. */
final class RequireWebSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof Model && $token->exists && is_a($token, Sanctum::personalAccessTokenModel())) {
            return response()->json(['error' => [
                'code' => 'forbidden',
                'message' => 'This operation requires a Flynn web session.',
                'details' => (object) [],
                'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20)),
            ]], 403);
        }

        return $next($request);
    }
}
