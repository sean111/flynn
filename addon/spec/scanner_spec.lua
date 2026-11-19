local helpers = dofile('addon/spec/helpers.lua')

describe('event-driven scanner lifecycle', function()
  local NS, frame, timers, combat, activeProfession, roster, rosterComplete, guid, sourceFailure, sourceRetryable
  before_each(function()
    NS = helpers.reset()
    timers, combat, activeProfession, rosterComplete, guid, sourceFailure, sourceRetryable = {}, false, 171, true, 'Player-1-123', nil, false
    roster = { { skillLineID = 171, currentSkill = 50, maxSkill = 100, name = 'Alchemy', requiresRecipeScan = true } }
    _G.C_Timer = { After = function(_, callback) timers[#timers + 1] = callback end }
    _G.InCombatLockdown = function() return combat end
    _G.GetCurrentRegion = function() return 1 end
    _G.GetRealmName = function() return 'Area 52' end
    _G.UnitName = function() return 'Crafter' end
    _G.UnitClass = function() return 'Mage', 'MAGE' end
    _G.UnitGUID = function() return guid end
    _G.UnitFactionGroup = function() return 'Alliance' end
    _G.GetProfessions = function() return 1 end
    _G.GetProfessionInfo = function() return 'Alchemy', 1, 50, 100, 0, 0, 171 end
    _G.WOW_PROJECT_ID, _G.WOW_PROJECT_MAINLINE = 1, 1
    _G.GetCraftSkillLine = nil
    _G.CreateFrame = function()
      local object = { events = {}, scripts = {} }
      function object:RegisterEvent(event) self.events[event] = true end
      function object:UnregisterEvent(event) self.events[event] = nil end
      function object:SetScript(name, callback) self.scripts[name] = callback end
      function object:fire(event) if self.events[event] then self.scripts.OnEvent(self, event) end end
      frame = object
      return object
    end
    _G.print = function() end
    NS.Adapters.knownProfessions = function() return roster, rosterComplete, 'mock roster unavailable' end
    NS.Adapters.inspectActive = function()
      if sourceFailure then return nil, sourceFailure, sourceRetryable end
      return { kind = 'mock', skillLineID = activeProfession }
    end
    NS.Adapters.captureActive = function() return helpers.profession(171), 'mock', true end
    NS.Scanner.initialize()
  end)

  local function runNext()
    local callback = table.remove(timers, 1)
    if callback then callback() end
  end

  it('does no work on idle/login and registers combat only for active scans', function()
    assert.is_nil(frame.scripts.OnUpdate)
    assert.is_nil(frame.events.PLAYER_REGEN_DISABLED)
    frame:fire('PLAYER_LOGIN')
    assert.are.equal(0, #timers)
    frame:fire('TRADE_SKILL_SHOW')
    assert.are.equal(1, #timers)
    runNext()
    assert.is_nil(frame.events.PLAYER_REGEN_DISABLED)
    assert.is_true(NS.Model.isUploadable(NS.database, NS.Model.getCharacterIdentity()))
  end)

  it('creates a complete snapshot at login when the verified roster contains only recipe-less lines', function()
    roster = {
      { skillLineID = 182, currentSkill = 50, maxSkill = 75, name = 'Herbalism', requiresRecipeScan = false },
      { skillLineID = 356, currentSkill = 40, maxSkill = 75, name = 'Fishing', requiresRecipeScan = false },
      { skillLineID = 393, currentSkill = 25, maxSkill = 75, name = 'Skinning', requiresRecipeScan = false },
      { skillLineID = 794, currentSkill = 10, maxSkill = 75, name = 'Archaeology', requiresRecipeScan = false },
    }
    frame:fire('PLAYER_LOGIN')
    local saved = NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter')
    assert.are.equal(4, #saved.professions)
    for _, profession in ipairs(saved.professions) do assert.are.same({}, profession.recipes) end
    assert.are.equal(0, #timers)
  end)

  it('invalidates pending/stale callbacks on close or source changes', function()
    frame:fire('TRADE_SKILL_SHOW')
    local stale = table.remove(timers, 1)
    frame:fire('TRADE_SKILL_CLOSE')
    stale()
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.database)
    frame:fire('TRADE_SKILL_DATA_SOURCE_CHANGED')
    runNext()
    assert.is_true(NS.Model.isUploadable(NS.database, NS.Model.getCharacterIdentity()))
  end)

  it('preserves the prior complete snapshot when roster verification fails or a new recipe profession is only partly scanned', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    _G.FlynnDB, NS.database = db, db
    local character = NS.Model.getCharacterIdentity()
    NS.Model.track(db, character)
    local oldRoster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true },
      { skillLineID = 164, currentSkill = 40, maxSkill = 75, requiresRecipeScan = true },
    }
    local oldSignature = NS.Adapters.rosterSignature(oldRoster)
    NS.Model.captureCharacter(db, character, oldRoster, oldSignature, helpers.profession(171))
    NS.Model.captureCharacter(db, character, oldRoster, oldSignature, helpers.profession(164))
    local previous = NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter')
    rosterComplete = false
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    assert.are.equal(previous, NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
    assert.is_true(NS.Model.isUploadable(db, previous))

    rosterComplete = true
    roster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 100, name = 'Alchemy', requiresRecipeScan = true },
      { skillLineID = 164, currentSkill = 40, maxSkill = 75, name = 'Blacksmithing', requiresRecipeScan = true },
      { skillLineID = 202, currentSkill = 10, maxSkill = 75, name = 'Engineering', requiresRecipeScan = true },
    }
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    assert.are.equal(previous, NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
  end)

  it('cancels without committing when identity, window source, or full roster changes across yields', function()
    NS.Adapters.captureActive = function()
      coroutine.yield()
      return helpers.profession(171), 'mock', true
    end
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    guid = 'Player-1-changed'
    runNext()
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))

    guid = 'Player-1-123'
    activeProfession = 171
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    activeProfession = 164
    runNext()
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))

    activeProfession = 171
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    roster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 100, name = 'Alchemy', requiresRecipeScan = true },
      { skillLineID = 164, currentSkill = 25, maxSkill = 75, name = 'Blacksmithing', requiresRecipeScan = true },
    }
    runNext()
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))
  end)

  it('coalesces repeated eligible UI events into one pending scan', function()
    frame:fire('TRADE_SKILL_SHOW')
    frame:fire('TRADE_SKILL_LIST_UPDATE')
    frame:fire('TRADE_SKILL_SHOW')
    assert.are.equal(1, #timers)
    runNext()
    assert.is_true(NS.Model.isUploadable(NS.database, NS.Model.getCharacterIdentity()))
  end)

  it('retries an unready event-started source only a bounded number of active timer turns', function()
    sourceFailure, sourceRetryable = 'profession data is not ready yet', true
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    assert.is_false(NS.Scanner.active)
    assert.is_not_nil(NS.Scanner.pending)
    assert.is_nil(frame.events.PLAYER_REGEN_DISABLED)
    sourceFailure = nil
    runNext()
    assert.is_true(NS.Model.isUploadable(NS.database, NS.Model.getCharacterIdentity()))
    assert.is_false(NS.Scanner.active)
    assert.are.equal(0, #timers)
  end)

  it('cancels a yielded scan immediately on combat and rejects combat-started work', function()
    NS.Adapters.captureActive = function()
      coroutine.yield()
      return helpers.profession(171), 'mock', true
    end
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    assert.is_true(NS.Scanner.active)
    assert.is_true(frame.events.PLAYER_REGEN_DISABLED)
    frame:fire('PLAYER_REGEN_DISABLED')
    assert.is_false(NS.Scanner.active)
    assert.is_nil(frame.events.PLAYER_REGEN_DISABLED)
    runNext() -- stale continuation must not commit a partial scan
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))
    combat = true
    frame:fire('TRADE_SKILL_LIST_UPDATE')
    assert.are.equal(0, #timers)
    assert.is_false(NS.Scanner.active)
  end)

  it('restarts an active scan when its list-update event fires', function()
    local captures = 0
    NS.Adapters.captureActive = function()
      captures = captures + 1
      if captures == 1 then coroutine.yield() end
      return helpers.profession(171), 'mock', true
    end
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    assert.is_true(NS.Scanner.active)
    frame:fire('TRADE_SKILL_LIST_UPDATE')
    assert.is_false(NS.Scanner.active)
    runNext() -- stale continuation cannot affect the restarted scan
    runNext() -- begin the retry
    assert.is_true(NS.Model.isUploadable(NS.database, NS.Model.getCharacterIdentity()))
    assert.are.equal(2, captures)
    assert.is_false(NS.Scanner.active)
  end)

  it('bounds automatic restarts when the profession list keeps changing', function()
    local captures = 0
    NS.Adapters.captureActive = function()
      captures = captures + 1
      coroutine.yield()
      return helpers.profession(171), 'mock', true
    end
    frame:fire('TRADE_SKILL_SHOW')
    runNext()
    for _ = 1, 3 do
      frame:fire('TRADE_SKILL_LIST_UPDATE')
      runNext() -- stale continuation
      runNext() -- begin the bounded retry
      assert.is_true(NS.Scanner.active)
    end
    frame:fire('TRADE_SKILL_LIST_UPDATE')
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.Scanner.pending)
    assert.are.equal(4, captures)
    runNext() -- final stale continuation cannot commit
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))
  end)

  it('registers CRAFT_CLOSE only when the legacy Craft API exists and cancels that window', function()
    assert.is_nil(frame.events.CRAFT_CLOSE)
    _G.GetCraftSkillLine = function() return 'Enchanting' end
    _G.C_EventUtils = { IsEventValid = function() return false end }
    NS.Scanner.initialize()
    assert.is_nil(frame.events.CRAFT_CLOSE)
    _G.C_EventUtils.IsEventValid = function() return true end
    NS.Scanner.initialize()
    assert.is_true(frame.events.CRAFT_CLOSE)
    frame:fire('PLAYER_LOGIN')
    frame:fire('TRADE_SKILL_SHOW')
    local stale = table.remove(timers, 1)
    frame:fire('CRAFT_CLOSE')
    stale()
    assert.is_false(NS.Scanner.active)
    assert.is_nil(NS.Model.getCharacter(NS.database, 'retail', 'us', 'Area 52', 'Crafter'))
  end)

  it('continues initialization when a client rejects an optional profession event', function()
    local originalCreateFrame = _G.CreateFrame
    _G.CreateFrame = function(...)
      local object = originalCreateFrame(...)
      local originalRegisterEvent = object.RegisterEvent
      function object:RegisterEvent(event)
        if event == 'CRAFT_SHOW' then error('unsupported event') end
        return originalRegisterEvent(self, event)
      end
      return object
    end
    local compatibleFrame = NS.Scanner.initialize()
    assert.is_not_nil(compatibleFrame.scripts.OnEvent)
    assert.is_nil(compatibleFrame.events.CRAFT_SHOW)
    assert.is_true(compatibleFrame.events.PLAYER_LOGIN)
    assert.is_true(compatibleFrame.events.TRADE_SKILL_SHOW)
  end)
end)
