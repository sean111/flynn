<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DiscordRestClient implements DiscordClient
{
    private function request()
    {
        return Http::baseUrl('https://discord.com/api/v10')->withToken((string) config('services.discord.bot_token'))->acceptJson()->timeout(10);
    }

    public function botHasGuildAdministratorAccess(string $guildId): bool
    {
        if (! preg_match('/^\d{17,20}$/', $guildId) || ! config('services.discord.bot_token')) {
            return false;
        }
        $guild = $this->request()->get("/guilds/{$guildId}");
        $bot = $this->request()->get('/users/@me');
        if (! $bot->successful() || ! $bot->json('id')) {
            return false;
        }
        $member = $this->request()->get("/guilds/{$guildId}/members/".$bot->json('id'));
        $roles = $this->request()->get("/guilds/{$guildId}/roles");
        if (! $guild->successful() || ! $member->successful() || ! $roles->successful()) {
            return false;
        }
        $roleIds = array_merge([$guild->json('id')], $member->json('roles', []));
        foreach ($roles->json() as $role) {
            if (in_array($role['id'] ?? null, $roleIds, true) && (((int) ($role['permissions'] ?? 0)) & 0x8) === 0x8) {
                return true;
            }
        }

        return false;
    }

    public function sendDirectMessage(string $userId, array $message, string $dedupeKey): string
    {
        if (! preg_match('/^\d{17,20}$/', $userId)) {
            throw new RuntimeException('Invalid Discord recipient.');
        }
        $channel = $this->request()->post('/users/@me/channels', ['recipient_id' => $userId]);
        if (! $channel->successful() || ! $channel->json('id')) {
            throw new RuntimeException('Discord direct-message channel could not be opened.');
        }
        $message['nonce'] = substr(hash('sha256', $dedupeKey), 0, 24);
        $message['enforce_nonce'] = true;
        $response = $this->request()->post('/channels/'.$channel->json('id').'/messages', $message);
        if (! $response->successful()) {
            throw new RuntimeException('Discord notification delivery failed with HTTP '.$response->status().'.');
        }

        return (string) $response->json('id');
    }
}
