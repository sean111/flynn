<?php

namespace App\Console\Commands;

use App\Jobs\DeliverFlynnOutboxEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:relay-flynn-outbox')]
#[Description('Dispatch due transactional outbox events to the notification queue')]
final class RelayFlynnOutbox extends Command
{
    private const RETRY_DELAYS = [10, 30, 90, 300];

    public function handle(): int
    {
        $events = DB::transaction(function () {
            $now = now();
            $events = DB::table('outbox_events')
                ->whereNull('published_at')
                ->where('available_at', '<=', $now)
                ->where('attempts', '<', 5)
                ->where(function ($query) use ($now) {
                    $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $now->copy()->subMinutes(5));
                })
                ->orderBy('created_at')
                ->limit(100)
                ->lockForUpdate()
                ->get();
            foreach ($events as $event) {
                DB::table('outbox_events')->where('id', $event->id)->update(['claimed_at' => $now, 'attempts' => $event->attempts + 1, 'updated_at' => $now]);
            }

            return $events;
        });

        foreach ($events as $event) {
            try {
                DeliverFlynnOutboxEvent::dispatch($event->id)->onQueue('notifications');
                DB::table('outbox_events')->where('id', $event->id)->update(['published_at' => now(), 'claimed_at' => null, 'last_error' => null, 'updated_at' => now()]);
            } catch (Throwable) {
                $attempt = min((int) $event->attempts, count(self::RETRY_DELAYS)) - 1;
                $delay = self::RETRY_DELAYS[max(0, $attempt)];
                DB::table('outbox_events')->where('id', $event->id)->update([
                    'claimed_at' => null,
                    'available_at' => now()->addSeconds($delay),
                    'last_error' => 'Notification job could not be queued.',
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info('Dispatched '.$events->count().' Flynn outbox event(s).');

        return self::SUCCESS;
    }
}
