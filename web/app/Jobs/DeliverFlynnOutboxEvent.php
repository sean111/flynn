<?php

namespace App\Jobs;

use App\Services\DiscordClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DeliverFlynnOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public array $backoff = [10, 30, 90, 300];

    public function __construct(public readonly string $eventId) {}

    public function handle(DiscordClient $discord): void
    {
        $event = DB::table('outbox_events')->where('id', $this->eventId)->first();
        if (! $event) {
            return;
        }
        $request = DB::table('crafting_requests')->where('id', $event->aggregate_id)->first();
        if (! $request) {
            return;
        }
        $eventPayload = json_decode($event->payload, true) ?: [];
        $recipientUserIds = [$eventPayload['recipientUserId'] ?? ($event->event_type === 'request.created' ? $request->crafter_user_id : $request->requester_user_id)];
        if ($event->event_type === 'request.accepted') {
            // The requester receives the status notification; the crafter also
            // needs the Complete action, which must not be sent to the requester.
            $recipientUserIds[] = $request->crafter_user_id;
        }
        foreach (array_unique($recipientUserIds) as $recipientUserId) {
            $recipient = DB::table('discord_identities')->where('user_id', $recipientUserId)->first();
            if (! $recipient) {
                DB::table('notification_deliveries')->updateOrInsert(
                    ['outbox_event_id' => $event->id, 'recipient_id' => 'user:'.$recipientUserId],
                    ['id' => (string) Str::uuid(), 'status' => 'unavailable', 'last_error' => 'Recipient has no linked Discord identity.', 'created_at' => now(), 'updated_at' => now()],
                );

                continue;
            }
            $delivery = DB::table('notification_deliveries')->where('outbox_event_id', $event->id)->where('recipient_id', $recipient->discord_user_id)->first();
            if ($delivery?->status === 'delivered') {
                continue;
            }
            $deliveryId = $delivery?->id ?? (string) Str::uuid();
            DB::table('notification_deliveries')->updateOrInsert(
                ['outbox_event_id' => $event->id, 'recipient_id' => $recipient->discord_user_id],
                ['id' => $deliveryId, 'status' => 'sending', 'last_error' => null, 'created_at' => $delivery?->created_at ?? now(), 'updated_at' => now()],
            );
            $recipe = $request->recipe_id ? DB::table('recipes')->where('id', $request->recipe_id)->first() : null;
            $spellId = $request->spell_id ?? $recipe?->spell_id;
            $recipeName = $spellId ? DB::table('catalog_spells')->where('game_flavor', $request->game_flavor)->where('spell_id', $spellId)->value('name') : null;
            $crafterCharacter = DB::table('characters')->where('id', $request->crafter_character_id)->first();
            $statusLabel = $eventPayload['toStatus'] ?? str_replace('request.', '', $event->event_type);
            $content = $event->event_type === 'request.created' ? 'A new Flynn crafting request is waiting for you.' : 'A Flynn crafting request changed status to **'.$statusLabel.'**.';
            $message = [
                'content' => $content,
                'embeds' => [[
                    'title' => ($recipeName ?: ($request->recipe_name ?: 'Recipe #'.($spellId ?? 'unknown'))).' × '.$request->quantity,
                    'description' => 'Guild request status: **'.$statusLabel.'**'.($request->recipient_character_name ? "\nFor: {$request->recipient_character_name}" : ''),
                    'color' => 14590752,
                    'fields' => [
                        ['name' => 'Assigned Crafter', 'value' => $crafterCharacter?->character_name ?? 'Unavailable', 'inline' => true],
                        ['name' => 'Materials', 'value' => ucfirst($request->materials_provided), 'inline' => true],
                        ['name' => 'Reagents', 'value' => mb_substr($this->reagentsText($request->id), 0, 1000), 'inline' => false],
                        ['name' => 'Notes', 'value' => mb_substr($recipientUserId === $request->requester_user_id ? ($request->crafter_notes ?: $request->requester_notes) : $request->requester_notes, 0, 1000) ?: '—', 'inline' => false],
                    ],
                ]],
            ];
            if ($event->event_type === 'request.created' || ($event->event_type === 'request.accepted' && $recipientUserId === $request->crafter_user_id)) {
                $message['components'] = [['type' => 1, 'components' => [
                    ...($event->event_type === 'request.created' ? [
                        ['type' => 2, 'style' => 3, 'label' => 'Accept', 'custom_id' => 'flynn:request:accept:'.$request->id],
                        ['type' => 2, 'style' => 4, 'label' => 'Deny', 'custom_id' => 'flynn:request:deny:'.$request->id],
                    ] : []),
                    ...($event->event_type === 'request.accepted' ? [
                        ['type' => 2, 'style' => 1, 'label' => 'Complete', 'custom_id' => 'flynn:request:complete:'.$request->id],
                    ] : []),
                ]]];
            }
            $discordMessageId = $discord->sendDirectMessage($recipient->discord_user_id, $message, $event->id.':'.$recipient->discord_user_id);
            DB::table('notification_deliveries')->where('id', $deliveryId)->update(['status' => 'delivered', 'delivery_id' => $discordMessageId, 'delivered_at' => now(), 'updated_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notification_deliveries')->where('outbox_event_id', $this->eventId)->where('status', 'sending')->update(['status' => 'failed', 'last_error' => 'Discord delivery failed after retries.', 'updated_at' => now()]);
        DB::table('outbox_events')->where('id', $this->eventId)->update(['last_error' => 'Discord delivery failed after retries.', 'updated_at' => now()]);
    }

    private function reagentsText(string $requestId): string
    {
        $flavor = DB::table('crafting_requests')->where('id', $requestId)->value('game_flavor');
        $query = DB::table('request_reagents as rr')->leftJoin('catalog_items as ci', function ($join) use ($flavor) {
            $join->on('ci.item_id', '=', 'rr.item_id')->where('ci.game_flavor', '=', $flavor);
        })->where('rr.request_id', $requestId)->select('rr.item_id', 'rr.quantity', 'ci.name')->get();
        if ($query->isEmpty()) {
            return 'No known reagent data.';
        }

        return $query->map(fn ($row) => ($row->name ?: 'Item #'.$row->item_id).' × '.$row->quantity)->implode("\n");
    }
}
