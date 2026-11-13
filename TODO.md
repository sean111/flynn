# Flynn Implementation TODO

Use this checklist to build and track the Flynn applications. Check an item off when it is implemented and verified; keep larger work in progress unchecked until its acceptance criteria are met.

## Project foundations and contracts

- [ ] Create the planned `addon/`, `web/`, `desktop/`, and `bot/` application structure.
- [ ] Define and document the versioned character/profession/recipe data contract shared by the addon, web API, and desktop app.
  - [ ] Include integer `schemaVersion` in every SavedVariables and export payload.
  - [ ] Specify supported `gameFlavor` values: `retail`, `classic_era`, and `classic`.
  - [ ] Define identifiers and fields for characters, professions, recipes, outputs, reagents, quality, and reagent slots.
- [ ] Define the `/api/v1/*` API contract; the Laravel backend is the sole owner of persistent domain state and business rules.
- [ ] Define payload/file size limits, API rate limits, validation rules, and actionable unsupported-schema errors.
- [ ] Select and document the relational database (PostgreSQL or MySQL), migrations strategy, and queued-job configuration.
- [ ] Add project-wide setup instructions and automated test/lint/build commands for each application.

## WoW addon (`addon/`)

### Data capture and compatibility

- [x] Create a flavor-aware addon layout and package metadata for Retail, Classic Era, and Classic clients.
- [x] Implement the Retail/modern `C_TradeSkillUI` and legacy `GetTradeSkill*` adapters for the full verified roster, including Archaeology and sixth-slot First Aid where exposed.
- [x] Capture recipe-less Herbalism, Fishing, Skinning, and Archaeology from roster ranks with empty recipe arrays; do not require nonexistent recipe windows.
- [x] Add the Classic Era Enchanting `GetCraft*` adapter as an additional compatibility path; it does not limit capture to Enchanting.
- [x] Capture character metadata: `schemaVersion`, `gameFlavor`, `region`, `realm`, `characterName`, `guid`, `faction`, `class`, and UTC-epoch `capturedAt`.
- [x] Capture each supported profession's `SkillLineID` and current/max skill ranks.
  - [ ] Capture specialization only when a client exposes a stable numeric specialization ID; current adapter deliberately omits unavailable/ambiguous specialization data.
- [x] Capture known recipe `spellID`s, reported output `itemID`s and quantities, reagent `itemID`s and quantities, and Retail quality/reagent-slot metadata where available; omit unidentified values rather than fabricate them.
- [x] Store compact numeric IDs and quantities instead of localized tooltip text wherever possible.

### Persistence, scanning, and export

- [x] Persist schema-versioned data in `FlynnDB` SavedVariables at `WTF/Account/<Account>/SavedVariables/Flynn.lua`, with small tracking preferences in the same assignment and scan staging kept in memory.
- [x] Namespace saved character snapshots by `[gameFlavor][region][realm][characterName]` so characters/flavors do not overwrite each other.
- [x] Start recipe scans only from `TRADE_SKILL_SHOW`, `TRADE_SKILL_DATA_SOURCE_CHANGED`, `TRADE_SKILL_LIST_UPDATE`, and `CRAFT_SHOW`; coalesce duplicate events and cancel on both applicable close events and source changes.
- [x] Ensure there are no `OnUpdate` polling loops, background timers, or idle combat listeners; timer yields and `PLAYER_REGEN_DISABLED` registration exist only while scans are active.
- [x] Abort scans while `InCombatLockdown()` is true and cancel active scans on `PLAYER_REGEN_DISABLED`; stale callbacks cannot commit.
- [x] Yield large scans across frames with a 4 ms `debugprofilestop` budget when available and a hard 10-operation batch cap, including nested recipe/reagent loops. Cache callbacks are unnecessary for captured fields because adapters use native numeric IDs and do not request cache text.
- [x] Require a complete native player roster, certified own-data source, and matching character/window/profession/roster state before an atomic snapshot commit; never infer or append the active profession to an incomplete roster.
- [x] Fail closed for linked/guild/NPC modern sources and uncertified legacy linked sources; refuse ambiguous legacy filters unless the explicit `All` state is confirmed.
- [x] Add `/flynn` and `/flynn export` commands for a selectable, read-only copy dialog and `/flynn settings` tracking controls.
- [x] Serialize, raw-DEFLATE-compress, and RFC4648 Base64-encode exports with a `FLYNN1:` prefix; enforce decoded/export/API structural limits without truncation.
- [x] Tell users in-game that SavedVariables are written on `/reload`, logout, or game exit before syncing.
- [ ] Test all supported client flavors, scan interruption/combat behavior, SavedVariables namespacing, and export round-tripping.

## Web application and backend (`web/`)

### Accounts and authentication

- [ ] Build the Laravel API and React + TypeScript web application.
- [ ] Implement email/password registration, sign-in, and account settings.
- [ ] Implement Discord OAuth2 account linking; persist the verified `discord_user_id` and handle.
- [ ] Implement Laravel Sanctum token issuance/revocation for the desktop app, including login/PKCE exchange and expired/401 handling.
- [ ] Implement scoped bearer/HMAC service authentication for the Discord bot; require acting `discord_user_id` and `discord_guild_id` on user commands.

### Guilds and memberships

- [ ] Implement guild creation with name, game flavor, region, realm, and optional linked `discord_guild_id`.
- [ ] Verify linked Discord guild bot presence/admin permission.
- [ ] Make the creator Guild Owner and support promoting/demoting Officers.
- [ ] Support guild discovery and invite-link applications.
- [ ] Put every new membership into `pending`; allow Owners/Officers to approve or reject applications and revoke members.
- [ ] Enforce that only `approved` members can associate characters, appear in crafter search, or create/fulfill guild requests.

### Character and dump ingestion

- [ ] Let users view, claim, and delete imported characters.
- [ ] Let users assign characters to one or more approved guilds, enforcing matching flavor and region/realm rules.
- [ ] Implement manual web upload for a `Flynn.lua` SavedVariables file and paste-in `FLYNN1:...` export strings.
- [ ] Implement `POST /api/v1/dumps` for desktop uploads of `Flynn.lua` payloads or structured JSON with schema metadata.
- [ ] Parse uploaded Lua with a strict deterministic parser; never execute uploaded Lua.
- [ ] Enforce payload size limits and validate schema/version before processing.
- [ ] Queue dump ingestion; perform idempotent character/profession/skill/recipe upserts.
- [ ] Prune professions and recipes no longer present on re-upload and update `last_synced_at`.
- [ ] Return clear errors for malformed files, unsupported schemas, and oversized payloads.

### Crafting requests and web UI

- [ ] Implement the request data model: guild, requester, optional recipient character, crafter user/character, recipe, quantity, materials source (`requester`, `crafter`, `partial`), notes, and status timestamps.
- [ ] Enforce transitions: `pending` → `accepted` / `denied`; `accepted` → `completed`; `pending` or `accepted` → `cancelled` by requester.
- [ ] Enforce which actor may perform each transition; support optional denial reason and requester confirmation of completion.
- [ ] Build crafter Incoming Requests views with pending/accepted/history filters and Accept, Deny, and Mark Completed actions.
- [ ] Build requester Outgoing Requests views showing status, reagents, assigned crafter character, and Cancel action.
- [ ] Emit a notification event for every status transition and queue Discord notifications to the counterpart.

## Desktop companion (`desktop/`)

- [ ] Build the Rust + Tauri desktop application with lightweight native UI and system tray support.
- [ ] Require Flynn API login before enabling uploads.
- [ ] Store API/session tokens only in the OS credential store (macOS Keychain, Windows Credential Manager, Linux Secret Service).
- [ ] Detect expired/401 credentials and prompt the user to authenticate again.
- [ ] Auto-detect standard WoW installation paths on supported OSes and provide a manual folder picker.
- [ ] Discover `Flynn.lua` under `<WoW Root>/{_retail_,_classic_era_,_classic_}/WTF/Account/*/SavedVariables/`.
- [ ] Watch discovered files with OS filesystem events and debounce writes until reload/logout file updates settle.
- [ ] SHA-256 hash file contents and skip uploads when a file has not changed.
- [ ] Implement Automatic Sync on file change and a manual Upload Now action.
- [ ] Show sync status, last upload time, characters updated, and useful network/file/authentication error diagnostics.
- [ ] Add settings for WoW path, per-account/flavor sync toggles, auto-sync, launch on startup, minimize to tray, and logout.
- [ ] Test file discovery, debounce behavior, unchanged-file suppression, secure token storage, and API upload/error flows on supported OSes.

## Discord bot (`bot/`)

- [ ] Build the Rust Discord gateway/interactions client and async HTTP client; communicate with Flynn domain data exclusively through the backend API.
- [ ] Resolve each Discord server to its linked Flynn Guild.
- [ ] Authorize commands by linked `discord_user_id` and approved guild membership; provide onboarding links/instructions for unlinked or pending users.
- [ ] Implement guild-flavor-scoped recipe search/autocomplete (including `/craft search` and `/craft add`).
- [ ] Show eligible approved guild characters that know a recipe, profession skill/rank, and last sync time.
- [ ] Implement per-user, per-guild active carts with reasonable TTL, persisted through the API or bot state.
- [ ] Render carts as ephemeral embeds with interactive buttons/select menus.
- [ ] Support add recipe, set quantity, choose eligible crafter, choose materials source (`Have Mats` / `Need Mats`), add note, remove item, and clear cart.
- [ ] Calculate the aggregated bill of materials across all cart items and quantities.
- [ ] Show per-item/per-crafter routing previews so request destinations are clear before checkout.
- [ ] Implement atomic Send Requests checkout through the API; create all selected requests and clear the cart only on success.
- [ ] Notify assigned crafters via DM and optionally a configured guild channel with item, quantity, reagents, requester, and interactive Accept/Deny/Complete controls synced to the API.
- [ ] Notify requesters via DM when requests are accepted, denied (including the reason), or completed.
- [ ] Test authorization, cart interactions/expiry, atomic checkout, and lifecycle notification flows.

## Cross-application verification and delivery

- [ ] Verify addon SavedVariables and export-string payloads are accepted by the web parser and desktop upload API.
- [ ] Verify schema migrations/backward compatibility and actionable update guidance across addon, desktop, and API versions.
- [ ] Verify flavor/region/realm isolation for characters, recipes, guilds, and crafter search.
- [ ] Verify permissions for pending/unapproved members and all guild/request actions.
- [ ] Verify API rate limits on dump uploads, authentication, and bot/webhook mutations, plus file/export size limits.
- [ ] Add end-to-end coverage for dump ingestion, guild approval, crafter discovery, request lifecycle, and Discord notifications.
- [ ] Document local development, configuration/secrets, deployment, and user setup for each application.
