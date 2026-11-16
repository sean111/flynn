local helpers = dofile('addon/spec/helpers.lua')

describe('profession API adapters', function()
  local NS
  before_each(function()
    NS = helpers.reset()
    _G.C_Spell = nil
    _G.GetProfessions = function() return 1, 2, nil, 4, 5 end
    _G.GetProfessionInfo = function(index)
      local ids = { [1] = 171, [2] = 164, [4] = 356, [5] = 185 }
      local names = { [1] = 'Alchemy', [2] = 'Blacksmithing', [4] = 'Fishing', [5] = 'Cooking' }
      return names[index], 1, 25, 75, 0, 0, ids[index]
    end
    _G.IsTradeSkillLinked = function() return false end
    _G.IsTradeSkillGuild = function() return false end
    _G.IsNPCCrafting = nil
    _G.GetTradeSkillSubClasses = function() return 'Flasks', 'Potions' end
    _G.GetTradeSkillSubClassFilter = function(index) return index == 0 and 1 or nil end
    _G.GetTradeSkillInvSlots = function() return 'Head', 'Hands' end
    _G.GetTradeSkillInvSlotFilter = function(index) return index == 0 and 1 or nil end
    _G.CraftFrame = nil
    _G.CraftFrameAvailableFilterCheckButton = nil
    _G.GetCraftFilter, _G.SetCraftFilter, _G.GetCraftSlots = nil, nil, nil
    _G.GetCraftItemNameFilter, _G.SetCraftItemNameFilter = nil, nil
    _G.CraftOnlyShowMakeable = nil
  end)

  it('discovers every profession slot, including Archaeology and sixth-slot First Aid', function()
    _G.GetProfessions = function() return 1, 2, 3, 4, 5, 6 end
    _G.GetProfessionInfo = function(index)
      local records = {
        [1] = { 'Alchemy', 171 }, [2] = { 'Blacksmithing', 164 },
        [3] = { 'Archaeology', 794 }, [4] = { 'Fishing', 356 },
        [5] = { 'Cooking', 185 }, [6] = { 'First Aid', 129 },
      }
      local record = records[index]
      return record[1], 1, 25, 75, 0, 0, record[2]
    end
    local found, complete = NS.Adapters.knownProfessions()
    assert.is_true(complete)
    assert.are.same({ 129, 164, 171, 185, 356, 794 }, {
      found[1].skillLineID, found[2].skillLineID, found[3].skillLineID,
      found[4].skillLineID, found[5].skillLineID, found[6].skillLineID,
    })
    assert.is_true(found[1].requiresRecipeScan) -- First Aid recipes are scanned on Classic.
    assert.is_false(found[5].requiresRecipeScan) -- Fishing is roster-only.
    assert.is_false(found[6].requiresRecipeScan) -- Archaeology is roster-only.
    assert.is_true(found[2].requiresRecipeScan)
    _G.GetTradeSkillLine = function() return 'First Aid', 50, 75, 0 end
    _G.GetNumTradeSkills = function() return 1 end
    _G.GetTradeSkillInfo = function() return 'Heavy Linen Bandage', 'optimal' end
    local source = NS.Adapters.inspectActive('TRADE_SKILL_SHOW', found)
    assert.are.same({ kind = 'legacy', skillLineID = 129, rowCount = 1 }, source)
  end)

  it('fails closed when any returned roster slot cannot be described', function()
    _G.GetProfessionInfo = function(index)
      if index == 1 then return 'Alchemy', 1, 25, 75, 0, 0, 171 end
      return 'Unknown', 1, 25, 75, 0, 0, nil
    end
    local roster, complete = NS.Adapters.knownProfessions()
    assert.is_nil(roster)
    assert.is_false(complete)
  end)

  it('reads modern recipe, output, required reagent choices and quality numerically', function()
    _G.WOW_PROJECT_ID, _G.WOW_PROJECT_CAMELOT = 18, 18
    assert.are.equal('forever', NS.Model.getFlavor())
    _G.C_TradeSkillUI = {
      GetBaseProfessionInfo = function() return { professionID = 171, skillLevel = 100, maxSkillLevel = 100 } end,
      IsTradeSkillLinked = function() return false end,
      IsTradeSkillGuild = function() return false end,
      IsNPCCrafting = function() return false end,
      IsTradeSkillReady = function() return true end,
      GetAllRecipeIDs = function() return { 5002, 5001 } end,
      GetRecipeInfo = function(id) return { learned = true, maxQuality = 2, qualityItemIDs = { 2001, 2002 }, recipeID = id } end,
      GetRecipeSchematic = function(id)
        return { outputItemID = 1001, quantityMin = 1, quantityMax = 1, reagentSlotSchematics = {
          { required = true, reagentType = 1, quantityRequired = 2, reagents = { { itemID = 3001 } } },
          { required = false, reagentType = 1, quantityRequired = 1, reagents = { { itemID = 3002 }, { itemID = 3003 } } },
        } }
      end,
    }
    local roster = assert(NS.Adapters.knownProfessions())
    local profession, kind, complete = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.are.equal('modern', kind)
    assert.is_true(complete)
    assert.are.equal(100, profession.currentSkill)
    assert.are.equal(5001, profession.recipes[1].spellID)
    assert.are.same({ itemID = 1001, quantity = 1 }, profession.recipes[1].outputs[1])
    assert.are.same({ itemID = 3001, quantity = 2 }, profession.recipes[1].reagents[1])
    assert.are.equal(2, profession.recipes[1].quality.maxRank)
    assert.are.equal(2, #profession.recipes[1].reagentSlots[2].options)
  end)

  it('uses the legacy trade-skill APIs and rejects collapsed or unidentified recipe rows', function()
    _G.C_TradeSkillUI = nil
    _G.GetTradeSkillLine = function() return 'Alchemy', 50, 75, 0 end
    _G.GetNumTradeSkills = function() return 2 end
    _G.GetTradeSkillInfo = function(index)
      if index == 1 then return 'Flasks', 'header', nil, false end
      return 'Potion', nil
    end
    _G.GetTradeSkillRecipeLink = function() return '|Henchant:12345|h[Recipe]|h' end
    _G.GetTradeSkillItemLink = function() return '|Hitem:9876:0|h[Item]|h' end
    _G.GetTradeSkillNumMade = function() return 2, 2 end
    _G.GetTradeSkillNumReagents = function() return 1 end
    _G.GetTradeSkillReagentInfo = function() return 'Herb', 1, 3, 5 end
    _G.GetTradeSkillReagentItemLink = function() return '|Hitem:7654:0|h[Herb]|h' end
    local roster = assert(NS.Adapters.knownProfessions())
    local profession, kind, complete = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.are.equal('legacy', kind)
    assert.is_false(complete)
    assert.are.equal(12345, profession.recipes[1].spellID)
    assert.are.equal(9876, profession.recipes[1].outputs[1].itemID)
    assert.are.equal(2, profession.recipes[1].outputs[1].quantity)
    assert.are.equal(7654, profession.recipes[1].reagents[1].itemID)
    _G.GetTradeSkillInfo = function() return 'Potion', nil end
    _G.GetTradeSkillRecipeLink = function() return nil end
    local _, _, completeWithoutID = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(completeWithoutID)
  end)

  it('refuses legacy recipe lists with active filters', function()
    _G.C_TradeSkillUI = nil
    _G.GetTradeSkillLine = function() return 'Alchemy', 50, 75, 0 end
    _G.GetNumTradeSkills = function() return 1 end
    _G.GetTradeSkillInfo = function() return 'Potion', 'optimal' end
    _G.GetTradeSkillRecipeLink = function() return '|Henchant:12345|h[Recipe]|h' end
    _G.GetTradeSkillItemLink = function() return '|Hitem:9876|h[Item]|h' end
    _G.GetTradeSkillItemNameFilter = function() return 'Potion' end
    local roster = assert(NS.Adapters.knownProfessions())
    local _, _, complete = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(complete)
    _G.GetTradeSkillItemNameFilter = function() return '' end
    _G.GetTradeSkillSubClasses = function() return 'Flasks', 'Potions' end
    _G.GetTradeSkillSubClassFilter = function(index) return index == 1 end
    local _, _, completeWithSubclassFilter = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(completeWithSubclassFilter)
    _G.GetTradeSkillSubClassFilter = function(index)
      if index == 0 then return false end
      return true
    end
    local _, _, completeWithAllSubclasses = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_true(completeWithAllSubclasses)
    _G.GetTradeSkillSubClassFilter = function() return nil end
    local _, _, completeWithNoSelectedSubclasses = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(completeWithNoSelectedSubclasses)
    _G.GetTradeSkillSubClassFilter = nil
    local _, _, completeWithUnverifiableSubclasses = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(completeWithUnverifiableSubclasses)
    _G.GetTradeSkillSubClassFilter = function(index) return index == 0 and 1 or nil end
    _G.GetTradeSkillRecipeLink = function() return '|Htrade:171:375|h[Wrong link type]|h' end
    local profession, _, noRecipeID = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(noRecipeID)
    assert.are.equal(0, #profession.recipes)
    _G.GetNumTradeSkills = function() return 0 end
    local _, _, emptyListComplete = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(emptyListComplete)
  end)

  it('marks modern rows incomplete when recipe or schematic information is unavailable', function()
    _G.C_TradeSkillUI = {
      GetBaseProfessionInfo = function() return { professionID = 171, skillLevel = 100, maxSkillLevel = 100 } end,
      IsTradeSkillLinked = function() return false end,
      IsTradeSkillGuild = function() return false end,
      IsNPCCrafting = function() return false end,
      IsTradeSkillReady = function() return true end,
      GetAllRecipeIDs = function() return { 5001, 5002 } end,
      GetRecipeInfo = function(id) if id == 5001 then return { learned = true } end end,
      GetRecipeSchematic = function() return nil end,
    }
    local roster = assert(NS.Adapters.knownProfessions())
    local _, _, complete = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    assert.is_false(complete)
  end)

  it('rejects modern linked, guild, NPC, and uncertifiable sources', function()
    local roster = assert(NS.Adapters.knownProfessions())
    _G.C_TradeSkillUI = {
      GetBaseProfessionInfo = function() return { professionID = 171 } end,
      IsTradeSkillReady = function() return true end,
      IsTradeSkillLinked = function() return false end,
      IsTradeSkillGuild = function() return false end,
      IsNPCCrafting = function() return false end,
    }
    assert.is_not_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
    _G.C_TradeSkillUI.IsTradeSkillReady = function() return false end
    local notReady, _, retryable = NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster)
    assert.is_nil(notReady)
    assert.is_true(retryable)
    _G.C_TradeSkillUI.IsTradeSkillReady = function() return true end
    for _, flag in ipairs({ 'IsTradeSkillLinked', 'IsTradeSkillGuild', 'IsNPCCrafting' }) do
      _G.C_TradeSkillUI[flag] = function() return true end
      assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster), flag)
      _G.C_TradeSkillUI[flag] = function() return false end
    end
    _G.C_TradeSkillUI.IsNPCCrafting = nil
    assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
    _G.C_TradeSkillUI.IsNPCCrafting = function() return false end
    _G.C_TradeSkillUI.GetBaseProfessionInfo = function() return { professionID = 999 } end
    assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
  end)

  it('rejects legacy linked and guild sources and exposes source identity for revalidation', function()
    _G.C_TradeSkillUI = nil
    _G.GetTradeSkillLine = function() return 'Alchemy', 50, 75, 0 end
    _G.GetNumTradeSkills = function() return 1 end
    _G.GetTradeSkillInfo = function() return 'Potion', 'optimal' end
    local roster = assert(NS.Adapters.knownProfessions())
    local source = NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster)
    assert.are.same({ kind = 'legacy', skillLineID = 171, rowCount = 1 }, source)
    _G.IsTradeSkillLinked = function() return true end
    assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
    _G.IsTradeSkillLinked = function() return false end
    _G.IsTradeSkillGuild = function() return true end
    assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
    _G.IsTradeSkillGuild = nil
    _G.IsTradeSkillLinked = nil
    assert.is_nil(NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster))
  end)

  it('maps localized legacy Smelting window labels to the Mining skill line by verified spell ID', function()
    _G.C_TradeSkillUI = nil
    _G.GetProfessions = function() return 1, 2 end
    _G.GetProfessionInfo = function(index)
      if index == 1 then return 'Alchemy', 1, 25, 75, 0, 0, 171 end
      return 'Minería', 1, 50, 75, 0, 0, 186
    end
    _G.GetTradeSkillLine = function() return 'Fusión', 50, 75, 0 end
    _G.GetSpellInfo = function(spellID)
      assert.are.equal(2656, spellID)
      return 'Fusión'
    end
    _G.GetNumTradeSkills = function() return 1 end
    _G.GetTradeSkillInfo = function() return 'Fundir cobre', 'optimal' end
    _G.GetTradeSkillRecipeLink = function() return '|Henchant:2657|h[Fundir cobre]|h' end
    local roster = assert(NS.Adapters.knownProfessions())
    local source = NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster)
    assert.are.same({ kind = 'legacy', skillLineID = 186, rowCount = 1 }, source)
    local profession, kind = NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster, source)
    assert.are.equal('legacy', kind)
    assert.are.equal(186, profession.skillLineID)
    _G.C_Spell = { GetSpellInfo = function(spellID) assert.are.equal(2656, spellID); return { name = 'Verhütten' } end }
    _G.GetTradeSkillLine = function() return 'Verhütten', 50, 75, 0 end
    local localizedSource = NS.Adapters.inspectActive('TRADE_SKILL_SHOW', roster)
    assert.are.equal(186, localizedSource.skillLineID)
  end)

  it('uses a bounded frame budget inside large recipe and reagent loops', function()
    local processed = 0
    _G.C_TradeSkillUI = {
      GetBaseProfessionInfo = function() return { professionID = 171, skillLevel = 100, maxSkillLevel = 100 } end,
      IsTradeSkillLinked = function() return false end,
      IsTradeSkillGuild = function() return false end,
      IsNPCCrafting = function() return false end,
      IsTradeSkillReady = function() return true end,
      GetAllRecipeIDs = function()
        local ids = {}
        for id = 1, 30 do ids[id] = id end
        return ids
      end,
      GetRecipeInfo = function() processed = processed + 1; return { learned = true } end,
      GetRecipeSchematic = function() return { quantityMin = 1, reagentSlotSchematics = {} } end,
    }
    _G.debugprofilestop = nil
    local roster = assert(NS.Adapters.knownProfessions())
    local capture = coroutine.create(function()
      return NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    end)
    local ok = coroutine.resume(capture)
    assert.is_true(ok)
    assert.are.equal('suspended', coroutine.status(capture))
    assert.is_true(processed < 30)
    _G.C_TradeSkillUI.GetRecipeInfo = function() processed = processed + 1; return { learned = true } end
    -- One more frame demonstrates the bounded yield without relying on frame timing.
    assert.is_true(coroutine.resume(capture))
    assert.is_true(processed < 30)

    local tick = 0
    processed = 0
    _G.debugprofilestop = function() tick = tick + 2; return tick end
    local timedCapture = coroutine.create(function()
      return NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    end)
    assert.is_true(coroutine.resume(timedCapture))
    assert.are.equal('suspended', coroutine.status(timedCapture))
    assert.are.equal(0, processed) -- The time budget yielded while preparing the recipe ID list.
    _G.debugprofilestop = nil

    _G.C_TradeSkillUI.GetAllRecipeIDs = function() return { 1 } end
    _G.C_TradeSkillUI.GetRecipeSchematic = function()
      local options = {}
      for index = 1, 25 do options[index] = { itemID = index + 100 } end
      return { quantityMin = 1, reagentSlotSchematics = {
        { required = false, quantityRequired = 1, reagents = options },
      } }
    end
    local nested = coroutine.create(function()
      return NS.Adapters.captureActive('TRADE_SKILL_SHOW', roster)
    end)
    assert.is_true(coroutine.resume(nested))
    assert.are.equal('suspended', coroutine.status(nested))
  end)

  it('uses GetCraft* for Classic Era Enchanting and does not invent an item output', function()
    _G.C_TradeSkillUI = nil
    _G.GetProfessions = function() return 1 end
    _G.GetCraftSkillLine = function(index) assert.are.equal(1, index); return 'Enchanting' end
    _G.GetCraftDisplaySkillLine = function() return 'Enchanting', 50, 75 end
    _G.GetProfessionInfo = function(index)
      if index == 1 then return 'Enchanting', 1, 50, 75, 0, 0, 333 end
      return 'Profession', 1, 25, 75, 0, 0, 171
    end
    _G.GetNumCrafts = function() return 1 end
    _G.GetCraftInfo = function() return 'Enchant Boots', nil, 'optimal', 0 end
    _G.GetCraftRecipeLink = function() return '|Henchant:7418|h[Enchant]|h' end
    _G.GetCraftItemLink = function() return nil end
    _G.GetCraftNumReagents = function() return 1 end
    _G.GetCraftReagentInfo = function() return 'Dust', 1, 2, 0 end
    _G.GetCraftReagentItemLink = function() return '|Hitem:10940:0|h[Dust]|h' end
    local roster = assert(NS.Adapters.knownProfessions())
    -- Era's Vanilla CraftFrame has no slot dropdown or makeable checkbox; the
    -- API's optional GetCraftFilter(0) result is not used by that UI build.
    _G.GetCraftFilter = function() return nil end
    local _, _, vanillaComplete = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_true(vanillaComplete)

    -- TBC's FrameXML creates the slot dropdown, calls SetCraftFilter(0) on
    -- load, and renders the makeable checkbox. Certify these real UI states.
    _G.CraftFrame = { Dropdown = {} }
    _G.GetCraftSlots = function() return 'Head', 'Hands' end
    _G.GetCraftFilter = function(index) return index == 0 and 1 or nil end
    _G.CraftFrameAvailableFilterCheckButton = { GetChecked = function() return false end }
    _G.GetCraftItemNameFilter = function() return nil end
    local _, _, tbcUnfiltered = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_true(tbcUnfiltered)
    _G.GetCraftFilter = function(index) return index == 0 and nil or index == 1 and 1 or nil end
    local _, _, tbcPartialSlotFilter = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_false(tbcPartialSlotFilter)
    _G.GetCraftFilter = function(index) return index == 0 and 1 or nil end
    _G.CraftFrameAvailableFilterCheckButton.GetChecked = function() return true end
    local _, _, tbcMakeableFilter = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_false(tbcMakeableFilter)
    _G.CraftFrameAvailableFilterCheckButton.GetChecked = function() return false end
    _G.GetCraftItemNameFilter = function() return 'Enchant' end
    local _, _, tbcNameFilter = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_false(tbcNameFilter)
    _G.GetCraftItemNameFilter = nil
    _G.SetCraftItemNameFilter = function() end
    local _, _, unknownCraftNameFilter = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_false(unknownCraftNameFilter)
    _G.SetCraftItemNameFilter = nil
    _G.GetCraftFilter = function() return nil end -- Calling without index is ambiguous on the TBC dropdown.
    local _, _, tbcAmbiguousAllState = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.is_false(tbcAmbiguousAllState)
    _G.GetCraftFilter = function(index) return index == 0 and 1 or nil end
    local profession, kind, complete = NS.Adapters.captureActive('CRAFT_SHOW', roster)
    assert.are.equal('legacy-craft', kind)
    assert.is_true(complete)
    assert.are.equal(333, profession.skillLineID)
    assert.are.equal(50, profession.currentSkill)
    assert.are.equal(75, profession.maxSkill)
    assert.are.equal(7418, profession.recipes[1].spellID)
    assert.are.same({}, profession.recipes[1].outputs)
    assert.are.equal(10940, profession.recipes[1].reagents[1].itemID)
  end)
end)
