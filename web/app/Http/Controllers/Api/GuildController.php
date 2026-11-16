<?php

namespace App\Http\Controllers\Api;

use App\Services\DiscordClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GuildController extends ApiController
{
    public function index(Request $request)
    {
        $query = DB::table('guilds')->where(function ($visibility) use ($request) {
            $visibility->where('discoverable', true)->orWhereExists(function ($membership) use ($request) {
                $membership->selectRaw('1')->from('guild_memberships')->whereColumn('guild_memberships.guild_id', 'guilds.id')->where('guild_memberships.user_id', $request->user()->id);
            });
        });
        if ($search = trim((string) $request->query('query', ''))) {
            $query->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
        }
        foreach (['gameFlavor' => 'game_flavor', 'region' => 'region', 'realm' => 'realm'] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, $request->query($input));
            }
        }

        $guilds = $query->orderBy('name')->orderBy('id')->cursorPaginate(50);

        return $this->ok($guilds->getCollection()->map(fn ($guild) => $this->resource($guild, $request->user()->id)), 200, ['nextCursor' => $guilds->nextCursor()?->encode()]);
    }

    public function create(Request $request, DiscordClient $discord)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'gameFlavor' => ['required', 'in:retail,classic_era,classic,forever'],
            'region' => ['required', 'in:us,eu,kr,tw,cn'],
            'realm' => ['required', 'string', 'max:128'],
            'discordGuildID' => ['nullable', 'regex:/^\d{17,20}$/'],
        ]);
        if (! empty($data['discordGuildID']) && ! $discord->botHasGuildAdministratorAccess($data['discordGuildID'])) {
            return $this->error('discord_verification_failed', 'Flynn bot must be present in this Discord server with Administrator permission.', 422);
        }
        if (! empty($data['discordGuildID']) && DB::table('guilds')->where('discord_guild_id', $data['discordGuildID'])->exists()) {
            return $this->error('discord_guild_in_use', 'This Discord server is already linked to another Flynn guild.', 409);
        }
        $guild = DB::transaction(function () use ($data, $request) {
            $id = (string) Str::uuid();
            DB::table('guilds')->insert([
                'id' => $id, 'name' => trim($data['name']), 'game_flavor' => $data['gameFlavor'],
                'region' => $data['region'], 'realm' => trim($data['realm']), 'owner_id' => $request->user()->id,
                'discord_guild_id' => $data['discordGuildID'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('guild_memberships')->insert([
                'id' => (string) Str::uuid(), 'guild_id' => $id, 'user_id' => $request->user()->id,
                'role' => 'owner', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('guilds')->find($id);
        });

        return $this->ok($this->resource($guild, $request->user()->id), 201);
    }

    public function show(Request $request, string $guildId)
    {
        $guild = DB::table('guilds')->where('id', $guildId)->first();
        if (! $guild) {
            return $this->error('not_found', 'Guild was not found.', 404);
        }
        if (! $guild->discoverable && ! $this->membership($request, $guildId)) {
            return $this->error('not_found', 'Guild was not found.', 404);
        }

        return $this->ok($this->resource($guild, $request->user()->id));
    }

    public function update(Request $request, string $guildId)
    {
        $guild = DB::table('guilds')->where('id', $guildId)->first();
        if (! $guild) {
            return $this->error('not_found', 'Guild was not found.', 404);
        }
        if (! $this->isOwnerOrOfficer($request, $guildId)) {
            return $this->error('forbidden', 'Only guild Owners and Officers may update these settings.', 403);
        }
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:100'], 'discoverable' => ['sometimes', 'boolean'], 'notificationChannelID' => ['sometimes', 'nullable', 'regex:/^\d{17,20}$/']]);
        DB::table('guilds')->where('id', $guildId)->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'discoverable' => $data['discoverable'] ?? null,
            'notification_channel_id' => $data['notificationChannelID'] ?? null,
            'updated_at' => now(),
        ], fn ($value) => $value !== null));

        return $this->ok($this->resource(DB::table('guilds')->find($guildId), $request->user()->id));
    }

    public function verifyDiscord(Request $request, string $guildId, DiscordClient $discord)
    {
        $guild = DB::table('guilds')->where('id', $guildId)->first();
        if (! $guild) {
            return $this->error('not_found', 'Guild was not found.', 404);
        }
        if (! $this->isOwner($request, $guildId)) {
            return $this->error('forbidden', 'Only the Guild Owner may link a Discord server.', 403);
        }
        $data = $request->validate(['discordGuildID' => ['required', 'regex:/^\d{17,20}$/']]);
        if (! $discord->botHasGuildAdministratorAccess($data['discordGuildID'])) {
            return $this->error('discord_verification_failed', 'Flynn bot must be present in this Discord server with Administrator permission.', 422);
        }
        $exists = DB::table('guilds')->where('discord_guild_id', $data['discordGuildID'])->where('id', '!=', $guildId)->exists();
        if ($exists) {
            return $this->error('discord_guild_in_use', 'This Discord server is already linked to another Flynn guild.', 409);
        }
        DB::table('guilds')->where('id', $guildId)->update(['discord_guild_id' => $data['discordGuildID'], 'updated_at' => now()]);

        return $this->ok($this->resource(DB::table('guilds')->find($guildId), $request->user()->id));
    }

    public function apply(Request $request, string $guildId)
    {
        $guild = DB::table('guilds')->where('id', $guildId)->where('discoverable', true)->first();
        if (! $guild) {
            return $this->error('not_found', 'Guild is not available for applications.', 404);
        }

        return $this->createApplication($request, $guildId);
    }

    public function applyInvite(Request $request, string $inviteToken)
    {
        $invite = DB::table('guild_invites')->where('token_hash', hash('sha256', $inviteToken))->where('expires_at', '>', now())->first();
        if (! $invite) {
            return $this->error('invalid_invite', 'Invite is invalid or expired.', 404);
        }
        DB::table('guild_invites')->where('id', $invite->id)->increment('uses');

        return $this->createApplication($request, $invite->guild_id);
    }

    public function members(Request $request, string $guildId)
    {
        if (! DB::table('guilds')->where('id', $guildId)->exists()) {
            return $this->error('not_found', 'Guild was not found.', 404);
        }
        $membership = $this->membership($request, $guildId);
        if (! $membership || $membership->status !== 'approved') {
            return $this->error('forbidden', 'An approved guild membership is required.', 403);
        }
        $query = DB::table('guild_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.guild_id', $guildId);
        if (! in_array($membership->role, ['owner', 'officer'], true)) {
            $query->where('m.status', 'approved')->where('m.user_id', $request->user()->id);
        }
        $items = $query->select('m.id', 'm.user_id as userId', 'u.name', 'm.role', 'm.status', 'm.created_at as appliedAt')->orderBy('m.id')->cursorPaginate(50);

        return $this->ok($items->getCollection(), 200, ['nextCursor' => $items->nextCursor()?->encode()]);
    }

    public function approve(Request $request, string $guildId, string $membershipId)
    {
        return $this->review($request, $guildId, $membershipId, 'approved');
    }

    public function reject(Request $request, string $guildId, string $membershipId)
    {
        return $this->review($request, $guildId, $membershipId, 'rejected');
    }

    public function revoke(Request $request, string $guildId, string $membershipId)
    {
        return $this->review($request, $guildId, $membershipId, 'revoked');
    }

    public function role(Request $request, string $guildId, string $membershipId)
    {
        if (! $this->isOwner($request, $guildId)) {
            return $this->error('forbidden', 'Only the Guild Owner may change Officer roles.', 403);
        }
        $data = $request->validate(['role' => ['required', 'in:officer,member']]);
        $member = DB::table('guild_memberships')->where('guild_id', $guildId)->where('id', $membershipId)->where('status', 'approved')->first();
        if (! $member || $member->role === 'owner') {
            return $this->error('invalid_membership', 'Membership cannot be changed.', 409);
        }
        DB::table('guild_memberships')->where('id', $membershipId)->update(['role' => $data['role'], 'updated_at' => now()]);

        return $this->ok(['id' => $membershipId, 'role' => $data['role']]);
    }

    public function createInvite(Request $request, string $guildId)
    {
        if (! $this->isOwnerOrOfficer($request, $guildId)) {
            return $this->error('forbidden', 'Only Owners and Officers may create invites.', 403);
        }
        $data = $request->validate(['expiresInDays' => ['sometimes', 'integer', 'min:1', 'max:30']]);
        $token = Str::random(48);
        $expiry = now()->addDays($data['expiresInDays'] ?? 7);
        DB::table('guild_invites')->insert(['id' => (string) Str::uuid(), 'guild_id' => $guildId, 'created_by' => $request->user()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);

        return $this->ok(['inviteToken' => $token, 'expiresAt' => $expiry->toISOString()], 201);
    }

    private function createApplication(Request $request, string $guildId)
    {
        $existing = DB::table('guild_memberships')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->first();
        if ($existing && in_array($existing->status, ['pending', 'approved'], true)) {
            return $this->error('application_exists', 'You already have an active membership or application.', 409);
        }
        $id = $existing?->id ?? (string) Str::uuid();
        $values = ['role' => 'member', 'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'updated_at' => now()];
        if ($existing) {
            DB::table('guild_memberships')->where('id', $id)->update($values);
        } else {
            DB::table('guild_memberships')->insert($values + ['id' => $id, 'guild_id' => $guildId, 'user_id' => $request->user()->id, 'created_at' => now()]);
        }

        return $this->ok(['id' => $id, 'guildId' => $guildId, 'status' => 'pending'], 201);
    }

    private function review(Request $request, string $guildId, string $membershipId, string $status)
    {
        if (! $this->isOwnerOrOfficer($request, $guildId)) {
            return $this->error('forbidden', 'Only Owners and Officers may review membership.', 403);
        }
        $member = DB::table('guild_memberships')->where('guild_id', $guildId)->where('id', $membershipId)->first();
        if (! $member || $member->status !== ($status === 'revoked' ? 'approved' : 'pending') || $member->role === 'owner') {
            return $this->error('invalid_membership_state', 'Membership is not in a state that can be changed.', 409);
        }
        if ($member->role === 'officer' && ! $this->isOwner($request, $guildId)) {
            return $this->error('forbidden', 'Only the Guild Owner may revoke an Officer.', 403);
        }
        DB::table('guild_memberships')->where('id', $membershipId)->update(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);

        return $this->ok(['id' => $membershipId, 'status' => $status]);
    }

    private function membership(Request $request, string $guildId): ?object
    {
        return DB::table('guild_memberships')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->first();
    }

    private function isOwner(Request $request, string $guildId): bool
    {
        return (string) DB::table('guilds')->where('id', $guildId)->value('owner_id') === (string) $request->user()->id;
    }

    private function isOwnerOrOfficer(Request $request, string $guildId): bool
    {
        $member = $this->membership($request, $guildId);

        return $member && $member->status === 'approved' && in_array($member->role, ['owner', 'officer'], true);
    }

    private function resource(object $guild, ?string $userId = null): array
    {
        $membership = $userId ? DB::table('guild_memberships')->where('guild_id', $guild->id)->where('user_id', $userId)->first() : null;

        return ['id' => $guild->id, 'name' => $guild->name, 'gameFlavor' => $guild->game_flavor, 'region' => $guild->region, 'realm' => $guild->realm, 'ownerId' => $guild->owner_id, 'discordGuildID' => $guild->discord_guild_id, 'discoverable' => (bool) $guild->discoverable, 'membershipStatus' => $membership?->status, 'membershipRole' => $membership?->role];
    }
}
