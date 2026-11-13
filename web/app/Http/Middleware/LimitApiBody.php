<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitApiBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/dumps') && $request->isMethod('post')) {
            return $next($request);
        }
        $limit = 1024 * 1024;
        if ((int) $request->header('Content-Length', 0) > $limit || strlen($request->getContent()) > $limit) {
            return response()->json(['error' => [
                'code' => 'payload_too_large', 'message' => 'General API request bodies are limited to 1 MiB.',
                'details' => ['limitBytes' => $limit], 'requestId' => $request->attributes->get('request_id'),
            ]], 413);
        }

        return $next($request);
    }
}
