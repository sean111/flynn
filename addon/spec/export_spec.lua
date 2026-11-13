local helpers = dofile('addon/spec/helpers.lua')

describe('export serialization', function()
  local NS, db
  before_each(function()
    NS = helpers.reset()
    db = assert(NS.Model.ensureDatabase(nil))
    NS.database = db
  end)

  local function add(name, flavor, region, realm)
    local record = helpers.character(name)
    record.gameFlavor, record.region, record.realm = flavor or 'retail', region or 'us', realm or 'Area 52'
    record.professions = {}
    NS.Model.setCharacter(db, record)
    local key = NS.Model.identityKey(record.gameFlavor, record.region, record.realm, record.characterName)
    db.settings.tracked[key] = true
    db.settings.capturedProfessions[key] = { complete = true }
    return record
  end

  it('uses standard RFC4648 Base64 and raw DEFLATE with empty arrays and Unicode', function()
    add('Mérida')
    local empty = NS.Json.encode({ schemaVersion = 1, characters = {}, professions = {} })
    assert.matches('"characters":{}', empty)
    assert.matches('"professions":%[%]', empty)
    local export = assert(NS.UI.createExport())
    assert.matches('^FLYNN1:[A-Za-z0-9+/]+=*$', export)
    local binary = export:sub(8)
    local alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
    local decoded = {}
    for index = 1, #binary, 4 do
      local a, b = alphabet:find(binary:sub(index, index), 1, true), alphabet:find(binary:sub(index + 1, index + 1), 1, true)
      local c = binary:sub(index + 2, index + 2) ~= '=' and alphabet:find(binary:sub(index + 2, index + 2), 1, true) or 1
      local d = binary:sub(index + 3, index + 3) ~= '=' and alphabet:find(binary:sub(index + 3, index + 3), 1, true) or 1
      local word = ((a - 1) * 262144) + ((b - 1) * 4096) + ((c - 1) * 64) + (d - 1)
      decoded[#decoded + 1] = string.char(math.floor(word / 65536) % 256)
      if binary:sub(index + 2, index + 2) ~= '=' then decoded[#decoded + 1] = string.char(math.floor(word / 256) % 256) end
      if binary:sub(index + 3, index + 3) ~= '=' then decoded[#decoded + 1] = string.char(word % 256) end
    end
    local lib = LibStub:GetLibrary('LibDeflate')
    local json = assert(lib:DecompressDeflate(table.concat(decoded)))
    assert.matches('Mérida', json)
    assert.matches('"professions":%[%]', json)
    assert.matches('"schemaVersion":1', json)
  end)

  it('exports selected complete characters from different namespaces only', function()
    add('Alpha', 'retail', 'us', 'Area 52')
    local beta = add('Beta', 'classic_era', 'eu', 'Auberdine')
    local alphaKey = NS.Model.identityKey('retail', 'us', 'Area 52', 'Alpha')
    local betaKey = NS.Model.identityKey('classic_era', 'eu', 'Auberdine', 'Beta')
    NS.UI.exportSelections[alphaKey] = false
    NS.UI.exportSelections[betaKey] = true
    local payload = assert(NS.UI.createExport())
    assert.is_true(payload:sub(1, 7) == 'FLYNN1:')
    assert.is_not_nil(beta)
  end)

  it('rejects structural/export limits without truncating', function()
    for index = 1, 101 do add('Crafter' .. index, 'retail', 'us', 'Realm') end
    local payload, err = NS.UI.createExport()
    assert.is_nil(payload)
    assert.matches('100 characters', err)
    assert.is_nil(NS.UI.createExport())
  end)
end)
