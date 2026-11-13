# Flynn Character Data Contract

This document defines the versioned character, profession, and recipe payload shared by the WoW addon, Flynn API, and desktop companion. The API may store the data in relational tables, but inputs and exports use this contract rather than application-specific database shapes.

## Versioning

- The current contract version is integer `schemaVersion: 1`.
- Every `FlynnDB` SavedVariables document and every decoded `FLYNN1:` export document has exactly one top-level `schemaVersion` field.
- `FLYNN1:` identifies the export format/schema generation; its decoded JSON document must still carry the numeric `schemaVersion` field.
- Version 1 consumers may ignore unknown object properties. They must not silently reinterpret a payload with an unsupported `schemaVersion`; actionable rejection behavior is specified with the API validation contract.
- Changes that preserve the meaning and required shape of existing fields may be additive. Changes to required fields, types, or meanings require a new schema version.

## Top-level document and character namespace

The SavedVariables global is `FlynnDB`. Its value is an object/table containing `schemaVersion` and a `characters` map. Characters are keyed by the exact tuple `[gameFlavor][region][realm][characterName]`; the same values are repeated in each character record so uploaded records can be validated independently. A one-character export uses the same envelope and contains only that character.

Supported `gameFlavor` values are:

| Value | WoW client |
| --- | --- |
| `retail` | Retail (`_retail_`) |
| `classic_era` | Classic Era / Forever (`_classic_era_`) |
| `classic` | Classic Progression / Anniversary (`_classic_`) |

`region` is the lowercase WoW region code: `us`, `eu`, `kr`, `tw`, or `cn`. Realm and character-name keys use the WoW-provided names without locale-independent transliteration. They are case-sensitive and must match their corresponding record fields.

## Field definitions

### Character record

| Field | Type | Meaning |
| --- | --- | --- |
| `gameFlavor` | string | One of the three supported flavor values above. |
| `region` | string | Lowercase region code. |
| `realm` | string | WoW-provided realm name. |
| `characterName` | string | WoW character name. |
| `guid` | string | WoW character GUID as returned by the client. |
| `faction` | string | `alliance`, `horde`, `neutral`, or `unknown`. |
| `class` | string | WoW class token (for example, `MAGE`); use `UNKNOWN` if unavailable. |
| `capturedAt` | integer | UTC Unix timestamp in seconds when this character's data was captured. |
| `professions` | array | Captured professions; empty when none are known or available. |

The character identity is the flavor, region, realm, and character-name tuple. `guid` is a useful client identifier but does not replace that namespaced identity.

### Profession record

| Field | Type | Meaning |
| --- | --- | --- |
| `skillLineID` | positive integer | WoW profession/skill-line identifier; unique in a character's profession list. |
| `currentSkill` | non-negative integer | Current profession rank. |
| `maxSkill` | non-negative integer | Maximum rank for the current tier. |
| `specialization` | object or `null` | Specialization data when supported/available; see below. |
| `recipes` | array | Known recipes for this profession; empty when none are known. |

`specialization` may contain a numeric `id` and an optional display `name`. Omit it or set it to `null` when the client has no applicable specialization data. Localized names are descriptive only and must not be used as identifiers.

### Recipe, output, and reagent records

| Field | Type | Meaning |
| --- | --- | --- |
| `spellID` | positive integer | Recipe spell identifier; unique within its profession. |
| `outputs` | array | Items produced by the recipe. |
| `reagents` | array | Known recipe reagent item IDs and quantities. |
| `quality` | object, optional | Retail recipe/output quality metadata when exposed by the client. |
| `reagentSlots` | array, optional | Retail crafting reagent-slot metadata when exposed by the client. |

Each output is `{ "itemID": positive integer, "quantity": positive integer }`. Each reagent is `{ "itemID": positive integer, "quantity": positive integer }`. Store numeric identifiers and quantities, not localized tooltip text. Where a client cannot provide optional metadata, omit that property rather than inventing a value.

`quality` contains `maxRank` (positive integer) and may contain `outputs`, an array of `{ "rank": positive integer, "itemID": positive integer, "quantity": positive integer }` mappings for quality-specific outputs.

Each `reagentSlots` entry contains:

| Field | Type | Meaning |
| --- | --- | --- |
| `slotIndex` | non-negative integer | Position of this slot in the recipe. |
| `kind` | string | `required`, `optional`, or `modifying`. |
| `quantity` | positive integer | Required quantity when the slot is filled. |
| `options` | array | Allowed item choices, each `{ "itemID": positive integer }`. |

The `reagents` list represents the recipe's known reagent requirements; `reagentSlots` preserves richer slot/choice metadata and does not replace the compact reagent list. Slot indices are zero-based. Do not include an item choice if the client did not report it.

## Example JSON representation

This is the JSON-equivalent document shape. WoW persists the same table data as the `FlynnDB` Lua SavedVariables global; JSON is used here to make the cross-application contract explicit.

```json
{
  "schemaVersion": 1,
  "characters": {
    "retail": {
      "us": {
        "Area 52": {
          "Examplemage": {
            "gameFlavor": "retail",
            "region": "us",
            "realm": "Area 52",
            "characterName": "Examplemage",
            "guid": "Player-3676-0ABCDEF0",
            "faction": "horde",
            "class": "MAGE",
            "capturedAt": 1794528000,
            "professions": [
              {
                "skillLineID": 171,
                "currentSkill": 100,
                "maxSkill": 100,
                "specialization": {
                  "id": 1234,
                  "name": "Example Specialization"
                },
                "recipes": [
                  {
                    "spellID": 12345,
                    "outputs": [
                      { "itemID": 67890, "quantity": 1 }
                    ],
                    "reagents": [
                      { "itemID": 11111, "quantity": 3 }
                    ],
                    "quality": {
                      "maxRank": 5,
                      "outputs": [
                        { "rank": 1, "itemID": 67890, "quantity": 1 }
                      ]
                    },
                    "reagentSlots": [
                      {
                        "slotIndex": 0,
                        "kind": "required",
                        "quantity": 3,
                        "options": [ { "itemID": 11111 } ]
                      },
                      {
                        "slotIndex": 1,
                        "kind": "optional",
                        "quantity": 1,
                        "options": [ { "itemID": 22222 } ]
                      }
                    ]
                  }
                ]
              }
            ]
          }
        }
      }
    }
  }
}
```

Classic clients, including Classic Era Enchanting, use the same profession/recipe/output/reagent records. They omit Retail-only `quality` and `reagentSlots` when those details are not available.
