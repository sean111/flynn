local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local Model = { SCHEMA_VERSION = 1, stagedProfessions = {} }
local validFlavors = { retail = true, classic_era = true, classic = true, forever = true }
local validRegions = { us = true, eu = true, kr = true, tw = true, cn = true }

function Model.ensureDatabase(database)
  database = database or { schemaVersion = Model.SCHEMA_VERSION, characters = {}, settings = {} }
  if type(database) ~= 'table' or type(database.schemaVersion) ~= 'number' then
    return nil, 'Flynn data has an invalid schema version; update the addon before capturing.'
  end
  if database.schemaVersion ~= Model.SCHEMA_VERSION then
    return nil, 'Flynn data schema ' .. tostring(database.schemaVersion) .. ' is newer than this addon; update Flynn before making changes.'
  end
  if type(database.characters) ~= 'table' then database.characters = {} end
  if type(database.settings) ~= 'table' then database.settings = {} end
  if type(database.settings.tracked) ~= 'table' then database.settings.tracked = {} end
  if type(database.settings.ignored) ~= 'table' then database.settings.ignored = {} end
  if type(database.settings.capturedProfessions) ~= 'table' then database.settings.capturedProfessions = {} end
  return database
end

function Model.identityKey(flavor, region, realm, characterName)
  return table.concat({ flavor, region, realm, characterName }, '\031')
end

function Model.getCharacter(database, flavor, region, realm, characterName)
  local flavors = database.characters[flavor]
  local regions = flavors and flavors[region]
  local realms = regions and regions[realm]
  return realms and realms[characterName]
end

function Model.setCharacter(database, character)
  local flavor, region, realm, characterName = character.gameFlavor, character.region, character.realm, character.characterName
  database.characters[flavor] = database.characters[flavor] or {}
  database.characters[flavor][region] = database.characters[flavor][region] or {}
  database.characters[flavor][region][realm] = database.characters[flavor][region][realm] or {}
  database.characters[flavor][region][realm][characterName] = character
end

function Model.track(database, character)
  local key = Model.identityKey(character.gameFlavor, character.region, character.realm, character.characterName)
  if database.settings.ignored[key] then return false, 'Character tracking is ignored.' end
  database.settings.tracked[key] = true
  database.settings.capturedProfessions[key] = database.settings.capturedProfessions[key] or {}
  return true
end

function Model.setIgnored(database, character, ignored)
  local key = Model.identityKey(character.gameFlavor, character.region, character.realm, character.characterName)
  if ignored then
    database.settings.ignored[key] = true
    database.settings.tracked[key] = nil
    database.settings.capturedProfessions[key] = nil
    Model.stagedProfessions[key] = nil
    local flavors = database.characters[character.gameFlavor]
    local regions = flavors and flavors[character.region]
    local realms = regions and regions[character.realm]
    if realms then realms[character.characterName] = nil end
  else
    database.settings.ignored[key] = nil
    database.settings.tracked[key] = true
    -- Require a fresh complete roster capture after re-enabling tracking.
    database.settings.capturedProfessions[key] = {}
    Model.stagedProfessions[key] = {}
  end
end

function Model.isUploadable(database, character)
  local key = Model.identityKey(character.gameFlavor, character.region, character.realm, character.characterName)
  if database.settings.ignored[key] then return false end
  return database.settings.tracked[key] == true and database.settings.capturedProfessions[key] and database.settings.capturedProfessions[key].complete == true
end

function Model.captureCharacter(database, character, roster, rosterSignature, scannedProfession)
  local key = Model.identityKey(character.gameFlavor, character.region, character.realm, character.characterName)
  if database.settings.ignored[key] then return false, 'ignored' end
  local stagedState = Model.stagedProfessions[key]
  if not stagedState or stagedState.signature ~= rosterSignature then
    stagedState = { signature = rosterSignature, professions = {} }
  end
  local rosterByID = {}
  for _, rosterProfession in ipairs(roster) do
    rosterByID[rosterProfession.skillLineID] = rosterProfession
    if not rosterProfession.requiresRecipeScan then
      stagedState.professions[rosterProfession.skillLineID] = {
        skillLineID = rosterProfession.skillLineID,
        currentSkill = rosterProfession.currentSkill,
        maxSkill = rosterProfession.maxSkill,
        recipes = {},
      }
    end
  end
  if scannedProfession then
    local rosterEntry = rosterByID[scannedProfession.skillLineID]
    if not rosterEntry or not rosterEntry.requiresRecipeScan then
      return false, 'The active profession is not in the complete scan-required roster.'
    end
    stagedState.professions[scannedProfession.skillLineID] = {
      skillLineID = rosterEntry.skillLineID,
      currentSkill = rosterEntry.currentSkill,
      maxSkill = rosterEntry.maxSkill,
      recipes = scannedProfession.recipes or {},
    }
  end
  Model.stagedProfessions[key] = stagedState

  local complete = true
  for _, rosterProfession in ipairs(roster) do
    if rosterProfession.requiresRecipeScan and not stagedState.professions[rosterProfession.skillLineID] then complete = false end
  end
  if not complete then return false end

  local professions = {}
  for _, rosterProfession in ipairs(roster) do
    professions[#professions + 1] = stagedState.professions[rosterProfession.skillLineID]
  end
  table.sort(professions, function(a, b) return a.skillLineID < b.skillLineID end)
  character.professions = professions
  Model.setCharacter(database, character)
  Model.stagedProfessions[key] = nil
  local captured = database.settings.capturedProfessions[key] or {}
  captured.complete = true
  database.settings.capturedProfessions[key] = captured
  return true
end

function Model.exportableCharacters(database)
  local result = {}
  for flavor, regions in pairs(database.characters) do
    for region, realms in pairs(regions) do
      for realm, characters in pairs(realms) do
        for characterName, record in pairs(characters) do
          if Model.isUploadable(database, record) then
            result[flavor] = result[flavor] or {}
            result[flavor][region] = result[flavor][region] or {}
            result[flavor][region][realm] = result[flavor][region][realm] or {}
            result[flavor][region][realm][characterName] = record
          end
        end
      end
    end
  end
  return result
end

function Model.validateExport(characters)
  local count, recipeTotal = 0, 0
  local function walk(value, depth)
    if depth > 32 then return false, 'Export exceeds the maximum nesting depth.' end
    if type(value) ~= 'table' then return true end
    for _, child in pairs(value) do
      count = count + 1
      if count > 500000 then return false, 'Export exceeds the maximum table-entry count.' end
      local ok, err = walk(child, depth + 1)
      if not ok then return false, err end
    end
    return true
  end
  for flavor, regions in pairs(characters) do
    if not validFlavors[flavor] then return false, 'Unsupported game flavor in export.' end
    for region, realms in pairs(regions) do
      if not validRegions[region] then return false, 'Unsupported region in export.' end
      for _, characterMap in pairs(realms) do
        for _, character in pairs(characterMap) do
          count = count + 1
          if count > 100 then return false, 'Export exceeds 100 characters. Sync separate account/install exports individually.' end
          if # (character.professions or {}) > 20 then return false, 'A character exceeds 20 professions.' end
          for _, profession in ipairs(character.professions or {}) do
            local recipes = profession.recipes or {}
            recipeTotal = recipeTotal + #recipes
            if #recipes > 5000 or recipeTotal > 50000 then return false, 'Export exceeds the recipe-count limit.' end
            for _, recipe in ipairs(recipes) do
              if #(recipe.outputs or {}) > 20 or #(recipe.reagents or {}) > 100 or #(recipe.reagentSlots or {}) > 100 then return false, 'A recipe exceeds an API structural limit.' end
              for _, slot in ipairs(recipe.reagentSlots or {}) do if #(slot.options or {}) > 100 then return false, 'A reagent slot exceeds the choice limit.' end end
            end
          end
        end
      end
    end
  end
  local ok, err = walk(characters, 0)
  if not ok then return false, err end
  return true
end

function Model.getFlavor()
  local project = _G.WOW_PROJECT_ID
  if project and project == _G.WOW_PROJECT_MAINLINE then return 'retail' end
  -- WoW Forever (Camelot) is a modern C_TradeSkillUI client, but owns a
  -- separate SavedVariables/catalog namespace from Retail.
  if project and ((_G.WOW_PROJECT_CAMELOT and project == _G.WOW_PROJECT_CAMELOT) or project == 18) then return 'forever' end
  if project and ((_G.WOW_PROJECT_CLASSIC_ERA and project == _G.WOW_PROJECT_CLASSIC_ERA) or (_G.WOW_PROJECT_CLASSIC and project == _G.WOW_PROJECT_CLASSIC)) then return 'classic_era' end
  if project and project ~= _G.WOW_PROJECT_MAINLINE then return 'classic' end
  if _G.C_TradeSkillUI and _G.C_TradeSkillUI.GetBaseProfessionInfo then return 'retail' end
  return 'classic_era'
end

function Model.getCharacterIdentity()
  local regionIndex = _G.GetCurrentRegion and _G.GetCurrentRegion()
  local region = ({ 'us', 'kr', 'eu', 'tw', 'cn' })[regionIndex]
  local name = _G.UnitName and _G.UnitName('player')
  local realm = _G.GetRealmName and _G.GetRealmName()
  local guid = _G.UnitGUID and _G.UnitGUID('player')
  if type(name) ~= 'string' or name == '' or type(realm) ~= 'string' or realm == '' or type(guid) ~= 'string' or guid == '' or not region then
    return nil, 'Character identity is unavailable from this client; no data was saved.'
  end
  local classToken = 'UNKNOWN'
  if _G.UnitClass then local _, token = _G.UnitClass('player'); classToken = token or 'UNKNOWN' end
  local factionValue = _G.UnitFactionGroup and _G.UnitFactionGroup('player')
  local faction = 'unknown'
  if factionValue == _G.FACTION_ALLIANCE or factionValue == 'Alliance' or factionValue == 'alliance' then faction = 'alliance' end
  if factionValue == _G.FACTION_HORDE or factionValue == 'Horde' or factionValue == 'horde' then faction = 'horde' end
  if _G.UnitIsPVP and not _G.UnitIsPVP('player') and factionValue == 'Neutral' then faction = 'neutral' end
  return {
    schemaVersion = Model.SCHEMA_VERSION,
    gameFlavor = Model.getFlavor(), region = region, realm = realm,
    characterName = name, guid = guid,
    faction = faction, class = classToken, capturedAt = (_G.time and _G.time()) or os.time(),
  }
end

NS.Model = Model
