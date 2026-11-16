# API Limits, Validation, and Ingestion Errors

This document defines the first Flynn API resource limits and validation policy. All size units use MiB (`1 MiB = 1,048,576` bytes). Limits apply before parsing and again to decoded/decompressed content where applicable. These are shared API limits; the backend may lower a limit temporarily for service protection, but must return an actionable response rather than silently truncating data.

## Request and dump size limits

| Input | Maximum |
| --- | ---: |
| General JSON request body | 1 MiB |
| `Flynn.lua` SavedVariables file | 10 MiB |
| Structured JSON dump document | 10 MiB |
| `FLYNN1:` export string, including prefix | 4 MiB UTF-8 bytes |
| Decompressed `FLYNN1:` JSON document | 20 MiB |
| Individual user-provided notes | 2,000 Unicode characters |

The general JSON body limit does not replace the dump-specific limits on `POST /api/v1/dumps`. The export-string limit is checked before Base64 decoding; the decompressed-document limit is checked while inflating, before JSON parsing. The decoder must stop once the decoded cap is exceeded and must not allocate based on an untrusted declared size. Payloads over a limit return `413 Payload Too Large` with `error.code: "payload_too_large"`, the applicable `limitBytes`, and a message that says which upload format/limit to observe. No partial ingestion is performed.

## Ingestion and parser resource limits

After syntactic parsing, enforce these shape caps on a single dump:

| Contract element | Maximum |
| --- | ---: |
| Characters | 100 |
| Professions per character | 20 |
| Recipes per profession | 5,000 |
| Recipes across one dump | 50,000 |
| Outputs per recipe | 20 |
| Reagents per recipe | 100 |
| Reagent slots per recipe | 100 |
| Choices per reagent slot | 100 |
| Total parsed object/table entries | 500,000 |
| Nesting depth | 32 |

For `Flynn.lua`, use a deterministic lexer/parser for the SavedVariables literal format. Never `include`, `eval`, execute, or otherwise load the uploaded Lua in a Lua runtime. Accept exactly one `FlynnDB = <table>` assignment, with optional comments and a trailing statement terminator. Values may be nested tables, strings, booleans, and finite numbers; keys may be strings or positive integer indices, with ordinary array entries accepted. Permit only the literal syntax needed by the addon's serializer (single- or double-quoted short strings with Lua 5.1 escapes, bracketed keys, commas, and `true`/`false`). Omitted Lua fields represent absent/nullable optional values. Reject function calls, operators, variable references, metatables, duplicate keys, multiple assignments, non-finite numbers, and unsupported syntax. Integer-indexed arrays must be contiguous and start at 1. Enforce byte, depth, and entry-count caps during parsing, not after building an unbounded in-memory object.

For JSON, require valid UTF-8, reject duplicate object keys, non-finite numbers, trailing content, and documents containing multiple top-level values. `FLYNN1:` input must have the exact prefix, valid Base64, valid Deflate data, and one UTF-8 JSON document after decompression. Reject corrupted or truncated compressed data.

## Data-contract validation

The structured document must conform to [data-contract.md](data-contract.md), including the following checks before any writes:

- `schemaVersion` is a JSON integer (not a boolean or fractional number) and is supported by the server.
- `characters` is a non-empty map. Each flavor, region, realm, and character-name namespace key is supported/non-empty, and exactly matches those fields in its character record.
- Supported flavors are `retail`, `classic_era`, `classic`, and `forever`; supported regions are `us`, `eu`, `kr`, `tw`, and `cn`. Each flavor has its own character, guild, recipe, and catalog namespace; WoW Forever must not fall back to another flavor's catalog. The offline catalog importer assigns rows to the flavor supplied by the operator but does not verify snapshot provenance, so only a verified Forever-specific snapshot should be imported as `forever`.
- Required character fields have the documented types. `capturedAt` is a positive Unix timestamp in seconds; `guid` is a non-empty string; `faction` is one of `alliance`, `horde`, `neutral`, or `unknown`; `class` is a WoW class token or `UNKNOWN`.
- Profession `skillLineID` and recipe `spellID` values are positive safe integers. Profession IDs are unique per character; recipe spell IDs are unique per profession. `currentSkill` and `maxSkill` are non-negative safe integers and `currentSkill <= maxSkill`.
- Item identifiers and quantities are positive safe integers. Output/reagent quantities and slot quantities may not exceed 1,000,000. A present specialization `id` is a positive safe integer. Recipe `quality.maxRank` and quality ranks are positive safe integers; ranks do not exceed `maxRank` and are unique within the quality output mapping. Reagent `slotIndex` values are unique non-negative safe integers; slot kind is `required`, `optional`, or `modifying`.
- Optional metadata may be omitted where the client cannot provide it. A present optional field must still have the documented shape. Unknown additive object properties are ignored by version 1 consumers after their value has passed the parser's resource limits.
- Strings must be valid Unicode. Character/realm names and GUIDs are at most 128 characters; optional specialization display names are at most 128 characters. User notes are at most 2,000 characters.
- Duplicate character identity keys, duplicate indexed records, sparse arrays, mismatched namespace fields, or counts above the caps are rejected. Validation of the entire submitted dump completes before any character is changed; a failed document does not partially update records.

The JSON contract uses `null` only where explicitly allowed (currently `specialization`). Lua SavedVariables omit that field instead, because Lua `nil` does not persist as a table value. API validation must normalize both representations to the same internal meaning.

Guild and request mutations receive corresponding domain validation: guild names are trimmed and 2–100 characters; flavor and region must be supported; realm is required and at most 128 characters; Discord IDs are decimal strings; membership actions require owner/officer authority and valid current state; character-to-guild assignments must match the guild's flavor/region/realm; recipe/crafter choices must exist in the guild's eligible data; `materialsProvided` must be `requester`, `crafter`, or `partial`; quantities must be positive integers no greater than 10,000; and requester/crafter notes are limited to 2,000 characters. Status changes must follow the request state machine in [api-contract.md](api-contract.md). Invalid fields return `422`; unauthorized actions return `403`; stale or illegal state transitions return `409`.

## Unsupported schemas and ingestion errors

An absent, malformed, or unsupported version is rejected before mutation. For a well-formed integer version that the server does not support, return `422 Unprocessable Entity` with a stable error code and explicit update guidance:

```json
{
  "error": {
    "code": "unsupported_schema_version",
    "message": "This Flynn data uses schema version 2, but this server supports version 1. Update the Flynn addon or use a server release that supports this data version, then export again.",
    "details": {
      "receivedSchemaVersion": 2,
      "supportedSchemaVersions": [1],
      "action": "update_addon_or_server"
    },
    "requestId": "req_opaque_id"
  }
}
```

Use `invalid_schema_version` when the version is missing or is not an integer; its message must tell the user that the payload needs a numeric `schemaVersion` and to update/re-export with the Flynn addon. Other stable ingestion error codes are:

| Code | Meaning and user guidance |
| --- | --- |
| `invalid_saved_variables` | The Lua file is malformed or is not a supported literal `FlynnDB` document; ask the user to verify the file came from the Flynn addon and retry after `/reload` or logout. |
| `invalid_export_string` | Prefix, Base64, Deflate stream, UTF-8, or JSON is invalid; copy the complete export string again. |
| `invalid_payload` | The JSON/data shape is malformed or violates the documented contract; return field paths in `details` when safe. |
| `invalid_schema_version` | `schemaVersion` is missing or not an integer; request a fresh export from a current Flynn addon. |
| `payload_too_large` | A raw, encoded, decoded, or structural limit was exceeded; include the applicable limit and format. |
| `unsupported_schema_version` | The payload is well-formed but requires a schema version this server does not support; include received/supported versions and update guidance. |

For an asynchronous ingestion accepted with `202`, report parse/schema/record failures on `GET /api/v1/dumps/{dumpId}` using these same codes and actionable messages. Do not expose stack traces, filesystem paths, uploaded source text, or secrets in client errors. Keep diagnostics correlated by `requestId`/`dumpId` without logging full uploaded payloads.

## Rate limits

Limits are per authenticated account or bot actor, and per source IP for unauthenticated flows. For a request subject to multiple limits, the strictest applicable quota wins. Bot request quotas are keyed by both Discord user ID and Discord guild ID. Do not count each item in one atomic cart checkout as a separate HTTP request, but cap the request body and cart size using the same API resource protections.

| Operation | Limit |
| --- | ---: |
| General authenticated API requests | 120 requests/minute/account |
| Unauthenticated API requests | 60 requests/minute/IP |
| Login attempts | 5 attempts/15 minutes/account and IP |
| Registration attempts | 5 attempts/hour/IP |
| Discord OAuth start/callback | 10 attempts/10 minutes/account and IP |
| Dump submissions | 10 uploads/hour/account and 100 uploads/day/account |
| Bot recipe/crafter reads and autocomplete | 60 requests/minute/Discord user + guild |
| Bot cart/request mutations | 20 requests/minute/Discord user + guild |
| Other guild and membership mutations | 30 requests/minute/account |

Rate-limit exhaustion returns `429 Too Many Requests`, `error.code: "rate_limited"`, and a `Retry-After` header in seconds. The response message says when the caller can retry. Rate limits do not replace authentication, authorization, payload caps, or job-queue capacity controls.
