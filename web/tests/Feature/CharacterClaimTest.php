<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CharacterClaimTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_uploader_can_explicitly_claim_an_unclaimed_import(): void
    {
        $uploader = User::factory()->create();
        $characterId = $this->createImport($uploader);
        Sanctum::actingAs($uploader);

        $response = $this->postJson('/api/v1/characters/'.$characterId.'/claim');

        $response->assertOk()->assertJsonPath('data.status', 'claimed');
        $this->assertDatabaseHas('characters', ['id' => $characterId, 'owner_id' => $uploader->id]);
    }

    public function test_another_uploader_cannot_claim_a_character_already_owned(): void
    {
        $owner = User::factory()->create();
        $uploader = User::factory()->create();
        $characterId = $this->createImport($uploader, $owner->id);
        Sanctum::actingAs($uploader);

        $response = $this->postJson('/api/v1/characters/'.$characterId.'/claim');

        $response->assertConflict()->assertJsonPath('error.code', 'character_ownership_conflict');
        $this->assertDatabaseHas('characters', ['id' => $characterId, 'owner_id' => $owner->id]);
    }

    public function test_unrelated_user_cannot_claim_an_unclaimed_character(): void
    {
        $uploader = User::factory()->create();
        $intruder = User::factory()->create();
        $characterId = $this->createImport($uploader);
        Sanctum::actingAs($intruder);

        $response = $this->postJson('/api/v1/characters/'.$characterId.'/claim');

        $response->assertForbidden();
        $this->assertDatabaseHas('characters', ['id' => $characterId, 'owner_id' => null]);
    }

    public function test_character_owner_can_assign_only_matching_guilds_and_delete_the_roster_entry(): void
    {
        $owner = User::factory()->create();
        $characterId = $this->createImport($owner, $owner->id);
        Sanctum::actingAs($owner);
        $guild = $this->postJson('/api/v1/guilds', ['name' => 'Matching Hall', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->assertCreated()->json('data');
        $mismatchedGuild = $this->postJson('/api/v1/guilds', ['name' => 'Distant Hall', 'gameFlavor' => 'classic', 'region' => 'us', 'realm' => 'Area 52'])->assertCreated()->json('data');

        $this->putJson('/api/v1/characters/'.$characterId.'/guilds/'.$guild['id'])->assertOk();
        $this->assertDatabaseHas('character_guild', ['character_id' => $characterId, 'guild_id' => $guild['id']]);
        $this->putJson('/api/v1/characters/'.$characterId.'/guilds/'.$mismatchedGuild['id'])->assertUnprocessable()->assertJsonPath('error.code', 'character_guild_mismatch');
        $this->deleteJson('/api/v1/characters/'.$characterId)->assertNoContent();
        $this->assertDatabaseMissing('characters', ['id' => $characterId]);
    }

    public function test_deleting_a_character_used_by_a_request_preserves_its_guild_association(): void
    {
        $owner = User::factory()->create();
        $characterId = $this->createImport($owner, $owner->id);
        Sanctum::actingAs($owner);
        $guild = $this->postJson('/api/v1/guilds', ['name' => 'Request Hall', 'gameFlavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52'])->assertCreated()->json('data');
        $this->putJson('/api/v1/characters/'.$characterId.'/guilds/'.$guild['id'])->assertOk();
        DB::table('crafting_requests')->insert([
            'id' => (string) Str::uuid(), 'guild_id' => $guild['id'], 'game_flavor' => 'retail',
            'requester_user_id' => $owner->id, 'crafter_user_id' => $owner->id, 'crafter_character_id' => $characterId,
            'spell_id' => 10001, 'skill_line_id' => 164, 'quantity' => 1, 'materials_provided' => 'requester',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/v1/characters/'.$characterId)->assertConflict()->assertJsonPath('error.code', 'character_in_use');

        $this->assertDatabaseHas('characters', ['id' => $characterId, 'owner_id' => $owner->id]);
        $this->assertDatabaseHas('character_guild', ['character_id' => $characterId, 'guild_id' => $guild['id']]);
    }

    private function createImport(User $uploader, ?string $ownerId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('characters')->insert([
            'id' => $id, 'owner_id' => $ownerId, 'game_flavor' => 'retail', 'region' => 'us', 'realm' => 'Area 52',
            'character_name' => 'Examplemage', 'guid' => 'Player-3676-0ABCDEF0', 'faction' => 'horde', 'class' => 'MAGE',
            'captured_at' => 1794528000, 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('character_import_authorizations')->insert([
            'id' => (string) Str::uuid(), 'character_id' => $id, 'uploader_id' => $uploader->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
