<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\FlynnAuth;
use App\Http\Middleware\LimitApiBody;
use App\Http\Middleware\RequireTokenAbility;
use App\Http\Middleware\RequireWebSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'flynn.auth' => FlynnAuth::class,
            'request.id' => AssignRequestId::class,
            'api.body' => LimitApiBody::class,
            'session.only' => RequireWebSession::class,
            'token.ability' => RequireTokenAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'invalid_input',
                'message' => 'The submitted data is invalid.',
                'details' => $exception->errors(),
                'requestId' => 'req_'.str()->random(20),
            ]], 422);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Authentication is required.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 401);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'You are not authorized to perform this action.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 403);
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? 60;

            return response()->json(['error' => [
                'code' => 'rate_limited',
                'message' => 'Too many requests. Retry in '.$retryAfter.' seconds.',
                'details' => (object) [],
                'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20)),
            ]], 429, ['Retry-After' => (string) $retryAfter]);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $exception->getStatusCode();
            $code = $status === 404 ? 'not_found' : 'request_failed';
            $message = $status === 404 ? 'The requested resource was not found.' : 'The request could not be completed.';

            return response()->json(['error' => [
                'code' => $code, 'message' => $message, 'details' => (object) [],
                'requestId' => $request->attributes->get('request_id', 'req_'.str()->random(20)),
            ]], $status, $exception->getHeaders());
        });
    })->create();
