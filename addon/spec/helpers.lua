local M = {}
local root = 'addon/Flynn/Core/'

function M.reset()
  _G.FlynnTestNamespace = {}
  _G.FlynnDB = nil
  _G.LibStub = nil
  _G.strmatch = string.match
  assert(loadfile('addon/Flynn/Libs/LibStub/LibStub.lua'))()
  assert(loadfile('addon/Flynn/Libs/LibDeflate/LibDeflate.lua'))()
  for _, name in ipairs({ 'Json', 'Base64', 'Model', 'Adapters', 'Scanner', 'UI' }) do
    assert(loadfile(root .. name .. '.lua'))('Flynn', _G.FlynnTestNamespace)
  end
  return _G.FlynnTestNamespace
end

function M.character(name)
  return {
    schemaVersion = 1, gameFlavor = 'retail', region = 'us', realm = 'Area 52',
    characterName = name or 'Crafter', guid = 'Player-1-123', faction = 'alliance',
    class = 'MAGE', capturedAt = 1760000000,
  }
end

function M.profession(id, recipes)
  return { skillLineID = id, currentSkill = 50, maxSkill = 100, recipes = recipes or {} }
end

return M
