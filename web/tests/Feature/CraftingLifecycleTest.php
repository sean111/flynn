<?php

namespace Tests\Feature;

use App\Jobs\DeliverFlynnOutboxEvent;
use App\Models\User;
use App\Services\DiscordClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class CraftingLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_forever_recipe_search_does_not_include_retail_crafters(): void
    {
        $owner = User::factory()->create();
        $retailCrafter = User::factory()->create();
        $foreverCrafter = User::factory()->create();
        $guildIds = [];

        foreach ([['retail', $retailCrafter, 'Retailsmith'], ['forever', $foreverCrafter, 'Camelotsmith']] as [$flavor, $crafter, $characterName]) {
            Sanctum::actingAs($owner);
            $guild = $this->postJson('/api/v1/guilds', [
                'name' => ucfirst($flavor).' Search Hall', 'gameFlavor' => $flavor, 'region' => 'us', 'realm' => 'Area 52',
            ])->assertCreated()->json('data');
            $guildIds[$flavor] = $guild['id'];

            Sanctum::actingAs($crafter);
            $application = $this->postJson('/api/v1/guilds/'.$guild['id'].'/applications')->assertCreated()->json('data');
            $this->createEligibleCrafter($crafter, $guild['id'], $characterName, $flavor);

            Sanctum::actingAs($owner);
            $this->postJson('/api/v1/guilds/'.$guild['id'].'/members/'.$application['id'].'/approve')->assertOk();
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/guilds/'.$guildIds['forever'].'/recipes')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.crafters.0.characterName', 'Camelotsmith');
        $this->getJson('/api/v1/guilds/'.$guildIds['retail'].'/recipes')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.crafters.0.characterName', 'Retailsmith');
    }

    public function test_approved_guild_members_can_route_and_complete_a_crafting_request(): void
    {
        $requester = User::factory()->create();
        $crafter = User::factory()->create();
        Sanctum::actingAs($requester);
        $guildResponse = $this->postJson('/api/v1/guilds', ['name' => 'The Golden Anvil', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52']);
        $guildResponse->assertCreated();
        $guildId = $guildResponse->json('data.id');

        Sanctum::actingAs($crafter);
        $application = $this->postJson('/api/v1/guilds/'.$guildId.'/applications');
        $application->assertCreated()->assertJsonPath('data.status', 'pending');
        $membershipId = $application->json('data.id');
        $characterId = $this->createEligibleCrafter($crafter, $guildId);

        Sanctum::actingAs($requester);
        $this->getJson('/api/v1/guilds/'.$guildId.'/recipes')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/guilds/'.$guildId.'/members/'.$membershipId.'/approve')->assertOk();

        $this->getJson('/api/v1/guilds/'.$guildId.'/recipes')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/guilds/'.$guildId.'/cart/items', [
            'spellID' => 10001, 'quantity' => 2, 'crafterCharacterId' => $characterId, 'materialsProvided' => 'requester',
            'recipientCharacterName' => 'Testmage', 'notes' => 'Meet by the forge.',
        ])->assertCreated();
        $this->assertDatabaseHas('cart_items', ['quantity' => 2, 'materials_provided' => 'requester']);

        $checkout = $this->withHeader('Idempotency-Key', 'request-test-key-001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout');
        $checkout->assertCreated()->assertJsonPath('data.createdCount', 1);
        $requestId = $checkout->json('data.requestIds.0');
        $this->withHeader('Idempotency-Key', 'request-test-key-001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertOk()->assertJsonPath('data.requestIds.0', $requestId);
        $this->assertDatabaseHas('crafting_requests', ['id' => $requestId, 'status' => 'pending', 'spell_id' => 10001, 'quantity' => 2]);
        $this->assertDatabaseHas('request_reagents', ['request_id' => $requestId, 'item_id' => 9001, 'quantity' => 6]);
        $this->assertDatabaseHas('request_outputs', ['request_id' => $requestId, 'item_id' => 9002, 'quantity' => 2]);
        $this->getJson('/api/v1/me/requests/outgoing')->assertOk()->assertJsonPath('data.0.reagents.0.itemID', 9001)->assertJsonPath('data.0.reagents.0.quantity', 6);

        $this->postJson('/api/v1/requests/'.$requestId.'/accept')->assertForbidden();
        Sanctum::actingAs($crafter);
        $this->postJson('/api/v1/requests/'.$requestId.'/cancel')->assertForbidden();
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/requests/'.$requestId.'/accept')->assertForbidden();
        Sanctum::actingAs($crafter);
        $this->postJson('/api/v1/requests/'.$requestId.'/accept')->assertOk()->assertJsonPath('data.status', 'accepted');
        DB::table('discord_identities')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $requester->id, 'discord_user_id' => '323456789012345678',
            'handle' => 'requester', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $acceptedEvent = DB::table('outbox_events')->where('aggregate_id', $requestId)->where('event_type', 'request.accepted')->first();
        $this->postJson('/api/v1/requests/'.$requestId.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        DB::table('discord_identities')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $crafter->id, 'discord_user_id' => '423456789012345678',
            'handle' => 'crafter', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $completedEvent = DB::table('outbox_events')->where('aggregate_id', $requestId)->where('event_type', 'request.completed')->first();
        $discord = $this->mock(DiscordClient::class);
        $acceptedMessage = null;
        $crafterAcceptedMessage = null;
        $completedMessage = null;
        $discord->shouldReceive('sendDirectMessage')->once()->with(
            '323456789012345678',
            Mockery::on(function (array $message) use (&$acceptedMessage) {
                $acceptedMessage = $message;

                return true;
            }),
            $acceptedEvent->id.':323456789012345678',
        )->andReturn('discord-message-001');
        $discord->shouldReceive('sendDirectMessage')->once()->with(
            '423456789012345678',
            Mockery::on(function (array $message) use (&$crafterAcceptedMessage) {
                $crafterAcceptedMessage = $message;

                return true;
            }),
            $acceptedEvent->id.':423456789012345678',
        )->andReturn('discord-message-003');
        $discord->shouldReceive('sendDirectMessage')->once()->with(
            '323456789012345678',
            Mockery::on(function (array $message) use (&$completedMessage) {
                $completedMessage = $message;

                return true;
            }),
            $completedEvent->id.':323456789012345678',
        )->andReturn('discord-message-002');
        $notificationJob = new DeliverFlynnOutboxEvent($acceptedEvent->id);
        $notificationJob->handle($discord);
        $notificationJob->handle($discord);
        (new DeliverFlynnOutboxEvent($completedEvent->id))->handle($discord);
        $this->assertStringContainsString('accepted', $acceptedMessage['content']);
        $this->assertStringContainsString('**accepted**', $acceptedMessage['embeds'][0]['description']);
        $this->assertArrayNotHasKey('components', $acceptedMessage);
        $this->assertSame('Complete', $crafterAcceptedMessage['components'][0]['components'][0]['label']);
        $this->assertStringContainsString('**accepted**', $crafterAcceptedMessage['embeds'][0]['description']);
        $this->assertStringContainsString('completed', $completedMessage['content']);
        $this->assertStringContainsString('**completed**', $completedMessage['embeds'][0]['description']);
        $this->assertStringContainsString('9001', $acceptedMessage['embeds'][0]['fields'][2]['value']);
        $this->assertDatabaseHas('notification_deliveries', ['outbox_event_id' => $acceptedEvent->id, 'status' => 'delivered', 'delivery_id' => 'discord-message-001']);
        $this->assertDatabaseHas('notification_deliveries', ['outbox_event_id' => $completedEvent->id, 'status' => 'delivered', 'delivery_id' => 'discord-message-002']);

        $this->assertDatabaseHas('request_transitions', ['request_id' => $requestId, 'from_status' => 'pending', 'to_status' => 'accepted']);
        $this->assertDatabaseHas('request_transitions', ['request_id' => $requestId, 'from_status' => 'accepted', 'to_status' => 'completed']);
        $this->assertSame(3, DB::table('outbox_events')->where('aggregate_id', $requestId)->count());
        $this->assertSame(0, DB::table('cart_items')->count());

        Sanctum::actingAs($requester);
        $this->addCartItem($guildId, $characterId);
        $this->withHeader('Idempotency-Key', 'request-test-key-001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->withHeader('Idempotency-Key', 'request-deny-key')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertCreated();
        $deniedId = DB::table('crafting_requests')->where('requester_user_id', $requester->id)->where('status', 'pending')->value('id');
        Sanctum::actingAs($crafter);
        $this->postJson('/api/v1/requests/'.$deniedId.'/deny', ['reason' => 'Out of stock'])->assertOk()->assertJsonPath('data.status', 'denied');
        Sanctum::actingAs($requester);
        $this->getJson('/api/v1/requests/'.$deniedId)->assertOk()->assertJsonPath('data.crafterNotes', 'Out of stock');

        $this->addCartRequest($guildId, $characterId, 'request-cancel-key');
        $cancelledId = DB::table('crafting_requests')->where('requester_user_id', $requester->id)->where('status', 'pending')->value('id');
        $this->postJson('/api/v1/requests/'.$cancelledId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_shared_spell_directory_and_cart_resolve_the_recipe_owned_by_the_selected_crafter(): void
    {
        $requester = User::factory()->create();
        $firstCrafter = User::factory()->create();
        $secondCrafter = User::factory()->create();
        Sanctum::actingAs($requester);
        $guildId = $this->postJson('/api/v1/guilds', ['name' => 'Shared Knowledge', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->json('data.id');

        $characters = [];
        foreach ([[$firstCrafter, 'Firstsmith'], [$secondCrafter, 'Secondsmith']] as [$owner, $name]) {
            Sanctum::actingAs($owner);
            $membership = $this->postJson('/api/v1/guilds/'.$guildId.'/applications')->assertCreated()->json('data.id');
            Sanctum::actingAs($requester);
            $this->postJson('/api/v1/guilds/'.$guildId.'/members/'.$membership.'/approve')->assertOk();
            $characters[] = $this->createEligibleCrafter($owner, $guildId, $name);
        }

        Sanctum::actingAs($requester);
        $directory = $this->getJson('/api/v1/guilds/'.$guildId.'/recipes')->assertOk();
        $directory->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.crafters');
        $this->getJson('/api/v1/guilds/'.$guildId.'/recipes/10001/crafters')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/guilds/'.$guildId.'/cart/items', [
            'spellID' => 10001, 'quantity' => 1, 'crafterCharacterId' => $characters[1], 'materialsProvided' => 'requester',
        ])->assertCreated();
        $this->getJson('/api/v1/guilds/'.$guildId.'/cart')->assertOk()->assertJsonPath('data.items.0.crafterCharacterId', $characters[1])->assertJsonCount(2, 'data.items.0.crafters');
        $checkout = $this->withHeader('Idempotency-Key', 'shared-spell-checkout')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertCreated();
        $request = DB::table('crafting_requests')->where('id', $checkout->json('data.requestIds.0'))->first();
        $this->assertSame($characters[1], $request->crafter_character_id);
        $this->assertDatabaseHas('recipes', ['id' => $request->recipe_id, 'profession_id' => DB::table('character_professions')->where('character_id', $characters[1])->value('id')]);
    }

    public function test_checkout_idempotency_key_is_scoped_and_fingerprinted(): void
    {
        $requester = User::factory()->create();
        $crafter = User::factory()->create();
        Sanctum::actingAs($requester);
        $guildId = $this->postJson('/api/v1/guilds', ['name' => 'Key Guild One', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->json('data.id');
        $otherGuildId = $this->postJson('/api/v1/guilds', ['name' => 'Key Guild Two', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->json('data.id');
        Sanctum::actingAs($crafter);
        $membershipId = $this->postJson('/api/v1/guilds/'.$guildId.'/applications')->assertCreated()->json('data.id');
        $characterId = $this->createEligibleCrafter($crafter, $guildId);
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/guilds/'.$guildId.'/members/'.$membershipId.'/approve')->assertOk();
        $this->addCartItem($guildId, $characterId);
        $this->withHeader('Idempotency-Key', 'scoped-key-0001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertCreated();

        // A retry against the now-empty same cart is replay-safe; a new cart input
        // and a different guild are conflicts rather than unrelated replays.
        $this->withHeader('Idempotency-Key', 'scoped-key-0001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertOk();
        $this->addCartItem($guildId, $characterId);
        $this->withHeader('Idempotency-Key', 'scoped-key-0001')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->withHeader('Idempotency-Key', 'scoped-key-0001')->postJson('/api/v1/guilds/'.$otherGuildId.'/cart/checkout')->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_bot_request_access_requires_participation_and_the_matching_linked_guild(): void
    {
        $requester = User::factory()->create();
        $crafter = User::factory()->create();
        $outsider = User::factory()->create();
        Sanctum::actingAs($requester);
        $guildId = $this->postJson('/api/v1/guilds', ['name' => 'Request Boundary', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->json('data.id');
        DB::table('guilds')->where('id', $guildId)->update(['discord_guild_id' => '223456789012345678']);
        Sanctum::actingAs($crafter);
        $crafterMembership = $this->postJson('/api/v1/guilds/'.$guildId.'/applications')->assertCreated()->json('data.id');
        $crafterCharacter = $this->createEligibleCrafter($crafter, $guildId);
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/guilds/'.$guildId.'/members/'.$crafterMembership.'/approve')->assertOk();
        Sanctum::actingAs($outsider);
        $outsiderMembership = $this->postJson('/api/v1/guilds/'.$guildId.'/applications')->assertCreated()->json('data.id');
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/guilds/'.$guildId.'/members/'.$outsiderMembership.'/approve')->assertOk();
        $this->addCartItem($guildId, $crafterCharacter);
        $requestId = $this->withHeader('Idempotency-Key', 'bot-boundary-key')->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertCreated()->json('data.requestIds.0');

        $otherGuild = $this->postJson('/api/v1/guilds', ['name' => 'Other Linked Guild', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->assertCreated()->json('data.id');
        DB::table('guilds')->where('id', $otherGuild)->update(['discord_guild_id' => '323456789012345678']);
        DB::table('discord_identities')->insert(['id' => (string) Str::uuid(), 'user_id' => $requester->id, 'discord_user_id' => '123456789012345678', 'handle' => 'requester', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('discord_identities')->insert(['id' => (string) Str::uuid(), 'user_id' => $outsider->id, 'discord_user_id' => '423456789012345678', 'handle' => 'outsider', 'created_at' => now(), 'updated_at' => now()]);
        config()->set('services.flynn.bot_token', 'request-boundary-bot-token');
        config()->set('services.flynn.bot_service_abilities', ['requests:read', 'requests:write']);

        $this->withToken('request-boundary-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '123456789012345678', 'X-Flynn-Discord-Guild-ID' => '323456789012345678',
        ])->getJson('/api/v1/requests/'.$requestId)->assertNotFound();
        $this->withToken('request-boundary-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '123456789012345678', 'X-Flynn-Discord-Guild-ID' => '323456789012345678',
        ])->postJson('/api/v1/requests/'.$requestId.'/complete')->assertNotFound();

        $this->withToken('request-boundary-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '423456789012345678', 'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ])->getJson('/api/v1/requests/'.$requestId)->assertNotFound();
        $this->withToken('request-boundary-bot-token')->withHeaders([
            'X-Flynn-Discord-User-ID' => '423456789012345678', 'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ])->postJson('/api/v1/requests/'.$requestId.'/accept')->assertNotFound();
    }

    public function test_bot_request_lists_are_scoped_to_its_linked_guild_before_cursor_pagination(): void
    {
        $actor = User::factory()->create();
        $discordUserId = '123456789012345678';
        $guildAId = $this->createRequestListGuild($actor, 'Guild A', '223456789012345678', $discordUserId);
        $guildBId = $this->createRequestListGuild($actor, 'Guild B', '323456789012345678', $discordUserId);
        $characterId = (string) Str::uuid();
        DB::table('characters')->insert([
            'id' => $characterId, 'owner_id' => $actor->id, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'character_name' => 'Crossguildsmith', 'guid' => 'Player-crossguildsmith', 'faction' => 'alliance', 'class' => 'WARRIOR',
            'captured_at' => 1794528000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $guildARequestIds = $this->createRequestListRows($actor, $characterId, $guildAId, 52, 'Guild A');
        $guildBRequestIds = $this->createRequestListRows($actor, $characterId, $guildBId, 3, 'Guild B');
        config()->set('services.flynn.bot_token', 'request-list-bot-token');
        config()->set('services.flynn.bot_service_abilities', ['requests:read']);
        $headers = [
            'X-Flynn-Discord-User-ID' => $discordUserId,
            'X-Flynn-Discord-Guild-ID' => '223456789012345678',
        ];

        foreach (['incoming', 'outgoing'] as $list) {
            $path = '/api/v1/me/requests/'.$list;
            $firstPage = $this->withToken('request-list-bot-token')->withHeaders($headers)->getJson($path)->assertOk();
            $this->assertCount(50, $firstPage->json('data'));
            $this->assertNotNull($firstPage->json('nextCursor'));
            $this->assertSame([], array_values(array_intersect($guildBRequestIds, array_column($firstPage->json('data'), 'id'))));
            $this->assertSame($guildARequestIds[0], $firstPage->json('data.0.id'));

            $cursor = $firstPage->json('nextCursor');
            $secondPage = $this->withToken('request-list-bot-token')->withHeaders($headers)
                ->getJson($path.'?cursor='.rawurlencode($cursor))->assertOk();
            $this->assertCount(2, $secondPage->json('data'));
            $this->assertNull($secondPage->json('nextCursor'));
            $this->assertSame([], array_values(array_intersect($guildBRequestIds, array_column($secondPage->json('data'), 'id'))));
            $this->assertCount(52, array_merge($firstPage->json('data'), $secondPage->json('data')));
        }

        // A browser participant remains able to see their requests across guilds.
        $this->flushHeaders();
        Sanctum::actingAs($actor);
        $browserPage = $this->getJson('/api/v1/me/requests/outgoing')->assertOk();
        $this->assertCount(50, $browserPage->json('data'));
        $this->assertNotNull($browserPage->json('nextCursor'));
        $this->assertNotSame([], array_values(array_intersect($guildBRequestIds, array_column($browserPage->json('data'), 'id'))));
        $browserSecondPage = $this->getJson('/api/v1/me/requests/outgoing?cursor='.rawurlencode($browserPage->json('nextCursor')))->assertOk();
        $this->assertCount(5, $browserSecondPage->json('data'));
        $this->assertNull($browserSecondPage->json('nextCursor'));
        $this->assertCount(55, array_merge($browserPage->json('data'), $browserSecondPage->json('data')));
    }

    private function createRequestListGuild(User $actor, string $name, string $discordGuildId, string $discordUserId): string
    {
        $guildId = (string) Str::uuid();
        DB::table('guilds')->insert([
            'id' => $guildId, 'name' => $name, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'owner_id' => $actor->id, 'discord_guild_id' => $discordGuildId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('guild_memberships')->insert([
            'id' => (string) Str::uuid(), 'guild_id' => $guildId, 'user_id' => $actor->id, 'role' => 'owner', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! DB::table('discord_identities')->where('user_id', $actor->id)->exists()) {
            DB::table('discord_identities')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $actor->id, 'discord_user_id' => $discordUserId,
                'handle' => 'crossguild-actor', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $guildId;
    }

    /** @return list<string> */
    private function createRequestListRows(User $actor, string $characterId, string $guildId, int $count, string $guildName): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $id = (string) Str::uuid();
            // Guild B rows sort first, making an unscoped first page leak immediately.
            $createdAt = $guildName === 'Guild B' ? now()->subSeconds($index) : now()->subSeconds(100 + $index);
            DB::table('crafting_requests')->insert([
                'id' => $id, 'guild_id' => $guildId, 'game_flavor' => 'retail', 'requester_user_id' => $actor->id,
                'recipient_character_name' => null, 'crafter_user_id' => $actor->id, 'crafter_character_id' => $characterId,
                'recipe_id' => null, 'spell_id' => 10001, 'skill_line_id' => 164, 'recipe_name' => $guildName.' request '.$index,
                'quality_max_rank' => null, 'quantity' => 1, 'materials_provided' => 'requester',
                'requester_notes' => $guildName.' private note '.$index, 'crafter_notes' => null, 'status' => 'pending',
                'accepted_at' => null, 'denied_at' => null, 'completed_at' => null, 'cancelled_at' => null,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            if ($guildName === 'Guild B') {
                DB::table('request_reagents')->insert([
                    'id' => (string) Str::uuid(), 'request_id' => $id, 'item_id' => 99999, 'quantity' => 1,
                    'created_at' => $createdAt, 'updated_at' => $createdAt,
                ]);
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function createEligibleCrafter(User $crafter, string $guildId, string $characterName = 'Smithy', string $flavor = 'retail'): string
    {
        $characterId = (string) Str::uuid();
        $professionId = (string) Str::uuid();
        $recipeId = (string) Str::uuid();
        DB::table('characters')->insert([
            'id' => $characterId, 'owner_id' => $crafter->id, 'game_flavor' => $flavor, 'region' => 'us', 'realm' => 'Area 52',
            'character_name' => $characterName, 'guid' => 'Player-1234-'.strtoupper($characterName), 'faction' => 'alliance', 'class' => 'WARRIOR',
            'captured_at' => 1794528000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('character_guild')->insert(['character_id' => $characterId, 'guild_id' => $guildId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('character_professions')->insert([
            'id' => $professionId, 'character_id' => $characterId, 'skill_line_id' => 164, 'current_skill' => 100, 'max_skill' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipes')->insert([
            'id' => $recipeId, 'game_flavor' => $flavor, 'profession_id' => $professionId, 'spell_id' => 10001,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recipe_outputs')->insert(['id' => (string) Str::uuid(), 'recipe_id' => $recipeId, 'item_id' => 9002, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recipe_reagents')->insert(['id' => (string) Str::uuid(), 'recipe_id' => $recipeId, 'item_id' => 9001, 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);

        return $characterId;
    }

    private function addCartRequest(string $guildId, string $characterId, string $key): void
    {
        $this->addCartItem($guildId, $characterId);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/guilds/'.$guildId.'/cart/checkout')->assertCreated();
    }

    private function addCartItem(string $guildId, string $characterId): void
    {
        $this->postJson('/api/v1/guilds/'.$guildId.'/cart/items', [
            'spellID' => 10001, 'quantity' => 1, 'crafterCharacterId' => $characterId, 'materialsProvided' => 'partial',
        ])->assertCreated();
    }
}
