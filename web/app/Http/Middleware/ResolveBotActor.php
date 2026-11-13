<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBotActor
{
    public function handle(Request $request, Closure $next): Response
    {
        $discordUserId = $request->header('X-Flynn-Discord-User-ID');
        $discordGuildId = $request->header('X-Flynn-Discord-Guild-ID');
        if (! preg_match('/^\d{17,20}$/', (string) $discordUserId) || ! preg_match('/^\d{17,20}$/', (string) $discordGuildId)) {
            return response()->json(['error' => ['code' => 'invalid_actor_context', 'message' => 'Both Discord actor headers are required.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 422);
        }
        $identity = DB::table('discord_identities')->where('discord_user_id', $discordUserId)->first();
        $guild = DB::table('guilds')->where('discord_guild_id', $discordGuildId)->first();
        $guildId = $request->route('guildId');
        if (! $identity || ! $guild || ($guildId && $guild->id !== $guildId) || ! DB::table('guild_memberships')->where('guild_id', $guild->id)->where('user_id', $identity->user_id)->where('status', 'approved')->exists()) {
            return response()->json(['error' => ['code' => 'forbidden', 'message' => 'The linked Discord identity must be an approved member of this linked Flynn guild.', 'details' => (object) [], 'requestId' => 'req_'.str()->random(20)]], 403);
        }
        $user = User::find($identity->user_id);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('discord_actor', ['userId' => $discordUserId, 'guildId' => $discordGuildId]);

        return $next($request);
    }
}
