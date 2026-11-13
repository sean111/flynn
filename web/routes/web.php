<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/api/v1/auth/discord/redirect', [AuthController::class, 'discordRedirect'])->middleware(['auth:sanctum', 'throttle:discord-oauth']);
Route::get('/api/v1/auth/discord/callback', [AuthController::class, 'discordCallback'])->middleware(['auth:sanctum', 'throttle:discord-oauth']);
Route::view('/{path?}', 'app')->where('path', '^(?!api(?:/|$)|sanctum(?:/|$)|up$).*$');
