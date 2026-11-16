<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DiscordClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GuildWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_forever_is_a_valid_distinct_guild_flavor(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $guild = $this->postJson('/api/v1/guilds', [
            'name' => 'Camelot Crafters', 'gameFlavor' => 'forever', 'region' => 'us', 'realm' => 'Camelot',
        ])->assertCreated()->assertJsonPath('data.gameFlavor', 'forever')->json('data');

        $this->assertDatabaseHas('guilds', ['id' => $guild['id'], 'game_flavor' => 'forever']);
    }

    public function test_invite_applications_start_pending_and_owners_review_and_manage_officers(): void
    {
        $owner = User::factory()->create();
        $applicant = User::factory()->create();
        Sanctum::actingAs($owner);
        $guild = $this->postJson('/api/v1/guilds', ['name' => 'Anvil Circle', 'gameFlavor' => 'classic', 'region' => 'eu', 'realm' => 'Gehennas'])->assertCreated()->json('data');
        $ownerMembership = DB::table('guild_memberships')->where('guild_id', $guild['id'])->where('user_id', $owner->id)->first();
        $this->assertSame('owner', $ownerMembership->role);
        $invite = $this->postJson('/api/v1/guilds/'.$guild['id'].'/invites')->assertCreated()->json('data.inviteToken');

        Sanctum::actingAs($applicant);
        $application = $this->postJson('/api/v1/guild-invites/'.$invite.'/applications')->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/guilds/'.$guild['id'].'/members/'.$application['id'].'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        Sanctum::actingAs($applicant);
        $this->postJson('/api/v1/guilds/'.$guild['id'].'/applications')->assertCreated()->assertJsonPath('data.status', 'pending');
        $applicationId = DB::table('guild_memberships')->where('guild_id', $guild['id'])->where('user_id', $applicant->id)->value('id');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/guilds/'.$guild['id'].'/members/'.$applicationId.'/approve')->assertOk();
        $this->patchJson('/api/v1/guilds/'.$guild['id'].'/members/'.$applicationId.'/role', ['role' => 'officer'])->assertOk()->assertJsonPath('data.role', 'officer');
        Sanctum::actingAs($applicant);
        $this->patchJson('/api/v1/guilds/'.$guild['id'].'/members/'.$applicationId.'/role', ['role' => 'member'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/v1/guilds/'.$guild['id'].'/members/'.$applicationId.'/role', ['role' => 'member'])->assertOk()->assertJsonPath('data.role', 'member');
    }

    public function test_discord_guild_link_requires_verified_bot_administrator_access(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $guild = $this->postJson('/api/v1/guilds', ['name' => 'Discord Anvils', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->assertCreated()->json('data');
        $discord = $this->mock(DiscordClient::class);
        $discord->shouldReceive('botHasGuildAdministratorAccess')->twice()->with('123456789012345678')->andReturn(false, true);

        $this->postJson('/api/v1/guilds/'.$guild['id'].'/discord-link/verify', ['discordGuildID' => '123456789012345678'])->assertUnprocessable();
        $this->postJson('/api/v1/guilds/'.$guild['id'].'/discord-link/verify', ['discordGuildID' => '123456789012345678'])->assertOk()->assertJsonPath('data.discordGuildID', '123456789012345678');
    }
}
