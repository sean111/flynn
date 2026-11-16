local NS = {}
_G.LibStub = nil
_G.strmatch = string.match
assert(loadfile('addon/Flynn/Libs/LibStub/LibStub.lua'))()
assert(loadfile('addon/Flynn/Libs/LibDeflate/LibDeflate.lua'))()
assert(loadfile('addon/Flynn/Core/Json.lua'))('Flynn', NS)
assert(loadfile('addon/Flynn/Core/Base64.lua'))('Flynn', NS)
local doc = {
  schemaVersion = 1,
  characters = {
    retail = { us = { ['Area 52'] = { ['Mérida'] = {
      gameFlavor = 'retail', region = 'us', realm = 'Area 52', characterName = 'Mérida',
      guid = 'Player-1-abc', faction = 'alliance', class = 'MAGE', capturedAt = 1760000000, professions = {},
    } } } },
    classic_era = { eu = { Auberdine = { ['Étoile'] = {
      gameFlavor = 'classic_era', region = 'eu', realm = 'Auberdine', characterName = 'Étoile',
      guid = 'Player-2-def', faction = 'horde', class = 'PRIEST', capturedAt = 1760000001,
      professions = { { skillLineID = 333, currentSkill = 50, maxSkill = 75, recipes = {
        { spellID = 7418, outputs = {}, reagents = { { itemID = 10940, quantity = 2 } } },
      } } },
    } } } },
    forever = { us = { Camelot = { ['Anvil'] = {
      gameFlavor = 'forever', region = 'us', realm = 'Camelot', characterName = 'Anvil',
      guid = 'Player-18-ghi', faction = 'alliance', class = 'WARRIOR', capturedAt = 1760000002,
      professions = { { skillLineID = 164, currentSkill = 50, maxSkill = 100, recipes = {
        { spellID = 12345, outputs = { { itemID = 5001, quantity = 1 } }, reagents = {} },
      } } },
    } } } },
  },
}
local json = assert(NS.Json.encode(doc))
local compressed = LibStub:GetLibrary('LibDeflate'):CompressDeflate(json, { level = 1 })
local file = assert(io.open(arg[1], 'wb'))
file:write('FLYNN1:', NS.Base64.encode(compressed))
file:close()
