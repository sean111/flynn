local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local Scanner = { generation = 0, active = false, pending = nil, disabled = false }
local scanEvents = {
  TRADE_SKILL_SHOW = true,
  TRADE_SKILL_DATA_SOURCE_CHANGED = true,
  TRADE_SKILL_LIST_UPDATE = true,
  CRAFT_SHOW = true,
}
local MAX_START_RETRIES = 3
local function message(text)
  if _G.print then _G.print('|cff70c7ffFlynn:|r ' .. text) end
end
local function inCombat()
  return _G.InCombatLockdown and _G.InCombatLockdown() == true
end
local function ensureDatabase()
  if Scanner.disabled then return nil end
  local database, err = NS.Model.ensureDatabase(_G.FlynnDB)
  if not database then
    Scanner.disabled = true
    message(err)
    return nil
  end
  _G.FlynnDB = database
  NS.database = database
  return database
end
local function currentKey(identity)
  return NS.Model.identityKey(identity.gameFlavor, identity.region, identity.realm, identity.characterName)
end
local function registerCombatListener(frame)
  if not Scanner.active then return end
  frame:RegisterEvent('PLAYER_REGEN_DISABLED')
end
local function unregisterCombatListener(frame)
  frame:UnregisterEvent('PLAYER_REGEN_DISABLED')
end
local function registerOptionalCraftClose(frame)
  if not _G.GetCraftSkillLine then return false end
  local eventUtils = _G.C_EventUtils
  if eventUtils and type(eventUtils.IsEventValid) == 'function' then
    if eventUtils.IsEventValid('CRAFT_CLOSE') ~= true then return false end
    frame:RegisterEvent('CRAFT_CLOSE')
    return true
  end
  local ok = pcall(frame.RegisterEvent, frame, 'CRAFT_CLOSE')
  return ok
end
local function registerOptionalCraftShow(frame)
  -- Modern clients such as WoW Forever do not expose the legacy CraftFrame
  -- event. Treat it as optional and fail closed on clients that reject it.
  local eventUtils = _G.C_EventUtils
  if eventUtils and type(eventUtils.IsEventValid) == 'function' then
    local validCall, isValid = pcall(eventUtils.IsEventValid, 'CRAFT_SHOW')
    if validCall and isValid ~= true then return false end
  end
  local ok = pcall(frame.RegisterEvent, frame, 'CRAFT_SHOW')
  return ok
end
local function cleanup(frame)
  Scanner.active = false
  Scanner.pending = nil
  Scanner.pendingEvent = nil
  Scanner.context = nil
  Scanner.thread = nil
  unregisterCombatListener(frame)
end

local resumeScan
local function makeContext(event, identity)
  local roster, rosterComplete, rosterError = NS.Adapters.knownProfessions()
  if not rosterComplete then return nil, rosterError, true end
  local source, sourceError, retryable = NS.Adapters.inspectActive(event, roster)
  if not source then return nil, sourceError, retryable end
  local rosterEntry
  for _, profession in ipairs(roster) do
    if profession.skillLineID == source.skillLineID then rosterEntry = profession; break end
  end
  if not rosterEntry or not rosterEntry.requiresRecipeScan then
    return nil, 'The active profession is not in the complete recipe-bearing roster.'
  end
  return {
    event = event,
    identity = identity,
    identityKey = currentKey(identity),
    roster = roster,
    rosterSignature = NS.Adapters.rosterSignature(roster),
    source = source,
  }
end

local function startPendingScan(frame, generation, fallbackEvent, attempt)
  if generation ~= Scanner.generation or Scanner.pending ~= generation then return end
  if inCombat() then
    cleanup(frame)
    message('Combat is active; scan skipped. Reopen or update the profession window after combat to retry.')
    return
  end
  local database = ensureDatabase()
  if not database then cleanup(frame); return end
  local identity, identityError = NS.Model.getCharacterIdentity()
  if not identity then cleanup(frame); message(identityError); return end
  local key = currentKey(identity)
  if database.settings.ignored[key] then cleanup(frame); return end
  local scheduledEvent = Scanner.pendingEvent or fallbackEvent
  local context, contextError, retryable = makeContext(scheduledEvent, identity)
  if not context then
    if retryable and attempt < MAX_START_RETRIES and _G.C_Timer and _G.C_Timer.After then
      _G.C_Timer.After(0, function() startPendingScan(frame, generation, fallbackEvent, attempt + 1) end)
      return
    end
    cleanup(frame)
    message(tostring(contextError or 'Profession source could not be verified.') .. ' Nothing was scanned.')
    return
  end
  Scanner.context = context
  Scanner.thread = coroutine.create(function()
    return NS.Adapters.captureActive(scheduledEvent, context.roster, context.source)
  end)
  Scanner.active = true
  registerCombatListener(frame)
  resumeScan(frame, generation)
end

local function validateContext(context)
  if not context then return false, 'The profession scan context is missing.' end
  local identity, identityError = NS.Model.getCharacterIdentity()
  if not identity then return false, identityError end
  if currentKey(identity) ~= context.identityKey or identity.guid ~= context.identity.guid then
    return false, 'The player character changed during the scan.'
  end
  local database = _G.FlynnDB
  if not database or not database.settings or database.settings.ignored[context.identityKey] then
    return false, 'Character tracking was ignored during the scan.'
  end
  local roster, complete, rosterError = NS.Adapters.knownProfessions()
  if not complete or NS.Adapters.rosterSignature(roster) ~= context.rosterSignature then
    return false, rosterError or 'The player profession roster changed during the scan.'
  end
  local source, sourceError = NS.Adapters.inspectActive(context.event, roster)
  if not source then return false, sourceError end
  if source.kind ~= context.source.kind or source.skillLineID ~= context.source.skillLineID
    or source.rowCount ~= context.source.rowCount or source.sourceCounter ~= context.source.sourceCounter then
    return false, 'The active profession source changed during the scan.'
  end
  local rosterEntry
  for _, profession in ipairs(roster) do
    if profession.skillLineID == source.skillLineID then rosterEntry = profession; break end
  end
  if not rosterEntry or not rosterEntry.requiresRecipeScan then
    return false, 'The active profession is no longer in the recipe-bearing roster.'
  end
  return true
end

resumeScan = function(frame, generation)
  if generation ~= Scanner.generation or not Scanner.active then return end
  if inCombat() then
    cleanup(frame)
    message('Scan canceled because combat started. Reopen or update the profession window to retry.')
    return
  end
  local context = Scanner.context
  local valid, validationError = validateContext(context)
  if not valid then
    cleanup(frame)
    message(validationError .. ' Nothing was replaced; reopen/update the profession to retry.')
    return
  end
  local ok, profession, _, complete, err = coroutine.resume(Scanner.thread)
  if not ok then
    cleanup(frame)
    message('Profession scan failed safely: ' .. tostring(profession))
    return
  end
  if coroutine.status(Scanner.thread) ~= 'dead' then
    _G.C_Timer.After(0, function() resumeScan(frame, generation) end)
    return
  end
  if not profession then
    cleanup(frame)
    message(err or 'No profession data was available; open the profession and retry.')
    return
  end
  if not complete then
    cleanup(frame)
    message('The profession list is filtered/collapsed or an ID was unavailable. Expand all categories and retry; nothing was replaced.')
    return
  end
  valid, validationError = validateContext(context)
  cleanup(frame)
  if not valid then
    message(validationError .. ' Nothing was replaced; reopen/update the profession to retry.')
    return
  end
  local database = ensureDatabase()
  if not database then return end
  local tracked, reason = NS.Model.track(database, context.identity)
  if not tracked then message(reason); return end
  local done, captureError = NS.Model.captureCharacter(database, context.identity, context.roster, context.rosterSignature, profession)
  if captureError then message(captureError); return end
  if done then
    message('Complete profession snapshot saved for ' .. context.identity.characterName .. '. Use /reload or log out before syncing Flynn.lua.')
  else
    message('Captured ' .. tostring(profession.skillLineID) .. '; open every recipe-bearing profession in the verified roster to refresh the complete upload snapshot. Gather-only, Fishing, and Archaeology ranks were included automatically. Use /reload or log out before syncing.')
  end
end

function Scanner.initialize()
  local frame = _G.CreateFrame('Frame')
  Scanner.frame = frame
  frame:RegisterEvent('PLAYER_LOGIN')
  frame:RegisterEvent('TRADE_SKILL_CLOSE')
  Scanner.craftCloseRegistered = registerOptionalCraftClose(frame)
  for event in pairs(scanEvents) do
    if event == 'CRAFT_SHOW' then
      Scanner.craftShowRegistered = registerOptionalCraftShow(frame)
    else
      frame:RegisterEvent(event)
    end
  end
  frame:SetScript('OnEvent', function(self, event)
    if event == 'PLAYER_LOGIN' then
      local database = ensureDatabase()
      if not database then return end
      local identity, identityError = NS.Model.getCharacterIdentity()
      if not identity then message(identityError); return end
      local tracked, reason = NS.Model.track(database, identity)
      if tracked then
        message('Tracking this character in FlynnDB. To make a safe upload snapshot, open every known profession window once. SavedVariables are written on /reload, logout, or game exit.')
      else
        message(reason .. ' Change this in /flynn settings.')
      end
      if tracked then
        local roster, rosterComplete, rosterError = NS.Adapters.knownProfessions()
        if rosterComplete then
          local signature = NS.Adapters.rosterSignature(roster)
          local saved = NS.Model.captureCharacter(database, identity, roster, signature, nil)
          if saved then message('This character has only roster-based professions; their complete empty-recipe snapshot is ready.') end
        elseif rosterError then
          message(rosterError .. ' Profession snapshots remain unavailable until the roster API succeeds.')
        end
      end
      if NS.UI then NS.UI.refreshSettings() end
      return
    end
    if event == 'TRADE_SKILL_CLOSE' or event == 'CRAFT_CLOSE' then
      Scanner.generation = Scanner.generation + 1
      cleanup(self)
      return
    end
    if event == 'PLAYER_REGEN_DISABLED' then
      Scanner.generation = Scanner.generation + 1
      cleanup(self)
      message('Scan canceled because combat started. Reopen or update the profession window to retry.')
      return
    end
    if not scanEvents[event] then return end
    if Scanner.active then
      -- A source switch changes the meaning of the currently displayed recipe index.
      -- Re-start only for that explicit event; repeated SHOW/LIST_UPDATE storms coalesce.
      if event == 'TRADE_SKILL_LIST_UPDATE' then
        Scanner.generation = Scanner.generation + 1
        cleanup(self)
        message('The profession recipe list changed during scanning; the partial scan was discarded. Update/reopen it to retry.')
        return
      end
      if event ~= 'TRADE_SKILL_DATA_SOURCE_CHANGED' then return end
    end
    if Scanner.pending then
      Scanner.pendingEvent = event
      return
    end
    Scanner.generation = Scanner.generation + 1
    local generation = Scanner.generation
    cleanup(self)
    if inCombat() then
      message('Combat is active; scan skipped. Reopen or update the profession window after combat to retry.')
      return
    end
    if not _G.C_Timer or not _G.C_Timer.After then
      message('This client does not provide C_Timer.After; safe frame-budgeted scanning is unavailable.')
      return
    end
    Scanner.pending = generation
    Scanner.pendingEvent = event
    _G.C_Timer.After(0, function() startPendingScan(self, generation, event, 0) end)
  end)
  return frame
end

function Scanner.cancel()
  Scanner.generation = Scanner.generation + 1
  if Scanner.frame then cleanup(Scanner.frame) end
end

NS.Scanner = Scanner
