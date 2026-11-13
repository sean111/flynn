<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = 'req_'.Str::random(20);
        $request->attributes->set('request_id', $requestId);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
