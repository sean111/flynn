local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local Adapters = {}
local recipeLessSkillLines = {
  [182] = true, -- Herbalism: gathering skill, no recipe catalog.
  [356] = true, -- Fishing: gathering skill, no recipe catalog.
  [393] = true, -- Skinning: gathering skill, no recipe catalog.
  [794] = true, -- Archaeology: skill-line ranks, not TradeSkill recipes.
}
-- Smelting is a Mining skill line (ID 186), but some legacy clients expose
-- the active window label as the localized Smelting spell name instead.
local legacyWindowAliasSpellIDs = {
  [186] = { 2656 },
}
local hasLegacyFilters
local function number(value)
  value = tonumber(value)
  if value and value > 0 and value % 1 == 0 then return value end
end

local function itemID(link)
  if type(link) ~= 'string' then return nil end
  return number(link:match('item:(%d+)'))
end

local function spellID(link)
  if type(link) ~= 'string' then return nil end
  -- Trade skill list hyperlinks encode profession/rank, not a recipe spell ID.
  return number(link:match('spell:(%d+)')) or number(link:match('enchant:(%d+)'))
end

local function makeFrameBudget()
  return {
    operations = 0,
    startedAt = _G.debugprofilestop and _G.debugprofilestop() or nil,
  }
end

local function yieldForFrameBudget(budget)
  budget.operations = budget.operations + 1
  local now = _G.debugprofilestop and _G.debugprofilestop() or nil
  local expired = now and budget.startedAt and now - budget.startedAt >= 4
  if budget.operations < 10 and not expired then return end
  local thread, isMain = coroutine.running()
  if not thread or isMain then return end
  coroutine.yield()
  budget.operations = 0
  budget.startedAt = _G.debugprofilestop and _G.debugprofilestop() or nil
end

function Adapters.knownProfessions()
  if not _G.GetProfessions or not _G.GetProfessionInfo then
    return nil, false, 'This client cannot provide a reliable complete profession roster.'
  end
  local result, seen = {}, {}
  local function pack(...) return { n = select('#', ...), ... } end
  local indices = pack(_G.GetProfessions())
  for position = 1, indices.n do
    local index = indices[position]
    if index then
      local name, _, current, maximum, _, _, skillLineID = _G.GetProfessionInfo(index)
      skillLineID = number(skillLineID)
      current, maximum = tonumber(current), tonumber(maximum)
      if not skillLineID or not current or not maximum or current < 0 or maximum < current or seen[skillLineID] then
        return nil, false, 'The client returned an incomplete or duplicate profession roster.'
      end
      seen[skillLineID] = true
      result[#result + 1] = {
        skillLineID = skillLineID,
        currentSkill = current,
        maxSkill = maximum,
        name = name,
        requiresRecipeScan = not recipeLessSkillLines[skillLineID],
      }
    end
  end
  table.sort(result, function(a, b) return a.skillLineID < b.skillLineID end)
  return result, true
end

function Adapters.rosterSignature(roster)
  local parts = {}
  for _, profession in ipairs(roster) do
    parts[#parts + 1] = table.concat({ profession.skillLineID, profession.currentSkill, profession.maxSkill, profession.requiresRecipeScan and 1 or 0 }, ':')
  end
  return table.concat(parts, '|')
end

local function professionInRoster(roster, skillLineID)
  for _, profession in ipairs(roster or {}) do
    if profession.skillLineID == skillLineID then return profession end
  end
end

local function localizedSpellName(spellIDValue)
  if _G.C_Spell and type(_G.C_Spell.GetSpellInfo) == 'function' then
    local info = _G.C_Spell.GetSpellInfo(spellIDValue)
    if type(info) == 'table' and type(info.name) == 'string' then return info.name end
  end
  if _G.GetSpellInfo then
    local name = _G.GetSpellInfo(spellIDValue)
    if type(name) == 'string' then return name end
  end
end

local function rosterProfessionForLegacyName(name, roster)
  if type(name) ~= 'string' then return nil end
  for _, profession in ipairs(roster or {}) do
    if profession.name == name then return profession end
  end
  for skillLineID, spellIDs in pairs(legacyWindowAliasSpellIDs) do
    local profession = professionInRoster(roster, skillLineID)
    if profession then
      for _, aliasSpellID in ipairs(spellIDs) do
        local localizedName = localizedSpellName(aliasSpellID)
        if localizedName and localizedName == name then return profession end
      end
    end
  end
end

local function falseFlag(api, name)
  local callback = api and api[name]
  if type(callback) ~= 'function' then return false end
  return callback() == false
end

function Adapters.inspectActive(event, roster)
  local api = _G.C_TradeSkillUI
  if event ~= 'CRAFT_SHOW' and api and api.GetBaseProfessionInfo then
    if not falseFlag(api, 'IsTradeSkillLinked') or not falseFlag(api, 'IsTradeSkillGuild')
      or not falseFlag(api, 'IsNPCCrafting') or type(api.IsTradeSkillReady) ~= 'function' then
      return nil, 'The modern profession source is linked, guild, NPC, not ready, or cannot be certified as personal data.'
    end
    local ready = api.IsTradeSkillReady()
    if ready ~= true then
      return nil, 'The modern profession data is not ready yet.', ready == false
    end
    local base = api.GetBaseProfessionInfo()
    if not base then return nil, 'The modern profession metadata is not ready yet.', true end
    local skillLineID = base and number(base.professionID or base.skillLineID)
    local profession = skillLineID and professionInRoster(roster, skillLineID)
    if not profession or not profession.requiresRecipeScan then
      return nil, 'The open modern profession is not part of the verified recipe-capable player roster.'
    end
    return { kind = 'modern', skillLineID = skillLineID, sourceCounter = base.sourceCounter }
  end

  if not _G.IsTradeSkillLinked then
    return nil, 'This legacy client cannot certify that the profession data belongs to the player.'
  end
  if _G.IsTradeSkillLinked() then
    return nil, 'Linked profession data is not captured.'
  end
  if _G.IsTradeSkillGuild and _G.IsTradeSkillGuild() then
    return nil, 'Guild profession data is not captured.'
  end
  if _G.IsNPCCrafting and _G.IsNPCCrafting() then
    return nil, 'NPC profession data is not captured.'
  end

  local lineName, kind
  if event == 'CRAFT_SHOW' then
    if not _G.GetCraftSkillLine or not _G.GetNumCrafts or not _G.GetCraftInfo then
      return nil, 'Classic CraftFrame APIs are unavailable.'
    end
    lineName, kind = _G.GetCraftSkillLine(1), 'legacy-craft'
  else
    if not _G.GetTradeSkillLine or not _G.GetNumTradeSkills or not _G.GetTradeSkillInfo then
      return nil, 'Legacy TradeSkill APIs are unavailable.'
    end
    lineName, kind = _G.GetTradeSkillLine(), 'legacy'
  end
  if hasLegacyFilters and hasLegacyFilters(kind == 'legacy-craft') then
    return nil, 'Legacy profession filters are active or their All state cannot be certified.'
  end
  local rowCount = tonumber(kind == 'legacy-craft' and _G.GetNumCrafts() or _G.GetNumTradeSkills())
  if not rowCount or rowCount < 1 then return nil, 'The legacy profession recipe list is not ready yet.', true end
  if type(lineName) ~= 'string' or lineName == '' or lineName == 'UNKNOWN' then
    return nil, 'The legacy profession window is not ready yet.', true
  end
  local profession = rosterProfessionForLegacyName(lineName, roster)
  if profession and profession.requiresRecipeScan then
    return { kind = kind, skillLineID = profession.skillLineID, rowCount = rowCount }
  end
  return nil, 'The open legacy profession does not match a recipe-capable entry in the complete player roster.'
end

local function modern()
  local api = _G.C_TradeSkillUI
  if not api or not api.GetBaseProfessionInfo or not api.GetAllRecipeIDs then return nil end
  local base = api.GetBaseProfessionInfo()
  if not base then return nil end
  local skillLineID = number(base.professionID or base.skillLineID)
  if not skillLineID then return nil end
  local profession = {
    skillLineID = skillLineID,
    currentSkill = math.max(0, tonumber(base.skillLevel) or 0),
    maxSkill = math.max(0, tonumber(base.maxSkillLevel) or 0),
    recipes = {},
  }
  local recipeIDs = api.GetAllRecipeIDs() or {}
  local ids = {}
  local complete = true
  local budget = makeFrameBudget()
  for _, id in ipairs(recipeIDs) do
    yieldForFrameBudget(budget)
    local recipeID = number(id)
    if recipeID then ids[#ids + 1] = recipeID else complete = false end
  end
  table.sort(ids)
  for recipeIndex, id in ipairs(ids) do
    yieldForFrameBudget(budget)
    local info = api.GetRecipeInfo and api.GetRecipeInfo(id)
    if not info then
      complete = false
    elseif info.learned ~= false and info.isDummyRecipe ~= true then
      local recipe = { spellID = id, outputs = {}, reagents = {} }
      local schematic = api.GetRecipeSchematic and api.GetRecipeSchematic(id, false)
      if schematic then
        local minimum = number(schematic.quantityMin) or 1
        local output = number(schematic.outputItemID)
        if output then recipe.outputs[1] = { itemID = output, quantity = minimum } end
        if info.maxQuality and number(info.maxQuality) then
          recipe.quality = { maxRank = number(info.maxQuality), outputs = {} }
          for rank, qualityOutput in ipairs(info.qualityItemIDs or {}) do
            yieldForFrameBudget(budget)
            local qualityID = number(qualityOutput)
            if qualityID then recipe.quality.outputs[#recipe.quality.outputs + 1] = { rank = rank, itemID = qualityID, quantity = minimum } end
          end
          if #recipe.quality.outputs == 0 then recipe.quality.outputs = nil end
        end
        local slots = schematic.reagentSlotSchematics or {}
        if #slots > 0 then recipe.reagentSlots = {} end
        for slotIndex, slot in ipairs(slots) do
          yieldForFrameBudget(budget)
          local kind = 'required'
          if slot.required == false then kind = 'optional' end
          if slot.reagentType == 0 then kind = 'modifying' end
          local quantity = number(slot.quantityRequired) or number(slot.quantity) or 1
          local slotRecord = { slotIndex = slotIndex - 1, kind = kind, quantity = quantity, options = {} }
          for _, reagent in ipairs(slot.reagents or {}) do
            yieldForFrameBudget(budget)
            local idValue = type(reagent) == 'table' and number(reagent.itemID) or number(reagent)
            if idValue then slotRecord.options[#slotRecord.options + 1] = { itemID = idValue } end
          end
          recipe.reagentSlots[#recipe.reagentSlots + 1] = slotRecord
          if kind == 'required' and #slotRecord.options == 1 then
            recipe.reagents[#recipe.reagents + 1] = { itemID = slotRecord.options[1].itemID, quantity = quantity }
          end
        end
      else
        -- A missing schematic may reflect a not-yet-loaded recipe; don't claim an authoritative snapshot.
        complete = false
      end
      -- When a client exposes fixed reagent counts separately, prefer those for item slots.
      if #recipe.reagents == 0 and api.GetRecipeNumReagents and api.GetRecipeReagentInfo then
        local reagentCount = tonumber(api.GetRecipeNumReagents(id)) or 0
        for index = 1, reagentCount do
          yieldForFrameBudget(budget)
          local _, _, quantity = api.GetRecipeReagentInfo(id, index)
          local link = api.GetRecipeReagentItemLink and api.GetRecipeReagentItemLink(id, index)
          local reagent = itemID(link)
          if reagent and number(quantity) then recipe.reagents[#recipe.reagents + 1] = { itemID = reagent, quantity = number(quantity) } end
        end
      end
      profession.recipes[#profession.recipes + 1] = recipe
    end
  end
  return profession, 'modern', complete
end

local function rowItemLink(index, craftAPI)
  if craftAPI then
    return _G.GetCraftItemLink and _G.GetCraftItemLink(index)
  end
  return _G.GetTradeSkillItemLink and _G.GetTradeSkillItemLink(index)
end

hasLegacyFilters = function(craftAPI)
  if craftAPI then
    local craftFrame = _G.CraftFrame
    local dropdown = craftFrame and craftFrame.Dropdown
    local getFilter, getSlots = _G.GetCraftFilter, _G.GetCraftSlots
    if dropdown then
      -- TBC FrameXML's CraftFrame_SetupDropdown calls SetCraftFilter(0),
      -- and its radio callbacks query GetCraftFilter(index). Only an explicit
      -- All selection (or every slot selected) certifies an unfiltered list.
      if not getFilter or not getSlots then return true end
      local allSelected = getFilter(0)
      if allSelected ~= true and allSelected ~= 1 then
        local slots = { getSlots() }
        local selected = 0
        for index = 1, #slots do
          local value = getFilter(index)
          if value == true or value == 1 then selected = selected + 1 end
        end
        if #slots == 0 or selected ~= #slots then return true end
      end
    end
    local availableButton = _G.CraftFrameAvailableFilterCheckButton
    if availableButton then
      if not availableButton.GetChecked or availableButton:GetChecked() then return true end
    elseif _G.CraftOnlyShowMakeable then
      return true
    end
    if _G.GetCraftItemNameFilter then
      local filter = _G.GetCraftItemNameFilter()
      if filter and filter ~= '' then return true end
    elseif _G.SetCraftItemNameFilter then
      return true
    end
    return false
  end
  if _G.GetTradeSkillItemNameFilter then
    local filter = _G.GetTradeSkillItemNameFilter()
    if filter and filter ~= '' then return true end
  elseif _G.SetTradeSkillItemNameFilter then
    return true
  end
  if _G.GetTradeSkillOnlyShowMakeable and _G.GetTradeSkillOnlyShowMakeable() then return true end
  if _G.GetTradeSkillOnlyShowSkillUps and _G.GetTradeSkillOnlyShowSkillUps() then return true end
  local availableFilter = _G.TradeSkillFrameAvailableFilterCheckButton
  if availableFilter and availableFilter.GetChecked and availableFilter:GetChecked() then return true end
  local skillUpFilter = _G.TradeSkillFrameSkillUpOnlyCheckButton
  if skillUpFilter and skillUpFilter.GetChecked and skillUpFilter:GetChecked() then return true end
  if _G.GetTradeSkillItemLevelFilter then
    local minimum, maximum = _G.GetTradeSkillItemLevelFilter()
    if (tonumber(minimum) or 0) > 0 or (tonumber(maximum) or 0) > 0 then return true end
  elseif _G.SetTradeSkillItemLevelFilter then
    return true
  end
  local function incompleteFilter(getter, listGetter)
    if not getter or not listGetter then return true end
    local returned = { listGetter() }
    local list = type(returned[1]) == 'table' and returned[1] or returned
    local listSize = #list
    if listSize == 0 then return false end
    -- Index 0 is the API's explicit All entry. Nil/false is ambiguous and must
    -- not be mistaken for an unfiltered list.
    local allSelected = getter(0)
    if allSelected == true or allSelected == 1 then return false end
    local selected = 0
    for index = 1, listSize do if getter(index) then selected = selected + 1 end end
    return selected ~= listSize
  end
  if incompleteFilter(_G.GetTradeSkillSubClassFilter, _G.GetTradeSkillSubClasses) then return true end
  if incompleteFilter(_G.GetTradeSkillInvSlotFilter, _G.GetTradeSkillInvSlots) then return true end
  return false
end

local function legacy(craftAPI, roster)
  local getLine = craftAPI and _G.GetCraftSkillLine or _G.GetTradeSkillLine
  local getCount = craftAPI and _G.GetNumCrafts or _G.GetNumTradeSkills
  local getInfo = craftAPI and _G.GetCraftInfo or _G.GetTradeSkillInfo
  if not getLine or not getCount or not getInfo then return nil end
  local lineName, current, maximum
  local skillLineID = nil
  if craftAPI then
    lineName = getLine(1)
    local displayName, displayRank, displayMaximum
    if _G.GetCraftDisplaySkillLine then displayName, displayRank, displayMaximum = _G.GetCraftDisplaySkillLine() end
    lineName, current, maximum = displayName or lineName, displayRank, displayMaximum
  else
    lineName, current, maximum = getLine()
  end
  local known = rosterProfessionForLegacyName(lineName, roster)
  if known then
    skillLineID = known.skillLineID
    current = tonumber(current) or known.currentSkill
    maximum = tonumber(maximum) or known.maxSkill
  end
  if not skillLineID then return nil end
  local profession = { skillLineID = skillLineID, currentSkill = math.max(0, tonumber(current) or 0), maxSkill = math.max(0, tonumber(maximum) or 0), recipes = {} }
  local count = tonumber(getCount()) or 0
  local complete = count > 0 and not hasLegacyFilters(craftAPI)
  local budget = makeFrameBudget()
  for index = 1, count do
    yieldForFrameBudget(budget)
    local recipeType, expanded
    local rowInfo = { getInfo(index) }
    if craftAPI then
      recipeType, expanded = rowInfo[3], rowInfo[5]
    else
      recipeType, expanded = rowInfo[2], rowInfo[4]
    end
    local header = recipeType == 'header' or recipeType == 'subheader'
    if header then
      if expanded == nil or expanded == false then complete = false end
    else
      local link
      if craftAPI then link = _G.GetCraftRecipeLink and _G.GetCraftRecipeLink(index)
      else link = _G.GetTradeSkillRecipeLink and _G.GetTradeSkillRecipeLink(index) end
      local id = spellID(link)
      if id then
        local recipe = { spellID = id, outputs = {}, reagents = {} }
        local output = itemID(rowItemLink(index, craftAPI))
        if output then
          local amount = 1
          local getMade = craftAPI and _G.GetCraftNumMade or _G.GetTradeSkillNumMade
          if getMade then
            local low, high = getMade(index)
            amount = number(low) or number(high) or 1
          end
          recipe.outputs[1] = { itemID = output, quantity = amount }
        end
        local getNumReagents = craftAPI and _G.GetCraftNumReagents or _G.GetTradeSkillNumReagents
        local getReagentInfo = craftAPI and _G.GetCraftReagentInfo or _G.GetTradeSkillReagentInfo
        local getReagentLink = craftAPI and _G.GetCraftReagentItemLink or _G.GetTradeSkillReagentItemLink
        local reagentCount = getNumReagents and tonumber(getNumReagents(index)) or 0
        for reagentIndex = 1, reagentCount do
          yieldForFrameBudget(budget)
          local _, _, required = getReagentInfo(index, reagentIndex)
          local link = getReagentLink and getReagentLink(index, reagentIndex)
          local reagent = itemID(link)
          if reagent and number(required) then
            recipe.reagents[#recipe.reagents + 1] = { itemID = reagent, quantity = number(required) }
          elseif (tonumber(required) or 0) > 0 then
            complete = false
          end
        end
        profession.recipes[#profession.recipes + 1] = recipe
      else
        -- No identifier means the index-based client did not expose a safe recipe ID.
        complete = false
      end
    end
  end
  table.sort(profession.recipes, function(a, b) return a.spellID < b.spellID end)
  return profession, craftAPI and 'legacy-craft' or 'legacy', complete
end

function Adapters.captureActive(event, roster, expectedSource)
  if not roster then
    local complete
    roster, complete = Adapters.knownProfessions()
    if not complete then return nil, 'unsupported', false, 'The player profession roster is unavailable.' end
  end
  local source, sourceError = Adapters.inspectActive(event, roster)
  if not source then return nil, 'unsupported', false, sourceError end
  if expectedSource and (source.kind ~= expectedSource.kind or source.skillLineID ~= expectedSource.skillLineID) then
    return nil, 'unsupported', false, 'The active profession source changed before scanning began.'
  end
  if source.kind == 'modern' then
    local profession, kind, complete = modern()
    if profession and profession.skillLineID == source.skillLineID then return profession, kind, complete end
    return nil, 'modern', false, 'The modern profession changed or became unavailable during the scan.'
  end
  local profession, kind, complete = legacy(source.kind == 'legacy-craft', roster)
  if profession and profession.skillLineID == source.skillLineID then return profession, kind, complete end
  return nil, kind or source.kind, false, 'The legacy profession changed or became unavailable during the scan.'
end

NS.Adapters = Adapters
