local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local UI = { exportSelections = {} }
local function makeFrame(name, parent, template)
  return _G.CreateFrame('Frame', name, parent, template)
end
local function text(parent, value, size)
  local font = parent:CreateFontString(nil, 'OVERLAY', 'GameFontNormal')
  font:SetText(value)
  font:SetJustifyH('LEFT')
  font:SetWidth(size or 500)
  return font
end
local function message(value)
  if _G.print then _G.print('|cff70c7ffFlynn:|r ' .. value) end
end

local function allExportable()
  local database = NS.database
  local characters = database and NS.Model.exportableCharacters(database) or {}
  local rows = {}
  for flavor, regions in pairs(characters) do
    for region, realms in pairs(regions) do
      for realm, characterMap in pairs(realms) do
        for characterName, record in pairs(characterMap) do
          rows[#rows + 1] = { key = NS.Model.identityKey(flavor, region, realm, characterName), label = characterName .. ' — ' .. realm .. ' (' .. flavor .. '/' .. region .. ')', record = record }
        end
      end
    end
  end
  table.sort(rows, function(a, b) return a.label < b.label end)
  return rows
end

local function selectedPayload(rows)
  local selected = {}
  local selectedCount = 0
  for _, row in ipairs(rows) do
    if UI.exportSelections[row.key] ~= false then
      selectedCount = selectedCount + 1
      local record = row.record
      local flavor, region, realm, name = record.gameFlavor, record.region, record.realm, record.characterName
      selected[flavor] = selected[flavor] or {}
      selected[flavor][region] = selected[flavor][region] or {}
      selected[flavor][region][realm] = selected[flavor][region][realm] or {}
      selected[flavor][region][realm][name] = record
    end
  end
  if selectedCount == 0 then return nil end
  return selected
end

local function createExport()
  local rows = allExportable()
  local characters = selectedPayload(rows)
  if not characters then return nil, 'Select at least one complete character.' end
  local ok, err = NS.Model.validateExport(characters)
  if not ok then return nil, err end
  local json, jsonError = NS.Json.encode({ schemaVersion = NS.Model.SCHEMA_VERSION, characters = characters })
  if not json then return nil, jsonError end
  local decodedLimit = 20 * 1024 * 1024
  if #json > decodedLimit then return nil, 'Uncompressed JSON exceeds the 20 MiB decoded-document limit. Nothing was truncated.' end
  local deflate = _G.LibStub and _G.LibStub:GetLibrary('LibDeflate', true)
  if not deflate then return nil, 'Bundled LibDeflate is unavailable; reinstall Flynn.' end
  local compressed = deflate:CompressDeflate(json, { level = 1 })
  if not compressed then return nil, 'DEFLATE compression failed.' end
  local export = 'FLYNN1:' .. NS.Base64.encode(compressed)
  if #export > 4 * 1024 * 1024 then return nil, 'Export exceeds the 4 MiB export-string limit. Nothing was truncated; reduce the selected characters or use the SavedVariables file.' end
  return export
end

local function close(frame)
  if frame and frame.Hide then frame:Hide() end
end

function UI.openExport()
  local rows = allExportable()
  if #rows == 0 then
    message('No complete tracked snapshots are uploadable yet. Open every known profession window for each character. Use /reload or log out before syncing SavedVariables.')
    return
  end
  local frame = UI.exportFrame
  if not frame then
    frame = makeFrame(nil, _G.UIParent, 'BackdropTemplate')
    frame:SetSize(610, 520)
    frame:SetPoint('CENTER')
    frame:SetFrameStrata('DIALOG')
    frame:SetBackdrop({ bgFile = 'Interface/Tooltips/UI-Tooltip-Background', edgeFile = 'Interface/Tooltips/UI-Tooltip-Border', tile = true, tileSize = 16, edgeSize = 16, insets = { left = 4, right = 4, top = 4, bottom = 4 } })
    frame:SetBackdropColor(0, 0, 0, 0.96)
    frame:EnableMouse(true)
    frame:SetMovable(true)
    frame:RegisterForDrag('LeftButton')
    frame:SetScript('OnDragStart', frame.StartMoving)
    frame:SetScript('OnDragStop', frame.StopMovingOrSizing)
    local title = text(frame, 'Flynn Export — choose complete character snapshots', 560)
    title:SetPoint('TOPLEFT', 18, -16)
    local instruction = text(frame, 'All complete tracked characters are selected. Uncheck any to export a subset. Separate WoW accounts/installs cannot be merged in-game.', 560)
    instruction:SetPoint('TOPLEFT', title, 'BOTTOMLEFT', 0, -8)
    local scroll = makeFrame(nil, frame, 'ScrollFrameTemplate')
    scroll:SetPoint('TOPLEFT', instruction, 'BOTTOMLEFT', 0, -12)
    scroll:SetSize(560, 215)
    local content = makeFrame(nil, scroll)
    content:SetSize(530, 1)
    scroll:SetScrollChild(content)
    frame.listContent = content
    frame.listScroll = scroll
    local output = _G.CreateFrame('EditBox', nil, frame)
    output:SetSize(560, 158)
    output:SetPoint('BOTTOMLEFT', 18, 54)
    output:SetMultiLine(true)
    output:SetMaxLetters(4 * 1024 * 1024)
    output:SetAutoFocus(false)
    output:SetFontObject('ChatFontNormal')
    output:SetTextInsets(6, 6, 5, 5)
    output:SetScript('OnTextChanged', function(self, userInput)
      if userInput then self:SetText(UI.exportBuffer or '') end
    end)
    output:SetScript('OnChar', function(self) self:SetText(UI.exportBuffer or '') end)
    output:SetScript('OnKeyDown', function(_, key) if key == 'ESCAPE' then close(frame) end end)
    frame.output = output
    local help = text(frame, 'Click in the box, press Ctrl+A then Ctrl+C. Escape closes this window. SavedVariables are written only after /reload, logout, or game exit.', 560)
    help:SetPoint('BOTTOMLEFT', 18, 34)
    local build = _G.CreateFrame('Button', nil, frame, 'UIPanelButtonTemplate')
    build:SetSize(175, 24)
    build:SetPoint('BOTTOMLEFT', 18, 9)
    build:SetText('Build selected export')
    build:SetScript('OnClick', function()
      local payload, err = createExport()
      if not payload then message(err); return end
      UI.exportBuffer = payload
      output:SetText(payload)
      output:SetFocus()
      output:HighlightText()
    end)
    local closeButton = _G.CreateFrame('Button', nil, frame, 'UIPanelButtonTemplate')
    closeButton:SetSize(80, 24)
    closeButton:SetPoint('BOTTOMRIGHT', -18, 9)
    closeButton:SetText('Close')
    closeButton:SetScript('OnClick', function() close(frame) end)
    frame:SetScript('OnKeyDown', function(self, key) if key == 'ESCAPE' then close(self) end end)
    frame:EnableKeyboard(true)
    if frame.SetPropagateKeyboardInput then frame:SetPropagateKeyboardInput(true) end
    UI.exportFrame = frame
  end
  for _, child in ipairs({ frame.listContent:GetChildren() }) do child:Hide(); child:SetParent(nil) end
  local y = -2
  for _, row in ipairs(rows) do
    if UI.exportSelections[row.key] == nil then UI.exportSelections[row.key] = true end
    local check = _G.CreateFrame('CheckButton', nil, frame.listContent, 'UICheckButtonTemplate')
    check:SetPoint('TOPLEFT', 0, y)
    check:SetSize(520, 24)
    check:SetChecked(UI.exportSelections[row.key])
    check.label = check:CreateFontString(nil, 'OVERLAY', 'GameFontHighlight')
    check.label:SetPoint('LEFT', check, 'RIGHT', 2, 0)
    check.label:SetText(row.label)
    check:SetScript('OnClick', function(self) UI.exportSelections[row.key] = self:GetChecked() == true end)
    y = y - 25
  end
  frame.listContent:SetHeight(math.max(1, -y))
  frame.output:SetText('')
  UI.exportBuffer = ''
  frame:Show()
end

UI.createExport = createExport

function UI.refreshSettings()
  if not UI.settingsCheck or not NS.database then return end
  local identity = NS.Model.getCharacterIdentity()
  if not identity then return end
  local key = NS.Model.identityKey(identity.gameFlavor, identity.region, identity.realm, identity.characterName)
  UI.settingsCheck:SetChecked(NS.database.settings.ignored[key] ~= true)
end

function UI.openSettings()
  if not NS.database then return end
  local frame = UI.settingsFrame
  if not frame then
    frame = makeFrame(nil, _G.UIParent, 'BackdropTemplate')
    frame:SetSize(480, 210)
    frame:SetPoint('CENTER')
    frame:SetFrameStrata('DIALOG')
    frame:SetBackdrop({ bgFile = 'Interface/Tooltips/UI-Tooltip-Background', edgeFile = 'Interface/Tooltips/UI-Tooltip-Border', tile = true, tileSize = 16, edgeSize = 16, insets = { left = 4, right = 4, top = 4, bottom = 4 } })
    frame:SetBackdropColor(0, 0, 0, 0.96)
    local title = text(frame, 'Flynn character tracking', 440)
    title:SetPoint('TOPLEFT', 18, -18)
    local check = _G.CreateFrame('CheckButton', nil, frame, 'UICheckButtonTemplate')
    check:SetPoint('TOPLEFT', 16, -58)
    check:SetSize(24, 24)
    local label = text(frame, 'Track and include this character in Flynn exports', 420)
    label:SetPoint('LEFT', check, 'RIGHT', 4, 0)
    check:SetScript('OnClick', function(self)
      local identity = NS.Model.getCharacterIdentity()
      if not identity then message('Character identity is unavailable; tracking preference was not changed.'); return end
      if self:GetChecked() ~= true and NS.Scanner then NS.Scanner.cancel() end
      NS.Model.setIgnored(NS.database, identity, self:GetChecked() ~= true)
      if self:GetChecked() then
        message('Tracking re-enabled. Previous snapshot is gone; open every known profession window to make a new one.')
      else
        message('Character ignored. Its uploadable snapshot was removed and scans are disabled; the minimal ignore preference remains in FlynnDB.settings.')
      end
    end)
    UI.settingsCheck = check
    local details = text(frame, 'Each primary/secondary profession window must be opened once before a full replacement snapshot is exported. An interrupted scan never replaces the last complete snapshot.', 440)
    details:SetPoint('TOPLEFT', check, 'BOTTOMLEFT', 0, -12)
    local closeButton = _G.CreateFrame('Button', nil, frame, 'UIPanelButtonTemplate')
    closeButton:SetSize(80, 24)
    closeButton:SetPoint('BOTTOMRIGHT', -18, 14)
    closeButton:SetText('Close')
    closeButton:SetScript('OnClick', function() close(frame) end)
    frame:SetScript('OnKeyDown', function(self, key) if key == 'ESCAPE' then close(self) end end)
    frame:EnableKeyboard(true)
    if frame.SetPropagateKeyboardInput then frame:SetPropagateKeyboardInput(true) end
    UI.settingsFrame = frame
  end
  UI.refreshSettings()
  frame:Show()
end

function UI.status()
  local buildInfo = _G.GetBuildInfo
  if type(buildInfo) == 'function' then
    local ok, version, build, _, interface = pcall(buildInfo)
    if ok then
      message('Client ' .. tostring(version) .. ' build ' .. tostring(build) .. ', interface ' .. tostring(interface) .. '.')
    else
      message('Client build information is unavailable: ' .. tostring(version))
    end
  else
    message('Client build information is unavailable.')
  end

  local identity, identityError = NS.Model.getCharacterIdentity()
  if identity then
    local key = NS.Model.identityKey(identity.gameFlavor, identity.region, identity.realm, identity.characterName)
    local settings = NS.database and NS.database.settings
    local tracked = settings and settings.tracked and settings.tracked[key] == true
    message('Character ' .. identity.characterName .. ' (' .. identity.gameFlavor .. '); tracking ' .. (tracked and 'on.' or 'off.'))
  else
    message(tostring(identityError))
  end

  local ok, roster, complete, rosterError = pcall(NS.Adapters.knownProfessions)
  if not ok then
    message('Profession roster check failed: ' .. tostring(roster))
  elseif complete then
    local scanRequired = 0
    for _, profession in ipairs(roster) do
      if profession.requiresRecipeScan then scanRequired = scanRequired + 1 end
    end
    message('Profession roster available: ' .. tostring(#roster) .. ' total, ' .. tostring(scanRequired) .. ' require scans.')
  else
    message('Profession roster unavailable: ' .. tostring(rosterError))
  end

  local scanner = NS.Scanner
  message('Scanner frame ' .. (scanner and scanner.frame and 'created' or 'missing') .. '; scan ' .. (scanner and scanner.active and 'active.' or 'idle.'))
end

function UI.installCommands()
  _G.SLASH_FLYNN1 = '/flynn'
  _G.SlashCmdList = _G.SlashCmdList or {}
  _G.SlashCmdList.FLYNN = function(input)
    local command = (input or ''):lower():match('^%s*(.-)%s*$')
    if command == 'settings' then UI.openSettings(); return end
    if command == 'status' then UI.status(); return end
    if command == '' or command == 'export' then UI.openExport(); return end
    message('Commands: /flynn export | /flynn settings | /flynn status')
  end
end

NS.UI = UI
