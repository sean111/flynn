# Flynn Shared Export and Import Data Contract

This document is the shared export/import format source of truth for `addon/`, `web/`, `desktop/`, and `bot/`. The web API may store imported data in relational tables, but every component exchanges the versioned format defined here rather than application-specific database shapes.

## Versioning and envelopes

- The current contract version is integer `schemaVersion: 1`.
- A `Flynn.lua` SavedVariables document has one top-level `schemaVersion` and a `characters` namespace map. Character records emitted by current add-ons also include `schemaVersion: 1`; v1 consumers accept an omitted per-character value from older v1 clients but validate it when present.
- A decoded `FLYNN1:` export contains top-level `schemaVersion` and selected `characters`, and omits local `settings`.
- Version 1 consumers may ignore unknown object properties after parsing/resource checks. They must reject unsupported schema versions rather than silently reinterpret them.
- Additions that preserve existing field meanings may be made within v1. Changes to required fields, types, or meanings require a new version.

## SavedVariables (`Flynn.lua`)

The file contains one assignment, `FlynnDB = <table>`, with top-level `schemaVersion`, `characters`, and optional local `settings`. Comments and an optional trailing semicolon are permitted. The web importer parses this as a bounded literal; it never executes Lua.

The `characters` table is keyed exactly by `[gameFlavor][region][realm][characterName]`. Those four identity values are repeated in the leaf character record and must match the namespace. Arrays use 1-indexed Lua positions and must be contiguous. Lua's empty table `{}` represents an empty array or map; context determines its meaning. Empty parent namespace maps may remain after a character is ignored, and importers skip those empty branches while still validating namespace keys and rejecting non-table or list-shaped branches.

`FlynnDB.settings` is optional, local addon preference data and is not imported as Flynn domain state. The `tracked`, `ignored`, and `capturedProfessions` maps use the compound string key. `capturedProfessions[identityKey]` is `{ complete = true }` for a complete captured profession snapshot, or `{}` when incomplete; it is not a list of skill-line IDs:

```text
<flavor><0x1F><region><0x1F><realm><0x1F><characterName>
```

`0x1F` is the ASCII Unit Separator byte. It may appear literally in a string, as a Lua decimal escape such as `\031`, or as a Lua hexadecimal escape such as `\x1f`. Preference values are implementation details of the addon; the API does not create character, profession, or recipe records from `settings`.

## In-game export (`FLYNN1:`)

An in-game export is `FLYNN1:` followed by RFC 4648 standard Base64 of raw RFC 1951 Deflate-compressed UTF-8 JSON. The JSON contains `schemaVersion` and the selected `characters` only, omitting `settings`. This is not Base64-encoded Lua and is not a gzip stream. Its decoded JSON follows the same character contract below.

## Namespaces and supported clients

| `gameFlavor` | WoW client |
| --- | --- |
| `retail` | Retail (`_retail_`) |
| `classic_era` | Classic Era (`_classic_era_`) |
| `classic` | Classic Progression / Anniversary (`_classic_`) |
| `forever` | WoW Forever / Camelot (`_forever_`) |

`region` is one of `us`, `eu`, `kr`, `tw`, or `cn`. Realm and character-name keys use the WoW-provided names without transliteration. Names are case-sensitive and must match the record. A character identity is the full flavor/region/realm/name tuple; `guid` does not replace it.

## Field semantics

### Character and profession records

| Field | Type | Meaning |
| --- | --- | --- |
| `schemaVersion` | positive integer, optional in older v1 character records | Must match the top-level version when present. |
| `gameFlavor` | string | Supported flavor matching the namespace. |
| `region` | string | Supported lowercase region matching the namespace. |
| `realm`, `characterName` | string | Names matching the namespace; at most 128 characters. |
| `guid` | string | WoW-provided character identifier; at most 128 characters. |
| `faction` | string | `alliance`, `horde`, `neutral`, or `unknown`. |
| `class` | string | WoW class token, or `UNKNOWN` if unavailable. |
| `capturedAt` | positive integer | UTC Unix timestamp in seconds. |
| `professions` | array | Captured profession records; empty when none are known/available. |

Profession fields are `skillLineID` (positive integer), `currentSkill` and `maxSkill` (non-negative integers with `currentSkill <= maxSkill`), optional `specialization`, and `recipes`. `specialization` may be an object with numeric `id` and optional display `name`, or JSON `null`; omit it if the client has no applicable data. The current addon omits specialization because it has no stable numeric specialization identifier to export.

### Recipe, output, and reagent records

| Field | Type | Meaning |
| --- | --- | --- |
| `spellID` | positive integer | Recipe spell identifier, unique within a profession. |
| `outputs` | array | Items produced. It may be empty for enchants and other non-item recipes. |
| `reagents` | array | Known fixed reagent item IDs and quantities. |
| `quality` | optional object | Quality metadata when the client exposes it. |
| `reagentSlots` | optional array | Structured required/optional/modifying slot and choice metadata when the client exposes it. |

Each output/reagent is `{ "itemID": positive integer, "quantity": positive integer }`. Store numeric identifiers and quantities, never localized tooltip text as identifiers. `quality` contains positive integer `maxRank` and may include `outputs` mappings with `rank`, `itemID`, and `quantity`.

Each `reagentSlots` record contains a zero-based `slotIndex`, `kind` (`required`, `optional`, or `modifying`), positive `quantity`, and `options` array of `{ "itemID": positive integer }`. A slot's `options` are choices, not a list of simultaneously required reagents. `reagents` remains the compact fixed/known bill of materials; slots preserve richer choice metadata and do not replace it. Duplicate option `itemID`s are interpreted once: importers retain the first occurrence and discard later duplicates. Slot indices are zero-based even though their containing Lua array is 1-indexed.

`quality` and `reagentSlots` are capability-driven metadata, not Retail-only fields. They may also be emitted by Classic/Forever modern adapters when native APIs expose the structure. Legacy clients omit unavailable metadata. Limits and validation are specified in [api-limits-and-validation.md](api-limits-and-validation.md).

## Paired privacy-safe examples

The following values are fabricated examples. The Lua and JSON SavedVariables-equivalent examples carry the same three professions, an item-producing recipe, an enchant with empty `outputs`, slot choices, and local preferences. Arrays are contiguous and 1-indexed in Lua.

### SavedVariables Lua

```lua
FlynnDB = {
  ["schemaVersion"] = 1,
  ["characters"] = {
    ["retail"] = {
      ["us"] = {
        ["Example Realm PvE"] = {
          ["Examplewarlock"] = {
            ["schemaVersion"] = 1,
            ["gameFlavor"] = "retail", ["region"] = "us", ["realm"] = "Example Realm PvE",
            ["characterName"] = "Examplewarlock", ["guid"] = "Player-0000-00000001",
            ["faction"] = "alliance", ["class"] = "WARLOCK", ["capturedAt"] = 1794528000,
            ["professions"] = {
              [1] = { ["skillLineID"] = 185, ["currentSkill"] = 75, ["maxSkill"] = 100, ["recipes"] = {} },
              [2] = {
                ["skillLineID"] = 197, ["currentSkill"] = 100, ["maxSkill"] = 100,
                ["recipes"] = { [1] = {
                  ["spellID"] = 376556,
                  ["outputs"] = { [1] = { ["itemID"] = 193922, ["quantity"] = 1 } },
                  ["reagents"] = { [1] = { ["itemID"] = 193208, ["quantity"] = 5 } },
                  ["reagentSlots"] = {
                    [1] = { ["slotIndex"] = 0, ["kind"] = "required", ["quantity"] = 5, ["options"] = { [1] = { ["itemID"] = 193208 } } },
                    [2] = { ["slotIndex"] = 1, ["kind"] = "optional", ["quantity"] = 1, ["options"] = { [1] = { ["itemID"] = 194123 }, [2] = { ["itemID"] = 194124 } } },
                  },
                } },
              },
              [3] = {
                ["skillLineID"] = 333, ["currentSkill"] = 80, ["maxSkill"] = 100,
                ["recipes"] = { [1] = {
                  ["spellID"] = 382994, ["outputs"] = {},
                  ["reagents"] = { [1] = { ["itemID"] = 190316, ["quantity"] = 2 } },
                  ["reagentSlots"] = { [1] = { ["slotIndex"] = 0, ["kind"] = "required", ["quantity"] = 2, ["options"] = { [1] = { ["itemID"] = 190316 } } } },
                } },
              },
            },
          },
        },
      },
    },
  },
  ["settings"] = {
    ["tracked"] = { ["retail\031us\031Example Realm PvE\031Examplewarlock"] = true },
    ["ignored"] = {},
    ["capturedProfessions"] = { ["retail\031us\031Example Realm PvE\031Examplewarlock"] = { ["complete"] = true } },
  },
}
```

### JSON-equivalent SavedVariables representation

```json
{
  "schemaVersion": 1,
  "characters": {
    "retail": {
      "us": {
        "Example Realm PvE": {
          "Examplewarlock": {
            "schemaVersion": 1,
            "gameFlavor": "retail", "region": "us", "realm": "Example Realm PvE",
            "characterName": "Examplewarlock", "guid": "Player-0000-00000001",
            "faction": "alliance", "class": "WARLOCK", "capturedAt": 1794528000,
            "professions": [
              { "skillLineID": 185, "currentSkill": 75, "maxSkill": 100, "recipes": [] },
              { "skillLineID": 197, "currentSkill": 100, "maxSkill": 100, "recipes": [{
                "spellID": 376556,
                "outputs": [{ "itemID": 193922, "quantity": 1 }],
                "reagents": [{ "itemID": 193208, "quantity": 5 }],
                "reagentSlots": [
                  { "slotIndex": 0, "kind": "required", "quantity": 5, "options": [{ "itemID": 193208 }] },
                  { "slotIndex": 1, "kind": "optional", "quantity": 1, "options": [{ "itemID": 194123 }, { "itemID": 194124 }] }
                ]
              }] },
              { "skillLineID": 333, "currentSkill": 80, "maxSkill": 100, "recipes": [{
                "spellID": 382994, "outputs": [],
                "reagents": [{ "itemID": 190316, "quantity": 2 }],
                "reagentSlots": [{ "slotIndex": 0, "kind": "required", "quantity": 2, "options": [{ "itemID": 190316 }] }]
              }] }
            ]
          }
        }
      }
    }
  },
  "settings": {
    "tracked": { "retail\u001fus\u001fExample Realm PvE\u001fExamplewarlock": true },
    "ignored": {},
    "capturedProfessions": { "retail\u001fus\u001fExample Realm PvE\u001fExamplewarlock": { "complete": true } }
  }
}
```

### Decoded `FLYNN1:` envelope

The UTF-8 JSON inside a `FLYNN1:` export is the same JSON-equivalent character document with the local `settings` property omitted. For the sample above, its decoded envelope is:

```json
{
  "schemaVersion": 1,
  "characters": {
    "retail": {
      "us": {
        "Example Realm PvE": {
          "Examplewarlock": {
            "schemaVersion": 1,
            "gameFlavor": "retail",
            "region": "us",
            "realm": "Example Realm PvE",
            "characterName": "Examplewarlock",
            "guid": "Player-0000-00000001",
            "faction": "alliance",
            "class": "WARLOCK",
            "capturedAt": 1794528000,
            "professions": [
              { "skillLineID": 185, "currentSkill": 75, "maxSkill": 100, "recipes": [] },
              { "skillLineID": 197, "currentSkill": 100, "maxSkill": 100, "recipes": [{
                "spellID": 376556,
                "outputs": [{ "itemID": 193922, "quantity": 1 }],
                "reagents": [{ "itemID": 193208, "quantity": 5 }],
                "reagentSlots": [
                  { "slotIndex": 0, "kind": "required", "quantity": 5, "options": [{ "itemID": 193208 }] },
                  { "slotIndex": 1, "kind": "optional", "quantity": 1, "options": [{ "itemID": 194123 }, { "itemID": 194124 }] }
                ]
              }] },
              { "skillLineID": 333, "currentSkill": 80, "maxSkill": 100, "recipes": [{
                "spellID": 382994, "outputs": [],
                "reagents": [{ "itemID": 190316, "quantity": 2 }],
                "reagentSlots": [{ "slotIndex": 0, "kind": "required", "quantity": 2, "options": [{ "itemID": 190316 }] }]
              }] }
            ]
          }
        }
      }
    }
  }
}
```

The decoded export is compressed with raw Deflate before standard Base64 encoding and is prefixed with `FLYNN1:`. No `settings` property is present in that payload.

## Flavor notes

Classic clients, including Classic Era Enchanting, use the same record semantics and omit unavailable quality/slot details. WoW Forever uses the modern adapter where supported but remains a separate `forever` namespace. Only import a verified Forever-specific catalog snapshot as `forever`; catalog provenance cannot be inferred from this character contract.
