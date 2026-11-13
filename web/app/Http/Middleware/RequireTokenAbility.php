<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

final class RequireTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->user()?->currentAccessToken();
        // Sanctum's transient token represents a stateful web session and intentionally
        // grants all abilities. Persistent tokens must carry the route's explicit scope.
        if ($token instanceof Model && $token->exists && is_a($token, Sanctum::personalAccessTokenModel()) && ! $token->can($ability)) {
            return response()->json(['error' => [
                'code' => 'forbidden',
                'message' => 'This access token does not have the required permission.',
                'details' => (object) [],
                'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20)),
            ]], 403);
        }

        return $next($request);
    }
}
