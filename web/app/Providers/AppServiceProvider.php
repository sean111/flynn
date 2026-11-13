<?php

namespace App\Providers;

use App\Services\DiscordClient;
use App\Services\DiscordRestClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DiscordClient::class, DiscordRestClient::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn ($request) => [
            Limit::perMinutes(15, 5)->by('login-email:'.strtolower((string) $request->input('email'))),
            Limit::perMinutes(15, 5)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('register', fn ($request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('discord-oauth', fn ($request) => [
            Limit::perMinutes(10, 10)->by('oauth-account:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinutes(10, 10)->by('oauth-ip:'.$request->ip()),
        ]);
        RateLimiter::for('unauthenticated', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('authenticated', fn ($request) => Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('dumps', fn ($request) => [
            Limit::perHour(10)->by('dump-hour:'.($request->user()?->id ?? $request->ip())),
            Limit::perDay(100)->by('dump-day:'.($request->user()?->id ?? $request->ip())),
        ]);
        RateLimiter::for('guild-mutations', fn ($request) => Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip())));
        RateLimiter::for('bot-reads', fn ($request) => Limit::perMinute(($request->bearerToken() || $request->hasHeader('X-Flynn-Signature')) ? 60 : 120)->by(($request->header('X-Flynn-Discord-User-ID') ?? $request->user()?->id ?? $request->ip()).':'.($request->header('X-Flynn-Discord-Guild-ID') ?? '')));
        RateLimiter::for('bot-mutations', fn ($request) => Limit::perMinute(($request->bearerToken() || $request->hasHeader('X-Flynn-Signature')) ? 20 : 120)->by(($request->header('X-Flynn-Discord-User-ID') ?? $request->user()?->id ?? $request->ip()).':'.($request->header('X-Flynn-Discord-Guild-ID') ?? '')));
    }
}
