# Flynn WoW Addon

Flynn captures profession recipes only while the player's own profession UI is open. It supports Retail, Classic Era, Classic Progression/Anniversary, and WoW Forever/Camelot, including primary professions, Archaeology, Fishing, Cooking, and First Aid where the client exposes it in the profession roster. Forever uses the existing capability-driven modern `C_TradeSkillUI` adapter and a separate `forever` SavedVariables/export flavor namespace; it is not Retail or Classic Era. Herbalism, Fishing, Skinning, and Archaeology are represented from authoritative roster ranks with empty recipe arrays; Mining and recipe-bearing First Aid still require a complete profession-window scan. Classic Era Enchanting has its own legacy `GetCraft*` adapter; the ordinary Classic `GetTradeSkill*` adapter is used for every other recipe-bearing profession.

## Install / package

Run `addon/scripts/package.sh retail`, `addon/scripts/package.sh classic_era`, `addon/scripts/package.sh classic`, or `addon/scripts/package.sh forever`, then copy the generated `addon/dist/<flavor>/Flynn` directory into that client's `Interface/AddOns/Flynn`. `addon/Flynn/Flynn.toc` is a convenience multi-interface TOC for local development; use the flavor-specific package output for actual releases. The package script copies only runtime Core and pinned libraries, never specs or scripts.

The saved global is `FlynnDB`, written by WoW to `WTF/Account/<account>/SavedVariables/Flynn.lua`. WoW flushes it only on `/reload`, logout, or exit. Separate WoW account folders or game installations cannot be merged by the addon; upload each account's SavedVariables file separately.

See the shared [`docs/data-contract.md`](../docs/data-contract.md) specification for the SavedVariables, `FLYNN1:` export, schema, and field semantics used by all Flynn components.

## Tracking and privacy

Flynn begins tracking the logged-in character unless ignored in `/flynn settings`. Login reads only the native profession roster; recipe scans begin only on the four TradeSkill/Craft window events. An event that arrives before profession data is ready gets at most three event-scoped `C_Timer.After(0, ...)` retries; Flynn does not subscribe to `TRADE_SKILL_UPDATE` or run idle polls. To form a safe authoritative snapshot, open every recipe-bearing profession window listed by the verified roster. Recipe-less gathering/secondary lines retain their reported ranks and empty recipe arrays without requiring nonexistent windows. Retail exports are refused for linked, guild, NPC, not-ready, or otherwise uncertifiable sources; legacy linked data is rejected and legacy filters must explicitly report the All state. An unavailable or incomplete roster never falls back to the currently open profession or adds an inferred profession. Character identity, source flags, active profession, and the complete roster are revalidated before every resumed batch and before commit. Scans yield at a 4 ms `debugprofilestop` budget when available and at most every 10 recipe/slot operations; recipe-list materialization/sorting and an individual native API call can still exceed a frame budget, so this is not a hard real-time guarantee. A scan canceled by combat, source/identity/roster change, closing the window, or ignoring the character cannot replace the last complete snapshot.

Ignore removes that character's uploadable data and staged profession records, retains only a minimal ignored identity preference, and cancels scanning. Re-enabling tracking requires fresh captures of the full profession roster. `/flynn` and `/flynn export` open the export UI; select the complete character snapshots to include, build the string, then click the field and press Ctrl+A / Ctrl+C. Export runs synchronous pure-Lua compression only after explicit user action. The addon enforces the API's 100-character, 50,000-recipe, 500,000-table-entry, 32-level, 20 MiB decoded JSON, and 4 MiB export-string caps; it never truncates. Large exports may briefly stall on older clients; the addon asks users to select fewer characters or upload `Flynn.lua` instead.

`FlynnDB.settings` contains only small local tracking preferences and is omitted from the JSON export. Partial profession staging stays in memory and is discarded on reload/logout; the previous complete SavedVariables snapshot remains intact. Do not edit the SavedVariables file while WoW is running. Schema versions newer than this addon are left untouched.

## Export contract and API notes

The export is `FLYNN1:` + RFC4648 standard Base64 of raw RFC1951 DEFLATE compressed UTF-8 JSON. The JSON is `{"schemaVersion":1,"characters":...}` and follows `docs/data-contract.md`; arrays are emitted as `[]`, including empty profession/recipe/output/reagent arrays. IDs, ranks, quantities, region, flavor, GUID, faction, class, and UTC epoch capture time are retained; localized tooltips/names are not used as identifiers. When a client exposes no numeric output/reagent ID, Flynn omits that item rather than guessing it. Retail quality and reagent slot choices are preserved only where native structured APIs provide them. Cache APIs are not required for the selected data (native trade-skill APIs already return numeric IDs), so Flynn does not request asynchronous item/spell data or retain localized cache strings.

Current native API references checked during implementation:

- Blizzard's generated TradeSkillUI docs and current FrameXML mirror: <https://github.com/Gethe/wow-ui-source/tree/live/Interface/AddOns/Blizzard_APIDocumentationGenerated>.
- Legacy `GetTradeSkill*` / `GetCraft*` APIs and flavor matrix: <https://warcraft.wiki.gg/wiki/World_of_Warcraft_API/Classic>.
- Modern personal-source checks use `C_TradeSkillUI.IsTradeSkillLinked`, `.IsTradeSkillGuild`, `.IsNPCCrafting`, and `.IsTradeSkillReady`; legacy uses `IsTradeSkillLinked` and source/roster matching. See <https://warcraft.wiki.gg/wiki/API:C_TradeSkillUI.IsTradeSkillLinked> and <https://warcraft.wiki.gg/wiki/API:IsTradeSkillLinked>.
- Legacy Mining/Smelting matching uses SkillLineID `186` and the exact localized name of spell ID `2656` (`Smelting`), never an English-string or fuzzy fallback; spell ID 2656 is documented for Classic at <https://www.wowhead.com/classic/spell=2656/smelting>.
- Blizzard's `classic_era` FrameXML shows Vanilla CraftFrame has no filter widgets; TBC CraftFrame has a slot dropdown, initializes it with `SetCraftFilter(0)` (All), and has a makeable checkbox: <https://github.com/Gethe/wow-ui-source/blob/classic_era/Interface/AddOns/Blizzard_CraftUI/Vanilla/Blizzard_CraftUI.lua>, <https://github.com/Gethe/wow-ui-source/blob/classic_era/Interface/AddOns/Blizzard_CraftUI/TBC/Blizzard_CraftUI.lua>.
- Profession roster enumeration uses `GetProfessions`/`GetProfessionInfo` result slots in their native order, preserving Archaeology and later Classic slots such as First Aid when returned.
- `GetRecipeInfo`, `GetRecipeSchematic`, output and reagent structures: <https://warcraft.wiki.gg/wiki/API:C_TradeSkillUI.GetRecipeInfo>, <https://warcraft.wiki.gg/wiki/API:C_TradeSkillUI.GetRecipeSchematic>.

The data contract intentionally does not store specialization when no stable numeric specialization identifier is exposed. Flavor-specific constants and TOC interface IDs must be checked against the exact release client builds before publishing.

## Development and verification

- `addon/scripts/setup-dev-tools.sh` installs LuaJIT-compatible Busted and Luacheck into `$HOME/.cache/flynn-lua-tools` without root access.
- `addon/scripts/test.sh` runs Busted unit specs, Luacheck on runtime source only, packaging smoke checks, and a PHP `gzinflate`/JSON compatibility round-trip when those tools are installed.
- After setup, `eval "$(luarocks --lua-version=5.1 --tree="$HOME/.cache/flynn-lua-tools" path)" && just addon-test` is reproducible from the repository root. `addon/scripts/test.sh` discovers that local tree automatically.
- Use `luacheck --config addon/.luacheckrc addon`; the existing bare `luacheck addon` Just recipe does not load the nested addon config and includes vendored files.
- `addon/scripts/package.sh <flavor>` creates a runtime-only install directory.

Lua tool setup requires LuaRocks and LuaJIT; PHP is optional for the cross-runtime check. The automated API mocks do not replace in-game testing. Before release, test the full capture/export flow on actual Retail, Classic Era, Classic Progression, and WoW Forever clients (especially linked/guild/NPC source rejection, full roster enumeration, recipe-less ranks, Era Enchanting, Forever's missing legacy CRAFT events, uncached links, filters, combat and source changes, and large export responsiveness). WoW Forever live-client validation has not yet been performed. No verified Forever catalog data/source was supplied; labels remain numeric-ID fallbacks until a Forever-specific snapshot is available.
