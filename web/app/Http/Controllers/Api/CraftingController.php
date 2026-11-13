<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CraftingController extends ApiController
{
    public function recipes(Request $request, string $guildId)
    {
        $guild = $this->approvedGuild($request, $guildId);
        if (! $guild) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $search = trim((string) $request->query('query', ''));
        $query = $this->eligibleRecipes($guildId, $guild->game_flavor);
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                if (ctype_digit($search)) {
                    $where->orWhere('r.spell_id', (int) $search)->orWhereExists(function ($sub) use ($search) {
                        $sub->selectRaw('1')->from('recipe_outputs as ro')->whereColumn('ro.recipe_id', 'r.id')->where('ro.item_id', (int) $search);
                    });
                }
                $where->orWhereExists(function ($sub) use ($search) {
                    $sub->selectRaw('1')->from('catalog_spells as cs')->whereColumn('cs.spell_id', 'r.spell_id')->whereColumn('cs.game_flavor', 'r.game_flavor')->where('cs.name', 'ilike', '%'.$this->escapeLike($search).'%');
                });
                $where->orWhereExists(function ($sub) use ($search) {
                    $sub->selectRaw('1')->from('recipe_outputs as ro')->join('catalog_items as ci', function ($join) {
                        $join->on('ci.item_id', '=', 'ro.item_id')->on('ci.game_flavor', '=', 'r.game_flavor');
                    })->whereColumn('ro.recipe_id', 'r.id')->where('ci.name', 'ilike', '%'.$this->escapeLike($search).'%');
                });
            });
        }
        $recipes = $query->select('r.spell_id')->distinct()->orderBy('r.spell_id')->cursorPaginate(50);
        $items = $recipes->getCollection()->map(function ($row) use ($guildId, $guild) {
            $recipe = $this->eligibleRecipes($guildId, $guild->game_flavor)->where('r.spell_id', $row->spell_id)->select('r.id', 'r.spell_id', 'p.skill_line_id')->first();

            return $this->recipeSummary($recipe, $guildId);
        });

        return $this->ok($items, 200, ['nextCursor' => $recipes->nextCursor()?->encode()]);
    }

    public function recipe(Request $request, string $guildId, int $spellId)
    {
        $guild = $this->approvedGuild($request, $guildId);
        if (! $guild) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $recipe = $this->eligibleRecipes($guildId, $guild->game_flavor)->where('r.spell_id', $spellId)->select('r.id', 'r.spell_id', 'r.game_flavor', 'r.quality', 'p.skill_line_id')->first();
        if (! $recipe) {
            return $this->error('not_found', 'Recipe is not available from an approved guild crafter.', 404);
        }

        return $this->ok($this->recipeSummary($recipe, $guildId, true));
    }

    public function crafters(Request $request, string $guildId, int $spellId)
    {
        $guild = $this->approvedGuild($request, $guildId);
        if (! $guild) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $crafters = $this->eligibleRecipes($guildId, $guild->game_flavor)->where('r.spell_id', $spellId)
            ->select('c.id', 'c.character_name', 'c.last_synced_at', 'c.owner_id', 'u.name as owner_name', 'p.skill_line_id', 'p.current_skill', 'p.max_skill')->distinct()->orderBy('c.character_name')->orderBy('c.id')->cursorPaginate(50);
        $items = $crafters->getCollection()->map(fn ($row) => ['characterId' => $row->id, 'characterName' => $row->character_name, 'ownerId' => $row->owner_id, 'ownerName' => $row->owner_name, 'skillLineID' => $row->skill_line_id, 'currentSkill' => $row->current_skill, 'maxSkill' => $row->max_skill, 'lastSyncedAt' => $row->last_synced_at]);

        return $this->ok($items, 200, ['nextCursor' => $crafters->nextCursor()?->encode()]);
    }

    public function cart(Request $request, string $guildId)
    {
        if (! $this->approvedGuild($request, $guildId)) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $cart = $this->cartRecord($request, $guildId);
        $items = DB::table('cart_items as ci')->join('recipes as r', 'r.id', '=', 'ci.recipe_id')->join('character_professions as p', 'p.id', '=', 'r.profession_id')->leftJoin('characters as c', 'c.id', '=', 'ci.crafter_character_id')->where('ci.cart_id', $cart->id)->select('ci.*', 'r.spell_id', 'p.skill_line_id', 'c.character_name as crafter_character_name')->get();

        return $this->ok(['id' => $cart->id, 'items' => $items->map(fn ($item) => $this->cartItemResource($item) + ['crafters' => $this->eligibleCrafterListForSpell($guildId, $item->spell_id, DB::table('recipes')->where('id', $item->recipe_id)->value('game_flavor'))]), 'reagentTotals' => $this->reagentTotals($items), 'updatedAt' => $cart->last_activity_at]);
    }

    public function addCartItem(Request $request, string $guildId)
    {
        $guild = $this->approvedGuild($request, $guildId);
        if (! $guild) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $data = $request->validate([
            'spellID' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'crafterCharacterId' => ['nullable', 'uuid'], 'materialsProvided' => ['required', 'in:requester,crafter,partial'],
            'recipientCharacterName' => ['nullable', 'string', 'max:128'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $recipeQuery = $this->eligibleRecipes($guildId, $guild->game_flavor)->where('r.spell_id', $data['spellID']);
        if (! empty($data['crafterCharacterId'])) {
            $recipeQuery->where('c.id', $data['crafterCharacterId']);
        }
        $selected = $recipeQuery->select('r.id as recipe_id', 'c.id as character_id')->orderBy('c.character_name')->orderBy('c.id')->first();
        if (! $selected) {
            return $this->error('recipe_unavailable', 'Recipe is not known by an approved guild crafter.', 422);
        }
        $recipe = (object) ['id' => $selected->recipe_id];
        $crafter = (object) ['id' => $selected->character_id];
        $cart = $this->cartRecord($request, $guildId);
        $id = (string) Str::uuid();
        $added = DB::transaction(function () use ($cart, $id, $recipe, $crafter, $data) {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            if (DB::table('cart_items')->where('cart_id', $cart->id)->count() >= 100) {
                return false;
            }
            DB::table('cart_items')->insert([
                'id' => $id, 'cart_id' => $cart->id, 'recipe_id' => $recipe->id, 'crafter_character_id' => $crafter->id,
                'quantity' => $data['quantity'], 'materials_provided' => $data['materialsProvided'], 'recipient_character_name' => $data['recipientCharacterName'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);

            return true;
        });
        if (! $added) {
            return $this->error('cart_limit_reached', 'An order cart may contain at most 100 recipe lines.', 422);
        }

        return $this->ok(['id' => $id, 'spellID' => $data['spellID'], 'quantity' => $data['quantity'], 'crafterCharacterId' => $crafter->id], 201);
    }

    public function updateCartItem(Request $request, string $guildId, string $itemId)
    {
        $guild = $this->approvedGuild($request, $guildId);
        if (! $guild) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $cart = $this->cartRecord($request, $guildId);
        $item = DB::table('cart_items')->where('cart_id', $cart->id)->where('id', $itemId)->first();
        if (! $item) {
            return $this->error('not_found', 'Cart item was not found.', 404);
        }
        $data = $request->validate(['quantity' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'crafterCharacterId' => ['sometimes', 'nullable', 'uuid'], 'materialsProvided' => ['sometimes', 'in:requester,crafter,partial'], 'recipientCharacterName' => ['sometimes', 'nullable', 'string', 'max:128'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        if (array_key_exists('crafterCharacterId', $data)) {
            $oldRecipe = DB::table('recipes')->where('id', $item->recipe_id)->first();
            $recipeQuery = $this->eligibleRecipes($guildId, $oldRecipe->game_flavor)->where('r.spell_id', $oldRecipe->spell_id);
            if ($data['crafterCharacterId']) {
                $recipeQuery->where('c.id', $data['crafterCharacterId']);
            }
            $recipe = $recipeQuery->select('r.id', 'c.id as character_id')->orderBy('c.character_name')->orderBy('c.id')->first();
            if (! $recipe) {
                return $this->error('invalid_crafter', 'Choose an eligible guild crafter for this recipe.', 422);
            }
            $data['crafter_character_id'] = $recipe->character_id;
            $data['recipe_id'] = $recipe->id;
            unset($data['crafterCharacterId']);
        }
        foreach (['materialsProvided' => 'materials_provided', 'recipientCharacterName' => 'recipient_character_name'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $data[$column] = $data[$input];
                unset($data[$input]);
            }
        }
        $updated = DB::transaction(function () use ($cart, $itemId, $data) {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            $updated = DB::table('cart_items')->where('cart_id', $cart->id)->where('id', $itemId)->update($data + ['updated_at' => now()]);
            if ($updated) {
                DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);
            }

            return $updated;
        });
        if (! $updated) {
            return $this->error('not_found', 'Cart item was not found.', 404);
        }

        return $this->ok(['id' => $itemId, 'updated' => true]);
    }

    public function removeCartItem(Request $request, string $guildId, string $itemId)
    {
        if (! $this->approvedGuild($request, $guildId)) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $cart = $this->cartRecord($request, $guildId);
        $deleted = DB::transaction(function () use ($cart, $itemId) {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            $deleted = DB::table('cart_items')->where('cart_id', $cart->id)->where('id', $itemId)->delete();
            if ($deleted) {
                DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);
            }

            return $deleted;
        });

        return $deleted ? response()->noContent() : $this->error('not_found', 'Cart item was not found.', 404);
    }

    public function clearCart(Request $request, string $guildId)
    {
        if (! $this->approvedGuild($request, $guildId)) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $cart = $this->cartRecord($request, $guildId);
        DB::transaction(function () use ($cart) {
            DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
            DB::table('cart_items')->where('cart_id', $cart->id)->delete();
            DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);
        });

        return response()->noContent();
    }

    public function checkout(Request $request, string $guildId)
    {
        if (! $this->approvedGuild($request, $guildId)) {
            return $this->error('forbidden', 'An approved membership in this guild is required.', 403);
        }
        $key = $request->header('Idempotency-Key');
        if (! $key || strlen($key) > 128) {
            return $this->error('invalid_idempotency_key', 'Provide an Idempotency-Key header of at most 128 characters.', 422);
        }
        $cart = $this->cartRecord($request, $guildId);
        try {
            $checkout = DB::transaction(function () use ($cart, $request, $guildId, $key) {
                DB::table('carts')->where('id', $cart->id)->lockForUpdate()->first();
                $items = DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('id')->lockForUpdate()->get();
                $hash = $this->cartPayloadHash($guildId, $request->user()->id, $items);
                $scopeHash = hash('sha256', 'cart.checkout|'.$request->user()->id.'|'.$guildId.'|'.$cart->id);
                $existing = DB::table('idempotency_records')->where('user_id', $request->user()->id)->where('key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->scope_hash && hash_equals($existing->scope_hash, $scopeHash) && ($items->isEmpty() || hash_equals($existing->request_hash, $hash))) {
                        return ['kind' => 'replay', 'data' => json_decode($existing->response, true)];
                    }

                    return ['kind' => 'conflict'];
                }
                if ($items->isEmpty()) {
                    return ['kind' => 'empty'];
                }
                $requests = [];
                foreach ($items as $item) {
                    $crafter = DB::table('characters')->where('id', $item->crafter_character_id)->first();
                    if (! $crafter || ! DB::table('guild_memberships')->where('guild_id', $cart->guild_id)->where('user_id', $crafter->owner_id)->where('status', 'approved')->exists()) {
                        throw new \DomainException('A selected crafter is no longer eligible.');
                    }
                    if (! DB::table('character_guild')->where('guild_id', $cart->guild_id)->where('character_id', $crafter->id)->exists()) {
                        throw new \DomainException('The selected character is no longer associated with this guild.');
                    }
                    $recipe = DB::table('recipes as r')->join('character_professions as p', 'p.id', '=', 'r.profession_id')->where('r.id', $item->recipe_id)->where('p.character_id', $crafter->id)->select('r.*', 'p.skill_line_id')->first();
                    if (! $recipe) {
                        throw new \DomainException('The selected recipe is no longer available.');
                    }
                    $recipeName = DB::table('catalog_spells')->where('game_flavor', $recipe->game_flavor)->where('spell_id', $recipe->spell_id)->value('name') ?? 'Recipe #'.$recipe->spell_id;
                    $id = (string) Str::uuid();
                    $requests[] = $id;
                    DB::table('crafting_requests')->insert([
                        'id' => $id, 'guild_id' => $cart->guild_id, 'game_flavor' => $recipe->game_flavor, 'requester_user_id' => $request->user()->id,
                        'recipient_character_name' => $item->recipient_character_name, 'crafter_user_id' => $crafter->owner_id,
                        'crafter_character_id' => $crafter->id, 'recipe_id' => $item->recipe_id, 'spell_id' => $recipe->spell_id, 'skill_line_id' => $recipe->skill_line_id,
                        'recipe_name' => $recipeName, 'quality_max_rank' => data_get(json_decode($recipe->quality ?? 'null', true), 'maxRank'), 'quantity' => $item->quantity,
                        'materials_provided' => $item->materials_provided, 'requester_notes' => $item->notes, 'status' => 'pending',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    foreach (DB::table('recipe_reagents')->where('recipe_id', $item->recipe_id)->get() as $reagent) {
                        DB::table('request_reagents')->insert(['id' => (string) Str::uuid(), 'request_id' => $id, 'item_id' => $reagent->item_id, 'quantity' => $reagent->quantity * $item->quantity, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    foreach (DB::table('recipe_outputs')->where('recipe_id', $item->recipe_id)->get() as $output) {
                        DB::table('request_outputs')->insert(['id' => (string) Str::uuid(), 'request_id' => $id, 'item_id' => $output->item_id, 'quantity' => $output->quantity * $item->quantity, 'quality_rank' => $output->quality_rank, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $quality = json_decode($recipe->quality ?? 'null', true);
                    foreach ($quality['outputs'] ?? [] as $output) {
                        DB::table('request_quality_outputs')->insert(['id' => (string) Str::uuid(), 'request_id' => $id, 'rank' => $output['rank'], 'item_id' => $output['itemID'], 'quantity' => $output['quantity'] * $item->quantity, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    foreach (DB::table('recipe_reagent_slots')->where('recipe_id', $item->recipe_id)->get() as $slot) {
                        $snapshotSlotId = (string) Str::uuid();
                        DB::table('request_reagent_slots')->insert(['id' => $snapshotSlotId, 'request_id' => $id, 'slot_index' => $slot->slot_index, 'kind' => $slot->kind, 'quantity' => $slot->quantity * $item->quantity, 'created_at' => now(), 'updated_at' => now()]);
                        foreach (DB::table('recipe_slot_options')->where('slot_id', $slot->id)->get() as $option) {
                            DB::table('request_slot_options')->insert(['id' => (string) Str::uuid(), 'slot_id' => $snapshotSlotId, 'item_id' => $option->item_id, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                    $event = (string) Str::uuid();
                    DB::table('outbox_events')->insert(['id' => $event, 'event_type' => 'request.created', 'aggregate_id' => $id, 'payload' => json_encode(['requestId' => $id, 'toStatus' => 'pending', 'recipientUserId' => $crafter->owner_id]), 'available_at' => now(), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('cart_items')->where('cart_id', $cart->id)->delete();
                $result = ['requestIds' => $requests, 'createdCount' => count($requests)];
                DB::table('idempotency_records')->insert(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'key' => $key, 'request_hash' => $hash, 'scope_hash' => $scopeHash, 'response' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);

                return ['kind' => 'created', 'data' => $result];
            });
        } catch (\DomainException $exception) {
            return $this->error('checkout_conflict', $exception->getMessage(), 409);
        } catch (UniqueConstraintViolationException) {
            $existing = DB::table('idempotency_records')->where('user_id', $request->user()->id)->where('key', $key)->first();
            $scopeHash = hash('sha256', 'cart.checkout|'.$request->user()->id.'|'.$guildId.'|'.$cart->id);
            if ($existing && $existing->scope_hash && hash_equals($existing->scope_hash, $scopeHash) && DB::table('cart_items')->where('cart_id', $cart->id)->doesntExist()) {
                return $this->ok(json_decode($existing->response, true));
            }

            return $this->error('idempotency_conflict', 'This Idempotency-Key was already used for a different operation.', 409);
        }
        if ($checkout['kind'] === 'empty') {
            return $this->error('empty_cart', 'Add at least one recipe before checking out.', 409);
        }
        if ($checkout['kind'] === 'conflict') {
            return $this->error('idempotency_conflict', 'This Idempotency-Key was already used for a different cart.', 409);
        }

        return $this->ok($checkout['data'], $checkout['kind'] === 'created' ? 201 : 200);
    }

    private function approvedGuild(Request $request, string $guildId): ?object
    {
        $guild = DB::table('guilds')->where('id', $guildId)->first();
        if (! $guild || ! DB::table('guild_memberships')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->where('status', 'approved')->exists()) {
            return null;
        }

        return $guild;
    }

    private function eligibleRecipes(string $guildId, string $flavor)
    {
        return DB::table('recipes as r')->join('character_professions as p', 'p.id', '=', 'r.profession_id')->join('characters as c', 'c.id', '=', 'p.character_id')->join('users as u', 'u.id', '=', 'c.owner_id')->join('character_guild as cg', 'cg.character_id', '=', 'c.id')->join('guild_memberships as gm', function ($join) {
            $join->on('gm.guild_id', '=', 'cg.guild_id')->on('gm.user_id', '=', 'c.owner_id')->where('gm.status', '=', 'approved');
        })->where('cg.guild_id', $guildId)->where('r.game_flavor', $flavor);
    }

    private function cartRecord(Request $request, string $guildId): object
    {
        return DB::transaction(function () use ($request, $guildId) {
            $cart = DB::table('carts')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if ($cart && now()->subHours(24)->greaterThanOrEqualTo($cart->last_activity_at)) {
                DB::table('cart_items')->where('cart_id', $cart->id)->delete();
                DB::table('carts')->where('id', $cart->id)->update(['last_activity_at' => now(), 'updated_at' => now()]);
                $cart->last_activity_at = now();

                return $cart;
            }
            if ($cart) {
                return $cart;
            }
            $id = (string) Str::uuid();
            DB::table('carts')->insertOrIgnore(['id' => $id, 'guild_id' => $guildId, 'user_id' => $request->user()->id, 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('carts')->where('guild_id', $guildId)->where('user_id', $request->user()->id)->first();
        });
    }

    private function cartPayloadHash(string $guildId, string $userId, $items): string
    {
        $payload = $items->map(fn ($item) => [
            'id' => $item->id,
            'recipeId' => $item->recipe_id,
            'crafterCharacterId' => $item->crafter_character_id,
            'quantity' => $item->quantity,
            'materialsProvided' => $item->materials_provided,
            'recipientCharacterName' => $item->recipient_character_name,
            'notes' => $item->notes,
        ])->all();

        return hash('sha256', $guildId.'|'.$userId.'|'.json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function recipeSummary(object $recipe, string $guildId, bool $detail = false): array
    {
        $spell = DB::table('catalog_spells')->where('game_flavor', DB::table('recipes')->where('id', $recipe->id)->value('game_flavor'))->where('spell_id', $recipe->spell_id)->first();
        $flavor = DB::table('recipes')->where('id', $recipe->id)->value('game_flavor');
        $result = ['id' => $recipe->id, 'spellID' => $recipe->spell_id, 'name' => $spell->name ?? 'Recipe #'.$recipe->spell_id, 'description' => $spell->description ?? null, 'icon' => $spell->icon ?? null, 'skillLineID' => $recipe->skill_line_id, 'crafters' => $this->eligibleCrafterListForSpell($guildId, $recipe->spell_id, $flavor)];
        if ($detail) {
            $result['quality'] = isset($recipe->quality) ? json_decode($recipe->quality, true) : null;
            $result['outputs'] = $this->itemRecords('recipe_outputs', $recipe->id);
            $result['reagents'] = $this->itemRecords('recipe_reagents', $recipe->id);
        }

        return $result;
    }

    private function eligibleCrafterList(string $guildId, string $recipeId): array
    {
        return $this->eligibleCrafterQuery($guildId, $recipeId)->get()->map(fn ($crafter) => ['characterId' => $crafter->id, 'characterName' => $crafter->character_name, 'ownerId' => $crafter->owner_id, 'ownerName' => $crafter->owner_name, 'currentSkill' => $crafter->current_skill, 'maxSkill' => $crafter->max_skill, 'lastSyncedAt' => $crafter->last_synced_at])->all();
    }

    private function eligibleCrafterListForSpell(string $guildId, int $spellId, string $flavor): array
    {
        return $this->eligibleCrafterSpellQuery($guildId, $spellId, $flavor)->get()->map(fn ($crafter) => ['characterId' => $crafter->id, 'characterName' => $crafter->character_name, 'ownerId' => $crafter->owner_id, 'ownerName' => $crafter->owner_name, 'currentSkill' => $crafter->current_skill, 'maxSkill' => $crafter->max_skill, 'lastSyncedAt' => $crafter->last_synced_at])->all();
    }

    private function eligibleCrafterSpellQuery(string $guildId, int $spellId, string $flavor)
    {
        return DB::table('characters as c')->join('character_guild as cg', 'cg.character_id', '=', 'c.id')->join('guild_memberships as gm', function ($join) {
            $join->on('gm.guild_id', '=', 'cg.guild_id')->on('gm.user_id', '=', 'c.owner_id')->where('gm.status', '=', 'approved');
        })->join('users as u', 'u.id', '=', 'c.owner_id')->join('character_professions as p', 'p.character_id', '=', 'c.id')->join('recipes as r', 'r.profession_id', '=', 'p.id')->where('cg.guild_id', $guildId)->where('r.game_flavor', $flavor)->where('r.spell_id', $spellId)->select('c.id', 'c.character_name', 'c.owner_id', 'c.last_synced_at', 'u.name as owner_name', 'p.current_skill', 'p.max_skill')->distinct();
    }

    private function eligibleCrafterQuery(string $guildId, string $recipeId)
    {
        return DB::table('characters as c')->join('character_guild as cg', 'cg.character_id', '=', 'c.id')->join('guild_memberships as gm', function ($join) {
            $join->on('gm.guild_id', '=', 'cg.guild_id')->on('gm.user_id', '=', 'c.owner_id')->where('gm.status', '=', 'approved');
        })->join('users as u', 'u.id', '=', 'c.owner_id')->join('character_professions as p', 'p.character_id', '=', 'c.id')->join('recipes as r', 'r.profession_id', '=', 'p.id')->where('cg.guild_id', $guildId)->where('r.id', $recipeId)->select('c.id', 'c.character_name', 'c.owner_id', 'c.last_synced_at', 'u.name as owner_name', 'p.current_skill', 'p.max_skill')->distinct();
    }

    private function itemRecords(string $table, string $recipeId): array
    {
        $recipe = DB::table('recipes')->where('id', $recipeId)->first();

        $query = DB::table($table.' as t')->leftJoin('catalog_items as ci', function ($join) use ($recipe) {
            $join->on('ci.item_id', '=', 't.item_id')->where('ci.game_flavor', '=', $recipe->game_flavor);
        })->where('t.recipe_id', $recipeId)->select('t.item_id', 't.quantity', 'ci.name', 'ci.icon');
        if ($table === 'recipe_outputs') {
            $query->addSelect('t.quality_rank');
        }

        return $query->get()->map(fn ($item) => ['itemID' => $item->item_id, 'name' => $item->name ?? 'Item #'.$item->item_id, 'quantity' => $item->quantity, 'quality' => $table === 'recipe_outputs' ? $item->quality_rank : null, 'icon' => $item->icon ?? null])->all();
    }

    private function cartItemResource(object $item): array
    {
        return ['id' => $item->id, 'spellID' => $item->spell_id, 'skillLineID' => $item->skill_line_id, 'quantity' => $item->quantity, 'crafterCharacterId' => $item->crafter_character_id, 'crafterCharacterName' => $item->crafter_character_name, 'materialsProvided' => $item->materials_provided, 'recipientCharacterName' => $item->recipient_character_name, 'notes' => $item->notes];
    }

    private function reagentTotals(iterable $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            foreach (DB::table('recipe_reagents')->where('recipe_id', $item->recipe_id)->get() as $reagent) {
                $totals[$reagent->item_id] = ($totals[$reagent->item_id] ?? 0) + $reagent->quantity * $item->quantity;
            }
        }
        $first = collect($items)->first();
        $flavor = $first ? DB::table('recipes')->where('id', $first->recipe_id)->value('game_flavor') : null;

        return collect($totals)->map(fn ($quantity, $itemId) => ['itemID' => (int) $itemId, 'name' => $flavor ? (DB::table('catalog_items')->where('game_flavor', $flavor)->where('item_id', $itemId)->value('name') ?? 'Item #'.$itemId) : 'Item #'.$itemId, 'quantity' => $quantity])->values()->all();
    }

    private function escapeLike(string $input): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $input);
    }
}
