# Flynn

## Overview

Flynn is an end-to-end World of Warcraft guild profession catalog and crafting request platform. Players export their characters' profession and recipe data via a lightweight WoW addon, sync it through a desktop client or web upload, and browse crafters or submit multi-item crafting orders through Discord or the web application.

### Supported WoW Flavors
Flynn supports multiple World of Warcraft client flavors simultaneously, isolating recipe databases, characters, and guild rosters by flavor:
- **Retail** (`_retail_`)
- **Classic Era** (`_classic_era_`)
- **Classic Progression / Anniversary** (`_classic_`)
- **WoW Forever / Camelot** (`_forever_`, distinct `forever` domain flavor)

### Planned Repository Layout
- `addon/` — World of Warcraft Lua addon (multi-TOC / flavor-aware)
- `web/` — Laravel PHP backend API & React web frontend
- `desktop/` — Rust & Tauri desktop companion app
- `bot/` — Rust Discord bot

---

## 1. WoW Addon (`addon/`)

### Responsibilities
Collect profession skill levels, specializations, known recipes, crafted output items, and required/optional reagents for each character on a player's account, then persist that data for export without degrading game performance.

### Functional Requirements
1. **Multi-Flavor TradeSkill Abstraction**:
   - Provide a thin compatibility layer over client APIs: `C_TradeSkillUI` on Retail/modern clients, and `GetTradeSkill*` plus `GetCraft*` (required for Enchanting in Classic Era) on Classic clients.
   - Capture per-character metadata: `schemaVersion`, `gameFlavor`, `region`, `realm`, `characterName`, `guid`, `faction`, `class`, and `capturedAt` (UTC epoch).
   - Capture per-profession metadata: profession ID (`SkillLineID`), current skill rank, max skill rank, specialization (if applicable), and known recipe IDs (`spellID`), output `itemID`s, quantities produced, reagent `itemID`s/quantities, and Retail quality/reagent slot metadata where applicable.
2. **Persistence (`SavedVariables`)**:
   - Store collected character data in `FlynnDB` (`WTF/Account/<Account>/SavedVariables/Flynn.lua`), namespaced by `[gameFlavor][region][realm][characterName]` so multiple characters and accounts do not overwrite one another.
   - Note: WoW only flushes `SavedVariables` to disk on `/reload`, character logout, or game exit; in-game UI messaging should remind users to `/reload` or log out before syncing files.
3. **In-Game Export String**:
   - Provide `/flynn` (or `/flynn export`) to open a minimal read-only copy dialog containing a version-prefixed, serialized, Deflate-compressed, Base64-encoded dump (e.g., `FLYNN1:<payload>`) for direct paste into the web app.

### Performance & Optimization Requirements
- **Zero Idle Overhead**: Never register `OnUpdate` polling loops, background timers, or combat event listeners. Memory usage and CPU time outside an open profession frame must be effectively zero.
- **Event-Driven Collection Only**: Scan exclusively when the player opens or updates a profession window (`TRADE_SKILL_SHOW`, `TRADE_SKILL_DATA_SOURCE_CHANGED`, `TRADE_SKILL_LIST_UPDATE`, `CRAFT_SHOW`).
- **Combat Guard**: Immediately abort or defer any scan if `InCombatLockdown()` is true, and cancel any in-progress scan on `PLAYER_REGEN_DISABLED`.
- **Frame-Budgeted / Asynchronous Scanning**: Yield across frames (`C_Timer.After` / coroutines) and use asynchronous item/spell cache callbacks (`Spell:ContinueOnSpellLoad`, `Item:ContinueOnItemLoad`) when scanning large recipe lists so client frame times never spike.
- **Compact Storage**: Store numeric IDs (`spellID`, `itemID`, `skillLineID`, quantities) rather than full localized tooltip strings wherever possible to keep `Flynn.lua` small and fast to serialize on `/reload`.

---

## 2. Web Application & Backend API (`web/`)

### Stack
- **Backend**: Laravel (PHP), relational database (PostgreSQL or MySQL), queued background jobs for dump ingestion and Discord notifications.
- **Frontend**: React (with TypeScript) integrated with Laravel.

### Authentication & Identity Linking
1. **User Accounts**:
   - Users register and sign in with email and password.
   - Users link their Discord account via **Discord OAuth2** (storing verified `discord_user_id` and handle). Linking Discord is required before a user can interact with the Discord bot on behalf of their guild membership.
2. **API Authentication**:
   - **Desktop App**: Token-based authentication via Laravel Sanctum personal/device tokens (exchangeable from login/PKCE flow, revocable from the web account settings).
   - **Discord Bot**: Authenticated service-to-service API requests using scoped bearer/HMAC credentials, passing the acting `discord_user_id` and `discord_guild_id` on every user command.

### Guild & Membership Management
1. **Guild Creation**:
   - Any authenticated user can create a guild specifying: guild name, game flavor (`retail`, `classic_era`, `classic`, `forever`), region, realm, and optional linked `discord_guild_id` (verified by bot presence/admin permission).
   - The creator becomes the **Guild Owner** and may promote/demote **Officers**.
2. **Signup & Approval Workflow**:
   - Authenticated users can discover or use an invite link to apply to a guild.
   - New memberships always enter a `pending` state.
   - Guild Owners and Officers view pending applications and must explicitly **Approve** or **Reject** them, and can **Revoke** existing members.
   - Only `approved` members can associate characters with the guild, appear in the guild crafter directory, or create/fulfill guild crafting requests.

### Character & Profession Dump Ingestion
1. **Character Management**:
   - Users view, claim, and delete their imported characters, and assign each character to one or more approved guilds matching that character's game flavor and region/realm rules.
2. **Dual Upload Support**:
   - **Web Manual Upload**: Upload a `Flynn.lua` `SavedVariables` file OR paste a `FLYNN1:...` compressed export string.
   - **Desktop API Upload**: `POST /api/v1/dumps` accepting `Flynn.lua` payloads or structured JSON parsed from `Flynn.lua` with schema version metadata.
3. **Safe Parsing & Upsert**:
   - Never execute uploaded Lua; parse `SavedVariables` via a strict deterministic lexer/parser with payload size limits and schema validation.
   - Perform idempotent upserts of character professions, skill levels, and known recipes (pruning unlearned professions/recipes for a re-uploaded character) and update `last_synced_at`.

### Crafting Request Lifecycle & Web UI
1. **Request Data Model**:
   - Each request links: `guild_id`, `requester_user_id` (and optional recipient character name), `crafter_user_id`, `crafter_character_id`, `recipe_id`, `quantity`, `materials_provided` (`requester`, `crafter`, `partial`), requester notes, crafter response notes, and status timestamps.
2. **State Machine**:
   - `pending` → `accepted` (by assigned crafter)
   - `pending` → `denied` (by assigned crafter, optional reason)
   - `accepted` → `completed` (by assigned crafter or confirmed by requester)
   - `pending` | `accepted` → `cancelled` (by requester)
3. **Crafter & Requester Views**:
   - Crafters have an **Incoming Requests** queue filtered by status (`pending`, `accepted`, history) with actions to **Accept**, **Deny** (with optional note), or **Mark Completed**.
   - Requesters have an **Outgoing Requests** view showing status, required reagents, assigned crafter character, and a **Cancel** action.
   - Every status transition emits an event to notify the counterpart via Discord DM (and/or guild notification channel).

---

## 3. Desktop Companion App (`desktop/`)

### Stack
- **Framework**: Rust + Tauri (lightweight native binary, system tray support).

### Functional Requirements
1. **Authentication & Secure Credential Storage**:
   - Require user login against the Flynn API before enabling uploads.
   - Persist session/API tokens exclusively in the OS native credential store (macOS Keychain, Windows Credential Manager, Linux Secret Service); handle 401/expired tokens gracefully by prompting re-authentication.
2. **WoW Directory & `SavedVariables` Watcher**:
   - Auto-detect standard World of Warcraft install paths per OS (with manual folder picker override).
   - Scan `<WoW Root>/{_retail_,_classic_era_,_classic_,_forever_}/WTF/Account/*/SavedVariables/Flynn.lua`.
   - Watch discovered `Flynn.lua` files using OS file-system events with debounce (waiting for file writes to settle on WoW `/reload` or exit) and SHA-256 content hashing so unchanged files are never re-uploaded.
3. **Upload Controls & Settings**:
   - Support both **Automatic Sync** (on file change) and a manual **Upload Now** button.
   - Display sync status, last upload timestamp, characters updated, and clear error diagnostics (e.g., network failure, invalid file, expired login).
   - Settings view: WoW installation path, per-account/flavor sync toggles, auto-sync on/off, launch on OS startup, minimize to system tray, and account logout.

---

## 4. Discord Bot (`bot/`)

### Stack
- **Language**: Rust (Discord gateway/interactions client + async HTTP client communicating exclusively through the Flynn Backend API).

### Functional Requirements
1. **Guild & Member Authorization**:
   - Resolve the Discord Server (`discord_guild_id`) to its linked Flynn Guild and verify the invoking Discord user (`discord_user_id`) is linked to a Flynn account with an `approved` membership in that guild. Provide clear onboarding instructions/links if unlinked or pending approval.
2. **Recipe Search & Crafter Discovery**:
   - Slash commands with autocomplete (e.g., `/craft search`, `/craft add`) scoped to the linked guild's game flavor.
   - Show which approved guild characters know the recipe, their profession skill/rank, and when their data was last synced.
3. **Interactive Ephemeral Shopping Cart**:
   - Maintain a per-user, per-guild active cart (persisted via API or bot state with reasonable TTL) rendered via ephemeral Discord embeds and interactive components (buttons & select menus).
   - **Cart Item Controls**: Add recipe, set quantity, choose target crafter from eligible guild crafters, specify whether materials are provided (`Have Mats` / `Need Mats`), add a note, remove item, or clear cart.
   - **Aggregated Reagent Summary**: Continuously compute and display the combined bill of materials (total quantity of every reagent across all cart items and quantities) alongside a per-item / per-crafter routing preview showing exactly who will receive each request.
4. **Checkout & Lifecycle Notifications**:
   - **Submit (`Send Requests`)**: Atomically submits the cart to the Flynn API, creating requests routed to the selected crafters and clearing the cart.
   - **Crafter & Requester Alerts**:
     - Notify assigned crafters via Discord DM (and optionally a configured guild crafting channel) detailing the requested item(s), quantities, required reagents, requester character/Discord tag, and interactive `Accept` / `Deny` / `Complete` buttons synced with the API.
     - Notify the requester via Discord DM whenever a crafter accepts, denies (including note), or completes their request.

---

## 5. Cross-Cutting Standards & Data Contracts

- **Schema Versioning**: All addon exports (`SavedVariables` and export strings) must include an integer `schemaVersion` so the API and Desktop App can support backward-compatible migrations or return actionable "please update your Flynn addon" messages.
- **Single Source of Truth**: Only the Laravel backend owns persistent domain state and business rules; the Desktop App and Discord Bot act as authenticated clients of the versioned HTTP API (`/api/v1/*`).
- **Rate Limiting & Abuse Prevention**: Apply API rate limits on dump uploads, authentication endpoints, and bot/webhook mutations; enforce maximum file size limits on uploaded `.lua` files and export strings.
