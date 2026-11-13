<?php

namespace App\Services;

interface DiscordClient
{
    public function botHasGuildAdministratorAccess(string $guildId): bool;

    public function sendDirectMessage(string $userId, array $message, string $dedupeKey): string;
}
