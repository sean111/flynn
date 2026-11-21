# Flynn HTTP API Contract

This document defines the first version of the Flynn application API. All application endpoints are rooted at `/api/v1`. The Laravel backend is the sole authority for persistent domain state, authorization, and business rules. The web frontend, desktop companion, and Discord bot are API clients; they must not independently create authoritative guild, membership, character, recipe, cart, or crafting-request state.

The character/profession/recipe payload accepted by dump endpoints is defined in [data-contract.md](data-contract.md).
Payload caps, validation rules, rate limits, and ingestion error codes are defined in [api-limits-and-validation.md](api-limits-and-validation.md).
Database migrations and queued-job operation are defined in [database-and-jobs.md](database-and-jobs.md).

## Conventions

- Requests and responses use UTF-8 JSON unless an endpoint explicitly accepts multipart form data or performs an OAuth redirect.
- JSON property names use `camelCase`. WoW identifiers retain their contract spelling, such as `skillLineID`, `spellID`, and `itemID`.
- Resource IDs are opaque strings (UUIDs for Flynn-owned records). WoW identifiers are JSON integers. Discord snowflake IDs are decimal strings so clients do not lose precision.
- API timestamps are ISO 8601 UTC strings. `capturedAt` inside character data remains a Unix timestamp in seconds as defined by the data contract.
- Collection endpoints support cursor pagination and return `data` plus `nextCursor` (null when there are no more results). Filters are documented per endpoint.
- State-changing endpoints return the canonical updated resource. Clients should use server-returned state rather than treating local UI state as authoritative.

## Authentication and acting identity

### Web client

The first-party web application uses an authenticated Laravel web session with CSRF protection for state-changing requests. `GET /api/v1/me` returns the signed-in account. Browser requests do not need a personal access token.

### Desktop client

The desktop application obtains a short-lived PKCE authorization code through the user's browser, then exchanges it at `POST /api/v1/auth/desktop/token`. The exchange returns a Sanctum bearer token and its expiry. Send it as `Authorization: Bearer <token>`. Personal tokens are revocable; an expired or revoked token returns `401` and the client must ask the user to authenticate again. Tokens are issued with only the abilities required by the approved desktop client (account identity and dump upload).

### Discord bot

The bot authenticates as a service using a scoped service bearer credential or the deployment's configured HMAC request authentication. Every bot request acting for a user must include both:

```http
X-Flynn-Discord-User-ID: 123456789012345678
X-Flynn-Discord-Guild-ID: 234567890123456789
```

The backend verifies the service credential, resolves those Discord identities to linked Flynn identities and guild membership, and applies the same approval and role rules as the web API. These headers are claims to check, not authorization by themselves. Bot endpoints must reject missing or mismatched actor context.

## Common responses

Successful resource responses use `{ "data": ... }`. Errors use this shape:

```json
{
  "error": {
    "code": "forbidden",
    "message": "You are not an approved member of this guild.",
    "details": {},
    "requestId": "req_opaque_id"
  }
}
```

`code` is a stable machine-readable value; `message` is safe to show to a user; `details` may contain field errors or relevant resource state; and `requestId` supports diagnostics. The API uses standard HTTP status semantics: `200` for reads/updates, `201` for synchronous creation, `202` for queued work, `204` for successful deletion with no response body, `401` for missing/invalid authentication, `403` for an authenticated but unauthorized actor, `404` for inaccessible or missing resources, `409` for state conflicts, and `422` for semantically invalid input. Ingestion-specific validation codes, payload limits, and rate-limit policy are defined by their respective project tasks.

## Endpoint catalog

`{id}` path values below are opaque Flynn resource IDs. Endpoints marked **web** use the browser session; **desktop** use a personal bearer token; **bot** use service authentication and Discord actor context. Where multiple client types are listed, each is subject to its corresponding authorization rules.

### Account and identity

| Method and path | Client | Contract |
| --- | --- | --- |
| `POST /auth/register` | web | Create an account from `email` and `password`; returns the account and starts an authenticated session. |
| `POST /auth/login` | web | Authenticate with `email` and `password`; returns the account and starts a session. |
| `POST /auth/logout` | web | End the current browser session. |
| `GET /me` | web, desktop | Return the current Flynn account and linked Discord identity, if any. |
| `PATCH /me` | web | Update permitted account profile fields. Email/password changes require the account security flow. |
| `GET /auth/discord/redirect` | web | Begin Discord OAuth2 linking for the current account. |
| `GET /auth/discord/callback` | web | Complete OAuth2 linking after server-side verification; persist the verified Discord user ID and handle. |
| `DELETE /me/discord` | web | Unlink the current account's Discord identity. The account can no longer act through the bot until Discord is linked again. |
| `GET /auth/desktop/authorize` | browser redirect | Start the desktop PKCE authorization flow; require signed-in user consent and preserve the PKCE challenge and state. |
| `POST /auth/desktop/token` | desktop bootstrap | Exchange a one-time authorization code and `codeVerifier` for a Sanctum bearer token; returns `accessToken`, `tokenType`, `expiresAt`, and granted `abilities`. |
| `DELETE /auth/tokens/{id}` | web | Revoke one of the current user's desktop tokens. |

Browser registration/login routes may use Laravel's session middleware; the `/api/v1` prefix identifies the application contract, not a requirement that OAuth redirects return JSON.

### Guilds, discovery, and membership

| Method and path | Client | Contract |
| --- | --- | --- |
| `GET /guilds` | web | Discover guilds; supports query and flavor/region/realm filters. Only discoverable guild information is returned. |
| `POST /guilds` | web | Create a guild with `name`, `gameFlavor`, `region`, `realm`, and optional `discordGuildID`. Creator becomes `owner`. |
| `GET /guilds/{guildId}` | web, bot | Return guild details visible to the actor. |
| `PATCH /guilds/{guildId}` | web | Owner/officer updates permitted guild settings. |
| `POST /guilds/{guildId}/discord-link/verify` | web | Verify bot presence and required Discord guild permissions before linking the Discord guild. |
| `POST /guilds/{guildId}/invites` | web | Owner/officer creates an invite; returns an opaque invite token/link and its expiry. |
| `POST /guild-invites/{inviteToken}/applications` | web | Apply to the invited guild. A valid application always creates a `pending` membership. |
| `POST /guilds/{guildId}/applications` | web | Apply to a discoverable guild; creates a `pending` membership. |
| `GET /guilds/{guildId}/members` | web | Owner/officer lists members and pending applications; members see only the membership information allowed by guild policy. |
| `POST /guilds/{guildId}/members/{membershipId}/approve` | web | Owner/officer approves a pending application. |
| `POST /guilds/{guildId}/members/{membershipId}/reject` | web | Owner/officer rejects a pending application. |
| `POST /guilds/{guildId}/members/{membershipId}/revoke` | web | Owner/officer revokes an approved membership. |
| `PATCH /guilds/{guildId}/members/{membershipId}/role` | web | Owner changes an approved member's role to/from `officer`, subject to ownership safeguards. |

Membership statuses are `pending`, `approved`, `rejected`, and `revoked`. Only `approved` members may associate characters, appear as guild crafters, or create/fulfill guild requests. Role and guild-scope checks are performed by the backend on every operation.

### Characters and dumps

| Method and path | Client | Contract |
| --- | --- | --- |
| `GET /characters` | web | List characters owned/claimed by the current account. |
| `GET /characters/{characterId}` | web | Return character metadata and profession/recipe summary. |
| `POST /characters/{characterId}/claim` | web | Claim an unclaimed imported character for the current account, subject to ownership/claim rules. |
| `DELETE /characters/{characterId}` | web | Remove a character from the current account; backend applies reference/ownership policy. |
| `PUT /characters/{characterId}/guilds/{guildId}` | web | Associate a character with a guild. Requires approved membership and matching game flavor and region/realm. |
| `DELETE /characters/{characterId}/guilds/{guildId}` | web | Remove that character's guild association. |
| `POST /dumps` | web, desktop | Submit a SavedVariables file, structured JSON data-contract document, or `FLYNN1:` export for asynchronous ingestion. See formats below. |
| `GET /dumps/{dumpId}` | web, desktop | Return the authenticated uploader's ingestion status and per-character results/errors. |

`POST /dumps` accepts either:

1. `multipart/form-data` with a `file` part containing `Flynn.lua`; or
2. `application/json` with `{ "format": "structuredJson", "payload": <data-contract document> }` or `{ "format": "exportString", "payload": "FLYNN1:..." }`.

The web page accepts either a `FLYNN1:` string or pasted full `Flynn.lua` contents. Pasted Lua is detected after leading whitespace and Lua line/block comments, then submitted through the same multipart `file` path using the filename `Flynn.lua`. An unsupported prefix such as `FLYNN2:` is sent as an export string so the API can return the specific export-format error.

Every format is synchronously parsed and data-contract validated before it is staged or accepted; invalid requests receive the parser's `4xx` error and do not create a dump row or enqueue work. The ingestion job repeats parsing/validation as defense in depth. On acceptance, the endpoint responds `202 Accepted` with `{ "data": { "dumpId": "...", "status": "queued" } }`. `GET /dumps/{dumpId}` reports `queued`, `processing`, `completed`, or `failed`, counts/results, and `error` (`null` unless terminal processing failed; otherwise `{ "code": "...", "message": "..." }`). Clients should show that terminal message and the dump ID for diagnostics, while surfacing status-polling failures separately. Uploads are never evaluated as Lua. The importer validates the data contract, deduplicates repeated reagent-slot options by `itemID` while preserving the first occurrence, then performs an idempotent upsert and prunes professions/recipes absent from the new snapshot for the same character identity. `parseExport()` maps envelope, Base64, Deflate, UTF-8, and JSON syntax problems to `invalid_export_string`, but preserves payload-size and schema/domain validation error codes. See [api-limits-and-validation.md](api-limits-and-validation.md) for file-size limits, schema rejection details, and upload throttling.

### Recipe search and crafter discovery

| Method and path | Client | Contract |
| --- | --- | --- |
| `GET /guilds/{guildId}/recipes` | web, bot | Search recipes available to approved guild crafters. Supports `query`, `gameFlavor`, and cursor pagination; flavor must match the guild. |
| `GET /guilds/{guildId}/recipes/{spellId}` | web, bot | Return recipe details, outputs, reagents, optional quality/slot data, and eligible crafter summaries. |
| `GET /guilds/{guildId}/recipes/{spellId}/crafters` | web, bot | List approved guild characters knowing the recipe, profession rank, owner, and `lastSyncedAt`. |

Search and discovery results include only characters assigned to that guild and owned by approved members. The backend scopes results by the guild's flavor and region/realm rules, and returns freshness timestamps so clients can communicate sync age.

Supported flavor values are `retail`, `classic_era`, `classic`, and `forever`. WoW Forever is a distinct domain namespace; catalog rows are scoped by the flavor supplied to the offline importer, which cannot independently establish snapshot provenance. Operators must only import a verified Forever-specific snapshot as `forever`. No verified Forever catalog is currently supplied, so responses preserve numeric-ID fallback labels rather than borrowing another client's catalog.

### Carts and crafting requests

Cart operations are scoped to the current web user or the authenticated bot actor plus guild. A cart is temporary application state owned by the backend and expires after an implementation-defined idle period.

| Method and path | Client | Contract |
| --- | --- | --- |
| `GET /guilds/{guildId}/cart` | web, bot | Return the actor's active cart, items, routing preview, and aggregated reagent totals. |
| `POST /guilds/{guildId}/cart/items` | web, bot | Add a recipe with `spellID`, positive `quantity`, optional `crafterCharacterId`, `materialsProvided` (`requester`, `crafter`, or `partial`), optional `recipientCharacterName`, and optional requester `notes`. |
| `PATCH /guilds/{guildId}/cart/items/{itemId}` | web, bot | Change quantity, eligible crafter, materials source, recipient, or note. |
| `DELETE /guilds/{guildId}/cart/items/{itemId}` | web, bot | Remove one item. |
| `DELETE /guilds/{guildId}/cart` | web, bot | Clear the active cart. |
| `POST /guilds/{guildId}/cart/checkout` | web, bot | Atomically create one pending request per cart item and clear the cart only if the entire operation succeeds. Accepts an `Idempotency-Key`; retries with the same key return the original result. |
| `GET /me/requests/incoming` | web | List requests assigned to the current user's guild characters; supports status filter and cursor. |
| `GET /me/requests/outgoing` | web | List requests created by the current user; supports status filter and cursor. |
| `GET /requests/{requestId}` | web, bot | Return a request visible to the actor, including status history, reagents, assigned crafter character, and notes visible to that actor. |
| `POST /requests/{requestId}/accept` | web, bot | Assigned crafter accepts a pending request. |
| `POST /requests/{requestId}/deny` | web, bot | Assigned crafter denies a pending request; optional body `{ "reason": "..." }`. |
| `POST /requests/{requestId}/complete` | web, bot | Assigned crafter marks an accepted request completed. |
| `POST /requests/{requestId}/completion-confirmation` | web, bot | Requester confirms completion of an accepted request. |
| `POST /requests/{requestId}/cancel` | web, bot | Requester cancels a pending or accepted request. |

A request records `guildId`, requester user and optional recipient character, crafter user/character, `spellID`, positive `quantity`, `materialsProvided`, requester/crafter notes, status, and transition timestamps. Statuses are `pending`, `accepted`, `denied`, `completed`, and `cancelled`; only these transitions are allowed:

- `pending` → `accepted` or `denied` by the assigned crafter;
- `accepted` → `completed` by the assigned crafter or requester confirmation;
- `pending` or `accepted` → `cancelled` by the requester.

Each successful transition is persisted by the backend and emits one domain notification event for delivery to the counterpart. Duplicate or stale transition requests return `409 Conflict` and do not emit another event. The API response includes the updated request; Discord delivery is asynchronous and is not part of the state-transition transaction's success response.

## Ownership boundary

The backend authorizes and persists account identities, Discord links, guilds, memberships, character claims and assignments, imported profession/recipe snapshots, carts, requests, and transition history. Desktop and bot clients may cache data for presentation, but the backend remains the source of truth. The bot uses the API for domain reads and writes and must not write directly to the Flynn database.
