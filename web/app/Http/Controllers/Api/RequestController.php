<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestController extends ApiController
{
    public function incoming(Request $request)
    {
        if ($request->filled('status') && ! in_array($request->query('status'), ['pending', 'accepted', 'denied', 'completed', 'cancelled', 'history'], true)) {
            return $this->error('invalid_status_filter', 'Status filter is not supported.', 422);
        }
        $query = DB::table('crafting_requests as r')->where('r.crafter_user_id', $request->user()->id);
        $this->scopeToBotGuild($request, $query);
        if ($request->filled('status') && $request->query('status') !== 'history') {
            $query->where('r.status', $request->query('status'));
        }
        if ($request->query('status') === 'history') {
            $query->whereIn('r.status', ['denied', 'completed', 'cancelled']);
        }
        $rows = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->cursorPaginate(50);

        return $this->ok($rows->getCollection()->map(fn ($row) => $this->resource($row, $request->user()->id, true)), 200, ['nextCursor' => $rows->nextCursor()?->encode()]);
    }

    public function outgoing(Request $request)
    {
        if ($request->filled('status') && ! in_array($request->query('status'), ['pending', 'accepted', 'denied', 'completed', 'cancelled', 'history'], true)) {
            return $this->error('invalid_status_filter', 'Status filter is not supported.', 422);
        }
        $query = DB::table('crafting_requests as r')->where('r.requester_user_id', $request->user()->id);
        $this->scopeToBotGuild($request, $query);
        if ($request->filled('status') && $request->query('status') !== 'history') {
            $query->where('r.status', $request->query('status'));
        }
        if ($request->query('status') === 'history') {
            $query->whereIn('r.status', ['denied', 'completed', 'cancelled']);
        }

        $rows = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->cursorPaginate(50);

        return $this->ok($rows->getCollection()->map(fn ($row) => $this->resource($row, $request->user()->id, true)), 200, ['nextCursor' => $rows->nextCursor()?->encode()]);
    }

    public function show(Request $request, string $requestId)
    {
        $row = DB::table('crafting_requests')->where('id', $requestId)->first();
        if (! $row || ! $this->canSee($request, $row)) {
            return $this->error('not_found', 'Request was not found.', 404);
        }

        return $this->ok($this->resource($row, $request->user()->id, true));
    }

    public function accept(Request $request, string $requestId)
    {
        return $this->transition($request, $requestId, 'accepted', 'crafter');
    }

    public function deny(Request $request, string $requestId)
    {
        return $this->transition($request, $requestId, 'denied', 'crafter');
    }

    public function complete(Request $request, string $requestId)
    {
        return $this->transition($request, $requestId, 'completed', 'crafter');
    }

    public function confirmCompletion(Request $request, string $requestId)
    {
        return $this->transition($request, $requestId, 'completed', 'requester');
    }

    public function cancel(Request $request, string $requestId)
    {
        return $this->transition($request, $requestId, 'cancelled', 'requester');
    }

    private function transition(Request $request, string $requestId, string $to, string $actorRole)
    {
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        $result = DB::transaction(function () use ($request, $requestId, $to, $actorRole, $data) {
            $row = DB::table('crafting_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $row) {
                return ['error' => 'not_found'];
            }
            if (! $this->canSee($request, $row)) {
                return ['error' => 'not_found'];
            }
            if ($actorRole === 'crafter' && $row->crafter_user_id !== $request->user()->id) {
                return ['error' => 'forbidden'];
            }
            if ($actorRole === 'requester' && $row->requester_user_id !== $request->user()->id) {
                return ['error' => 'forbidden'];
            }
            if ($actorRole === 'crafter' && ! DB::table('guild_memberships')->where('guild_id', $row->guild_id)->where('user_id', $row->crafter_user_id)->where('status', 'approved')->exists()) {
                return ['error' => 'forbidden'];
            }
            $allowed = match ($to) {
                'accepted', 'denied' => $row->status === 'pending' && $actorRole === 'crafter',
                'completed' => $row->status === 'accepted',
                'cancelled' => in_array($row->status, ['pending', 'accepted'], true) && $actorRole === 'requester',
                default => false,
            };
            if (! $allowed) {
                return ['error' => 'conflict'];
            }
            if ($to === 'completed' && $actorRole === 'crafter' && $row->status !== 'accepted') {
                return ['error' => 'conflict'];
            }
            $now = now();
            $updates = ['status' => $to, 'updated_at' => $now];
            if ($to === 'accepted') {
                $updates['accepted_at'] = $now;
            }
            if ($to === 'denied') {
                $updates['denied_at'] = $now;
                $updates['crafter_notes'] = $data['reason'] ?? null;
            }
            if ($to === 'completed') {
                $updates['completed_at'] = $now;
            }
            if ($to === 'cancelled') {
                $updates['cancelled_at'] = $now;
            }
            DB::table('crafting_requests')->where('id', $requestId)->update($updates);
            $eventId = (string) Str::uuid();
            DB::table('request_transitions')->insert(['id' => (string) Str::uuid(), 'request_id' => $requestId, 'actor_id' => $request->user()->id, 'from_status' => $row->status, 'to_status' => $to, 'domain_event_id' => $eventId, 'note' => $data['reason'] ?? null, 'created_at' => $now, 'updated_at' => $now]);
            $recipientUserId = $actorRole === 'crafter' ? $row->requester_user_id : $row->crafter_user_id;
            DB::table('outbox_events')->insert(['id' => $eventId, 'event_type' => 'request.'.$to, 'aggregate_id' => $requestId, 'payload' => json_encode(['requestId' => $requestId, 'fromStatus' => $row->status, 'toStatus' => $to, 'recipientUserId' => $recipientUserId]), 'available_at' => $now, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);

            return ['row' => DB::table('crafting_requests')->where('id', $requestId)->first()];
        });
        if (isset($result['error'])) {
            return match ($result['error']) {
                'not_found' => $this->error('not_found', 'Request was not found.', 404),
                'forbidden' => $this->error('forbidden', 'You are not authorized to perform this request action.', 403),
                default => $this->error('invalid_request_transition', 'The request has changed or this transition is not allowed.', 409),
            };
        }

        return $this->ok($this->resource($result['row'], $request->user()->id));
    }

    private function canSee(Request $request, object $row): bool
    {
        $actor = $request->attributes->get('discord_actor');
        if ($actor) {
            // Bot authorization must satisfy both scopes independently. Check the
            // linked guild first and reveal no request existence on mismatch.
            if (! DB::table('guilds')->where('id', $row->guild_id)->where('discord_guild_id', $actor['guildId'])->exists()) {
                return false;
            }

            return in_array($request->user()->id, [$row->requester_user_id, $row->crafter_user_id], true);
        }

        return $row->requester_user_id === $request->user()->id || $row->crafter_user_id === $request->user()->id;
    }

    /** Keep bot list reads within the guild selected by its Discord actor headers. */
    private function scopeToBotGuild(Request $request, Builder $query): void
    {
        $actor = $request->attributes->get('discord_actor');
        if ($actor) {
            $query->whereIn('r.guild_id', DB::table('guilds')
                ->select('id')
                ->where('discord_guild_id', $actor['guildId']));
        }
    }

    private function resource(object $row, string $viewerId, bool $detail = false): array
    {
        $recipe = $row->recipe_id ? DB::table('recipes')->where('id', $row->recipe_id)->first() : null;
        $spellId = $row->spell_id ?? $recipe?->spell_id;
        $spell = $spellId ? DB::table('catalog_spells')->where('game_flavor', $row->game_flavor)->where('spell_id', $spellId)->value('name') : null;
        $resource = [
            'id' => $row->id, 'guildId' => $row->guild_id, 'requesterUserId' => $row->requester_user_id,
            'recipientCharacterName' => $row->recipient_character_name,
            'crafterUserId' => $row->crafter_user_id, 'crafterCharacterId' => $row->crafter_character_id,
            'crafterCharacterName' => DB::table('characters')->where('id', $row->crafter_character_id)->value('character_name'),
            'spellID' => $spellId, 'recipeName' => $spell ?? ($row->recipe_name ?: ($recipe ? 'Recipe #'.$recipe->spell_id : 'Unknown recipe')),
            'quantity' => $row->quantity, 'materialsProvided' => $row->materials_provided, 'status' => $row->status,
            'requesterNotes' => in_array($viewerId, [$row->requester_user_id, $row->crafter_user_id], true) ? $row->requester_notes : null,
            'crafterNotes' => in_array($viewerId, [$row->requester_user_id, $row->crafter_user_id], true) ? $row->crafter_notes : null,
            'createdAt' => $row->created_at, 'acceptedAt' => $row->accepted_at, 'completedAt' => $row->completed_at,
        ];
        if ($detail) {
            $resource['reagents'] = DB::table('request_reagents as rr')->leftJoin('catalog_items as ci', function ($join) use ($row) {
                $join->on('ci.item_id', '=', 'rr.item_id')->where('ci.game_flavor', '=', $row->game_flavor);
            })->where('rr.request_id', $row->id)->select('rr.item_id', 'rr.quantity', 'ci.name')->get()->map(fn ($reagent) => ['itemID' => $reagent->item_id, 'name' => $reagent->name ?? 'Item #'.$reagent->item_id, 'quantity' => $reagent->quantity]);
            $resource['outputs'] = DB::table('request_outputs as ro')->leftJoin('catalog_items as ci', function ($join) use ($row) {
                $join->on('ci.item_id', '=', 'ro.item_id')->where('ci.game_flavor', '=', $row->game_flavor);
            })->where('ro.request_id', $row->id)->select('ro.item_id', 'ro.quantity', 'ro.quality_rank', 'ci.name')->get()->map(fn ($output) => ['itemID' => $output->item_id, 'name' => $output->name ?? 'Item #'.$output->item_id, 'quantity' => $output->quantity, 'qualityRank' => $output->quality_rank]);
            $resource['quality'] = [
                'maxRank' => $row->quality_max_rank,
                'outputs' => DB::table('request_quality_outputs as qo')->leftJoin('catalog_items as ci', function ($join) use ($row) {
                    $join->on('ci.item_id', '=', 'qo.item_id')->where('ci.game_flavor', '=', $row->game_flavor);
                })->where('qo.request_id', $row->id)->select('qo.rank', 'qo.item_id', 'qo.quantity', 'ci.name')->get()->map(fn ($output) => ['rank' => $output->rank, 'itemID' => $output->item_id, 'name' => $output->name ?? 'Item #'.$output->item_id, 'quantity' => $output->quantity]),
            ];
            $resource['reagentSlots'] = DB::table('request_reagent_slots')->where('request_id', $row->id)->orderBy('slot_index')->get()->map(fn ($slot) => [
                'slotIndex' => $slot->slot_index,
                'kind' => $slot->kind,
                'quantity' => $slot->quantity,
                'options' => DB::table('request_slot_options as so')->leftJoin('catalog_items as ci', function ($join) use ($row) {
                    $join->on('ci.item_id', '=', 'so.item_id')->where('ci.game_flavor', '=', $row->game_flavor);
                })->where('so.slot_id', $slot->id)->select('so.item_id', 'ci.name')->get()->map(fn ($option) => ['itemID' => $option->item_id, 'name' => $option->name ?? 'Item #'.$option->item_id]),
            ]);
            $resource['history'] = DB::table('request_transitions')->where('request_id', $row->id)->orderBy('created_at')->get()->map(fn ($transition) => ['from' => $transition->from_status, 'to' => $transition->to_status, 'at' => $transition->created_at, 'note' => $transition->actor_id === $viewerId ? $transition->note : null]);
        }

        return $resource;
    }
}
