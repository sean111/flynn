<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\DumpController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\RequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['request.id', 'api.body'])->prefix('v1')->group(function () {
    Route::middleware('web')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register'])->middleware(['throttle:register']);
        Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login']);
    });
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'session.only']);
    Route::get('/auth/desktop/authorize', [AuthController::class, 'desktopAuthorize'])->middleware(['throttle:unauthenticated', 'session.only']);
    Route::post('/auth/desktop/authorize', [AuthController::class, 'desktopAuthorize'])->middleware(['auth:sanctum', 'session.only', 'throttle:authenticated']);
    Route::post('/auth/desktop/token', [AuthController::class, 'desktopToken'])->middleware('throttle:unauthenticated');

    // The persistent desktop token's complete allow-list is account identity and dump
    // upload/status. Browser-domain routes below require a stateful web session.
    Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->middleware('token.ability:account:read');
        Route::post('/dumps', [DumpController::class, 'store'])->middleware(['token.ability:dumps:write', 'throttle:dumps']);
        Route::get('/dumps/{dumpId}', [DumpController::class, 'show'])->middleware('token.ability:dumps:write');
    });

    Route::middleware(['auth:sanctum', 'session.only', 'throttle:authenticated'])->group(function () {
        Route::patch('/me', [AuthController::class, 'update']);
        Route::patch('/me/password', [AuthController::class, 'updatePassword']);
        Route::delete('/me/discord', [AuthController::class, 'unlinkDiscord']);
        Route::get('/me/tokens', [AuthController::class, 'tokens']);
        Route::delete('/auth/tokens/{id}', [AuthController::class, 'revokeToken']);
        Route::get('/characters', [CharacterController::class, 'index']);
        Route::get('/characters/{characterId}', [CharacterController::class, 'show']);
        Route::post('/characters/{characterId}/claim', [CharacterController::class, 'claim']);
        Route::delete('/characters/{characterId}', [CharacterController::class, 'destroy']);
        Route::put('/characters/{characterId}/guilds/{guildId}', [CharacterController::class, 'assign']);
        Route::delete('/characters/{characterId}/guilds/{guildId}', [CharacterController::class, 'unassign']);
        Route::get('/guilds', [GuildController::class, 'index']);
        Route::post('/guilds', [GuildController::class, 'create'])->middleware('throttle:guild-mutations');
        Route::patch('/guilds/{guildId}', [GuildController::class, 'update'])->middleware('throttle:guild-mutations');
        Route::post('/guilds/{guildId}/discord-link/verify', [GuildController::class, 'verifyDiscord'])->middleware('throttle:guild-mutations');
        Route::post('/guilds/{guildId}/invites', [GuildController::class, 'createInvite'])->middleware('throttle:guild-mutations');
        Route::post('/guild-invites/{inviteToken}/applications', [GuildController::class, 'applyInvite'])->middleware('throttle:guild-mutations');
        Route::post('/guilds/{guildId}/applications', [GuildController::class, 'apply'])->middleware('throttle:guild-mutations');
        Route::get('/guilds/{guildId}/members', [GuildController::class, 'members']);
        Route::post('/guilds/{guildId}/members/{membershipId}/approve', [GuildController::class, 'approve'])->middleware('throttle:guild-mutations');
        Route::post('/guilds/{guildId}/members/{membershipId}/reject', [GuildController::class, 'reject'])->middleware('throttle:guild-mutations');
        Route::post('/guilds/{guildId}/members/{membershipId}/revoke', [GuildController::class, 'revoke'])->middleware('throttle:guild-mutations');
        Route::patch('/guilds/{guildId}/members/{membershipId}/role', [GuildController::class, 'role'])->middleware('throttle:guild-mutations');
    });

    Route::middleware(['flynn.auth', 'session.only', 'throttle:authenticated'])->group(function () {
        Route::get('/guilds/{guildId}', [GuildController::class, 'show']);
        Route::get('/guilds/{guildId}/recipes', [CraftingController::class, 'recipes'])->middleware('throttle:bot-reads');
        Route::get('/guilds/{guildId}/recipes/{spellId}', [CraftingController::class, 'recipe'])->whereNumber('spellId')->middleware('throttle:bot-reads');
        Route::get('/guilds/{guildId}/recipes/{spellId}/crafters', [CraftingController::class, 'crafters'])->whereNumber('spellId')->middleware('throttle:bot-reads');
        Route::get('/guilds/{guildId}/cart', [CraftingController::class, 'cart']);
        Route::post('/guilds/{guildId}/cart/items', [CraftingController::class, 'addCartItem'])->middleware('throttle:bot-mutations');
        Route::patch('/guilds/{guildId}/cart/items/{itemId}', [CraftingController::class, 'updateCartItem'])->middleware('throttle:bot-mutations');
        Route::delete('/guilds/{guildId}/cart/items/{itemId}', [CraftingController::class, 'removeCartItem'])->middleware('throttle:bot-mutations');
        Route::delete('/guilds/{guildId}/cart', [CraftingController::class, 'clearCart'])->middleware('throttle:bot-mutations');
        Route::post('/guilds/{guildId}/cart/checkout', [CraftingController::class, 'checkout'])->middleware('throttle:bot-mutations');
        Route::get('/me/requests/incoming', [RequestController::class, 'incoming']);
        Route::get('/me/requests/outgoing', [RequestController::class, 'outgoing']);
        Route::get('/requests/{requestId}', [RequestController::class, 'show']);
        Route::post('/requests/{requestId}/accept', [RequestController::class, 'accept'])->middleware('throttle:bot-mutations');
        Route::post('/requests/{requestId}/deny', [RequestController::class, 'deny'])->middleware('throttle:bot-mutations');
        Route::post('/requests/{requestId}/complete', [RequestController::class, 'complete'])->middleware('throttle:bot-mutations');
        Route::post('/requests/{requestId}/completion-confirmation', [RequestController::class, 'confirmCompletion'])->middleware('throttle:bot-mutations');
        Route::post('/requests/{requestId}/cancel', [RequestController::class, 'cancel'])->middleware('throttle:bot-mutations');
    });
});
