<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('discord_user_id', 24)->unique();
            $table->string('handle', 128);
            $table->timestamps();
        });

        Schema::create('guilds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('game_flavor', 20);
            $table->string('region', 2);
            $table->string('realm', 128);
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('discord_guild_id', 24)->nullable()->unique();
            $table->string('notification_channel_id', 24)->nullable();
            $table->boolean('discoverable')->default(true);
            $table->timestamps();
            $table->index(['game_flavor', 'region', 'realm']);
        });

        Schema::create('guild_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['guild_id', 'user_id']);
            $table->index(['guild_id', 'status']);
        });

        Schema::create('guild_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('game_flavor', 20);
            $table->string('region', 2);
            $table->string('realm', 128);
            $table->string('character_name', 128);
            $table->string('guid', 128);
            $table->string('faction', 16);
            $table->string('class', 24);
            $table->unsignedBigInteger('captured_at');
            $table->timestamp('last_synced_at');
            $table->timestamps();
            $table->unique(['game_flavor', 'region', 'realm', 'character_name']);
            $table->index(['owner_id', 'last_synced_at']);
        });

        Schema::create('character_import_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('character_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'uploader_id']);
        });

        Schema::create('character_guild', function (Blueprint $table) {
            $table->uuid('character_id');
            $table->uuid('guild_id');
            $table->foreign('character_id')->references('id')->on('characters')->cascadeOnDelete();
            $table->foreign('guild_id')->references('id')->on('guilds')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['character_id', 'guild_id']);
        });

        Schema::create('character_professions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('skill_line_id');
            $table->unsignedInteger('current_skill');
            $table->unsignedInteger('max_skill');
            $table->json('specialization')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'skill_line_id']);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('game_flavor', 20);
            $table->foreignUuid('profession_id')->constrained('character_professions')->cascadeOnDelete();
            $table->unsignedBigInteger('spell_id');
            $table->json('quality')->nullable();
            $table->timestamps();
            $table->unique(['profession_id', 'spell_id']);
            $table->index(['game_flavor', 'spell_id']);
        });

        Schema::create('recipe_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quality_rank')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'recipe_id']);
        });

        Schema::create('recipe_reagents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->index(['item_id', 'recipe_id']);
        });

        Schema::create('recipe_reagent_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot_index');
            $table->string('kind', 16);
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['recipe_id', 'slot_index']);
        });

        Schema::create('recipe_slot_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('slot_id')->constrained('recipe_reagent_slots')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->timestamps();
            $table->unique(['slot_id', 'item_id']);
        });

        foreach (['catalog_professions', 'catalog_spells', 'catalog_items'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->uuid('id')->primary();
                $table->string('game_flavor', 20);
                $table->unsignedBigInteger($tableName === 'catalog_professions' ? 'skill_line_id' : ($tableName === 'catalog_spells' ? 'spell_id' : 'item_id'));
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->unsignedTinyInteger('quality')->nullable();
                $table->string('icon', 255)->nullable();
                $table->timestamps();
                $table->unique(['game_flavor', $tableName === 'catalog_professions' ? 'skill_line_id' : ($tableName === 'catalog_spells' ? 'spell_id' : 'item_id')], $tableName.'_flavor_id_unique');
            });
        }

        Schema::create('dumps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('staging_path', 512)->nullable();
            $table->string('checksum', 64);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('characters_seen')->default(0);
            $table->unsignedInteger('characters_updated')->default(0);
            $table->json('error')->nullable();
            $table->timestamps();
            $table->index(['uploader_id', 'created_at']);
        });

        Schema::create('dump_character_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dump_id')->constrained()->cascadeOnDelete();
            $table->string('identity', 512);
            $table->string('status', 24);
            $table->string('code', 64)->nullable();
            $table->text('message')->nullable();
            $table->foreignUuid('character_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamps();
            $table->unique(['guild_id', 'user_id']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('crafter_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('materials_provided', 16);
            $table->string('recipient_character_name', 128)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->json('response');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        Schema::create('crafting_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('guild_id')->constrained()->restrictOnDelete();
            $table->string('game_flavor', 20);
            $table->foreignUuid('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_character_name', 128)->nullable();
            $table->foreignUuid('crafter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('crafter_character_id')->constrained('characters')->restrictOnDelete();
            $table->foreignUuid('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('spell_id');
            $table->unsignedBigInteger('skill_line_id');
            $table->string('recipe_name', 255)->nullable();
            $table->unsignedInteger('quality_max_rank')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('materials_provided', 16);
            $table->string('requester_notes', 2000)->nullable();
            $table->string('crafter_notes', 2000)->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['requester_user_id', 'status']);
            $table->index(['crafter_user_id', 'status']);
        });

        Schema::create('request_reagents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('crafting_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
        });

        Schema::create('request_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('crafting_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('quantity');
            $table->unsignedInteger('quality_rank')->nullable();
            $table->timestamps();
        });

        Schema::create('request_quality_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('crafting_requests')->cascadeOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
            $table->unique(['request_id', 'rank']);
        });

        Schema::create('request_reagent_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('crafting_requests')->cascadeOnDelete();
            $table->unsignedInteger('slot_index');
            $table->string('kind', 16);
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
            $table->unique(['request_id', 'slot_index']);
        });

        Schema::create('request_slot_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('slot_id')->constrained('request_reagent_slots')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->timestamps();
            $table->unique(['slot_id', 'item_id']);
        });

        Schema::create('request_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('crafting_requests')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->string('domain_event_id', 36)->unique();
            $table->string('note', 2000)->nullable();
            $table->timestamps();
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->string('aggregate_id', 36);
            $table->json('payload');
            $table->timestamp('available_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('outbox_event_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_id', 64);
            $table->string('delivery_id', 128)->nullable();
            $table->string('status', 16)->default('queued');
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['outbox_event_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'notification_deliveries', 'outbox_events', 'request_transitions', 'request_slot_options', 'request_reagent_slots', 'request_quality_outputs', 'request_outputs', 'request_reagents', 'crafting_requests',
            'idempotency_records', 'cart_items', 'carts', 'dump_character_results', 'dumps', 'catalog_items', 'catalog_spells',
            'catalog_professions', 'recipe_slot_options', 'recipe_reagent_slots', 'recipe_reagents', 'recipe_outputs', 'recipes',
            'character_professions', 'character_guild', 'character_import_authorizations', 'characters', 'guild_invites',
            'guild_memberships', 'guilds', 'discord_identities',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
