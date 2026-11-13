<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CharacterController extends ApiController
{
    public function index(Request $request)
    {
        $owned = DB::table('characters')->select('characters.*')->where('owner_id', $request->user()->id);
        $claimable = DB::table('characters as c')->select('c.*')->whereNull('c.owner_id')->whereExists(function ($authorization) use ($request) {
            $authorization->selectRaw('1')->from('character_import_authorizations as a')->whereColumn('a.character_id', 'c.id')->where('a.uploader_id', $request->user()->id);
        });
        $characters = $owned->union($claimable)->orderBy('id')->cursorPaginate(50);

        return $this->ok($characters->getCollection()->map(fn ($character) => $this->resource($character, $character->owner_id ? 'claimed' : 'claimable')), 200, ['nextCursor' => $characters->nextCursor()?->encode()]);
    }

    public function show(Request $request, string $characterId)
    {
        $character = DB::table('characters')->where('id', $characterId)->first();
        if (! $character || ! $this->canView($request, $character)) {
            return $this->error('not_found', 'Character was not found.', 404);
        }
        $professions = DB::table('character_professions')->where('character_id', $characterId)->orderBy('skill_line_id')->get()->map(function ($profession) {
            return [
                'skillLineID' => $profession->skill_line_id, 'currentSkill' => $profession->current_skill, 'maxSkill' => $profession->max_skill,
                'specialization' => $profession->specialization ? json_decode($profession->specialization, true) : null,
                'recipesCount' => DB::table('recipes')->where('profession_id', $profession->id)->count(),
            ];
        });

        return $this->ok($this->resource($character, $character->owner_id ? 'claimed' : 'claimable') + ['professions' => $professions]);
    }

    public function claim(Request $request, string $characterId)
    {
        $result = DB::transaction(function () use ($request, $characterId) {
            $character = DB::table('characters')->where('id', $characterId)->lockForUpdate()->first();
            if (! $character) {
                return ['status' => 'missing'];
            }
            if ($character->owner_id) {
                return ['status' => $character->owner_id === $request->user()->id ? 'owned' : 'conflict'];
            }
            $authorization = DB::table('character_import_authorizations')->where('character_id', $characterId)->where('uploader_id', $request->user()->id)->lockForUpdate()->first();
            if (! $authorization) {
                return ['status' => 'forbidden'];
            }
            DB::table('characters')->where('id', $characterId)->update(['owner_id' => $request->user()->id, 'updated_at' => now()]);
            DB::table('character_import_authorizations')->where('id', $authorization->id)->update(['claimed_at' => now(), 'updated_at' => now()]);

            return ['status' => 'claimed'];
        });

        return match ($result['status']) {
            'missing' => $this->error('not_found', 'Character was not found.', 404),
            'forbidden' => $this->error('forbidden', 'Only the uploading Flynn account may claim this imported character.', 403),
            'conflict' => $this->error('character_ownership_conflict', 'Another Flynn account has already claimed this character.', 409),
            default => $this->ok(['id' => $characterId, 'status' => 'claimed']),
        };
    }

    public function destroy(Request $request, string $characterId)
    {
        try {
            $deleted = DB::transaction(function () use ($request, $characterId): bool {
                $character = DB::table('characters')->where('id', $characterId)->where('owner_id', $request->user()->id)->lockForUpdate()->first();
                if (! $character) {
                    return false;
                }
                DB::table('character_guild')->where('character_id', $characterId)->delete();

                return DB::table('characters')->where('id', $characterId)->delete() > 0;
            });
        } catch (QueryException) {
            return $this->error('character_in_use', 'Character is referenced by a crafting request and cannot be deleted.', 409);
        }
        if (! $deleted) {
            return $this->error('not_found', 'Character was not found.', 404);
        }

        return response()->noContent();
    }

    public function assign(Request $request, string $characterId, string $guildId)
    {
        $character = DB::table('characters')->where('id', $characterId)->where('owner_id', $request->user()->id)->first();
        $guild = DB::table('guilds')->where('id', $guildId)->first();
        if (! $character || ! $guild) {
            return $this->error('not_found', 'Character or guild was not found.', 404);
        }
        if (! DB::table('guild_memberships')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->where('status', 'approved')->exists()) {
            return $this->error('forbidden', 'An approved guild membership is required.', 403);
        }
        if ($character->game_flavor !== $guild->game_flavor || $character->region !== $guild->region || $character->realm !== $guild->realm) {
            return $this->error('character_guild_mismatch', 'Character flavor, region, and realm must match the guild.', 422);
        }
        DB::table('character_guild')->updateOrInsert(['character_id' => $characterId, 'guild_id' => $guildId], ['created_at' => now(), 'updated_at' => now()]);

        return $this->ok(['characterId' => $characterId, 'guildId' => $guildId]);
    }

    public function unassign(Request $request, string $characterId, string $guildId)
    {
        $deleted = DB::table('character_guild')->where('character_id', $characterId)->where('guild_id', $guildId)->whereExists(function ($query) use ($request) {
            $query->selectRaw('1')->from('characters')->whereColumn('characters.id', 'character_guild.character_id')->where('characters.owner_id', $request->user()->id);
        })->delete();

        return $deleted ? response()->noContent() : $this->error('not_found', 'Character association was not found.', 404);
    }

    private function canView(Request $request, object $character): bool
    {
        if ($character->owner_id === $request->user()->id) {
            return true;
        }
        if (! $character->owner_id && DB::table('character_import_authorizations')->where('character_id', $character->id)->where('uploader_id', $request->user()->id)->exists()) {
            return true;
        }

        return DB::table('character_guild')->join('guild_memberships', 'guild_memberships.guild_id', '=', 'character_guild.guild_id')->where('character_guild.character_id', $character->id)->where('guild_memberships.user_id', $request->user()->id)->where('guild_memberships.status', 'approved')->exists();
    }

    private function resource(object $character, string $claimStatus): array
    {
        return ['id' => $character->id, 'ownerId' => $character->owner_id, 'claimStatus' => $character->owner_id ? 'claimed' : $claimStatus, 'gameFlavor' => $character->game_flavor, 'region' => $character->region, 'realm' => $character->realm, 'characterName' => $character->character_name, 'guid' => $character->guid, 'faction' => $character->faction, 'class' => $character->class, 'capturedAt' => $character->captured_at, 'lastSyncedAt' => $character->last_synced_at, 'guildIds' => DB::table('character_guild')->where('character_id', $character->id)->pluck('guild_id')->all()];
    }
}
