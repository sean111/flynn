local helpers = dofile('addon/spec/helpers.lua')

describe('SavedVariables model', function()
  local NS
  before_each(function() NS = helpers.reset() end)

  it('initializes schema 1 and refuses future versions without changing them', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    assert.are.equal(1, db.schemaVersion)
    local future = { schemaVersion = 7, characters = { keep = true } }
    local result, err = NS.Model.ensureDatabase(future)
    assert.is_nil(result)
    assert.matches('newer', err)
    assert.is_true(future.characters.keep)
  end)

  it('captures stable character metadata and normalizes flavor and region', function()
    _G.WOW_PROJECT_ID, _G.WOW_PROJECT_MAINLINE = 1, 1
    _G.GetCurrentRegion = function() return 3 end
    _G.GetRealmName = function() return 'Area 52' end
    _G.UnitName = function() return 'Mérida' end
    _G.UnitClass = function() return 'Mage', 'MAGE' end
    _G.UnitGUID = function() return 'Player-1-abc' end
    _G.UnitFactionGroup = function() return 'Alliance' end
    _G.FACTION_ALLIANCE = 'Alliance'
    _G.time = function() return 1760000000 end
    local record = NS.Model.getCharacterIdentity()
    assert.are.equal(1, record.schemaVersion)
    assert.are.equal('retail', record.gameFlavor)
    assert.are.equal('eu', record.region)
    assert.are.equal('Mérida', record.characterName)
    assert.are.equal('Player-1-abc', record.guid)
    assert.are.equal('alliance', record.faction)
    assert.are.equal('MAGE', record.class)
    assert.are.equal(1760000000, record.capturedAt)
    _G.WOW_PROJECT_ID, _G.WOW_PROJECT_CLASSIC = 2, 2
    assert.are.equal('classic_era', NS.Model.getFlavor())
    _G.WOW_PROJECT_ID, _G.WOW_PROJECT_BURNING_CRUSADE_CLASSIC = 5, 5
    assert.are.equal('classic', NS.Model.getFlavor())
    _G.WOW_PROJECT_CAMELOT = 18
    _G.WOW_PROJECT_ID = 18
    assert.are.equal('forever', NS.Model.getFlavor())
    _G.WOW_PROJECT_ID = 2
    assert.are.equal('classic_era', NS.Model.getFlavor())
  end)

  it('isolates same-named characters across flavors, regions, realms and characters', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local a, b = helpers.character('Same'), helpers.character('Same')
    b.gameFlavor, b.region, b.realm = 'classic_era', 'eu', 'Other Realm'
    NS.Model.setCharacter(db, a); NS.Model.setCharacter(db, b)
    assert.are.equal(a, NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Same'))
    assert.are.equal(b, NS.Model.getCharacter(db, 'classic_era', 'eu', 'Other Realm', 'Same'))
    assert.is_nil(NS.Model.getCharacter(db, 'classic', 'us', 'Area 52', 'Same'))
    local forever = helpers.character('Same')
    forever.gameFlavor = 'forever'
    NS.Model.setCharacter(db, forever)
    assert.are.equal(forever, NS.Model.getCharacter(db, 'forever', 'us', 'Area 52', 'Same'))
    assert.are.equal(a, NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Same'))
    assert.is_true(NS.Model.validateExport({ forever = { us = { ['Area 52'] = { Same = forever } } } }))
  end)

  it('keeps new/partial scans out of exports and commits a full roster atomically', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local character = helpers.character()
    local roster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 75, requiresRecipeScan = true },
      { skillLineID = 164, currentSkill = 40, maxSkill = 75, requiresRecipeScan = true },
    }
    local signature = '164:40:75:1|171:50:75:1'
    assert.is_true(NS.Model.track(db, character))
    assert.is_false(NS.Model.captureCharacter(db, character, roster, signature, helpers.profession(171)))
    assert.is_nil(NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
    assert.is_nil(db.settings.stagedProfessions)
    assert.are.same({}, NS.Model.exportableCharacters(db))
    assert.is_true(NS.Model.captureCharacter(db, character, roster, signature, helpers.profession(164)))
    local saved = NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter')
    assert.are.same({ 164, 171 }, { saved.professions[1].skillLineID, saved.professions[2].skillLineID })
    assert.is_not.same({}, NS.Model.exportableCharacters(db))
  end)

  it('preserves the last complete snapshot when a replacement is interrupted', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local character = helpers.character()
    assert.is_true(NS.Model.track(db, character))
    local roster = { { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true } }
    NS.Model.captureCharacter(db, character, roster, '171:50:100:1', helpers.profession(171))
    local previous = NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter')
    local changedRoster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true },
      { skillLineID = 999, currentSkill = 1, maxSkill = 75, requiresRecipeScan = true },
    }
    local done, err = NS.Model.captureCharacter(db, character, changedRoster, '171:50:100:1|999:1:75:1', helpers.profession(999))
    assert.is_false(done)
    assert.is_nil(err)
    assert.are.equal(previous, NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
    assert.is_true(NS.Model.isUploadable(db, previous))
  end)

  it('never infers or appends an active profession absent from the complete roster', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local character = helpers.character()
    NS.Model.track(db, character)
    local roster = { { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true } }
    local done, err = NS.Model.captureCharacter(db, character, roster, '171:50:100:1', helpers.profession(164))
    assert.is_false(done)
    assert.matches('not in the complete scan%-required roster', err)
    assert.is_nil(NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
  end)

  it('commits verified recipe-less gathering/secondary lines with ranks and empty recipe arrays', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local character = helpers.character()
    NS.Model.track(db, character)
    local roster = {
      { skillLineID = 182, currentSkill = 75, maxSkill = 75, requiresRecipeScan = false }, -- Herbalism
      { skillLineID = 356, currentSkill = 44, maxSkill = 75, requiresRecipeScan = false }, -- Fishing
      { skillLineID = 393, currentSkill = 20, maxSkill = 75, requiresRecipeScan = false }, -- Skinning
      { skillLineID = 794, currentSkill = 10, maxSkill = 75, requiresRecipeScan = false }, -- Archaeology
    }
    assert.is_true(NS.Model.captureCharacter(db, character, roster, 'gathering-only', nil))
    local saved = NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter')
    assert.are.equal(4, #saved.professions)
    for _, profession in ipairs(saved.professions) do
      assert.are.same({}, profession.recipes)
      assert.is_nil(profession.name)
      assert.is_nil(profession.requiresRecipeScan)
    end
    assert.is_true(NS.Model.isUploadable(db, saved))
  end)

  it('removes ignored data, retains only identity preference, and requires a fresh full capture', function()
    local db = assert(NS.Model.ensureDatabase(nil))
    local character = helpers.character()
    NS.Model.track(db, character)
    local roster = { { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true } }
    NS.Model.captureCharacter(db, character, roster, '171:50:100:1', helpers.profession(171))
    NS.Model.setIgnored(db, character, true)
    assert.is_nil(NS.Model.getCharacter(db, 'retail', 'us', 'Area 52', 'Crafter'))
    assert.is_true(db.settings.ignored[NS.Model.identityKey('retail', 'us', 'Area 52', 'Crafter')])
    NS.Model.setIgnored(db, character, false)
    assert.are.same({}, NS.Model.exportableCharacters(db))
    local expandedRoster = {
      { skillLineID = 171, currentSkill = 50, maxSkill = 100, requiresRecipeScan = true },
      { skillLineID = 164, currentSkill = 40, maxSkill = 75, requiresRecipeScan = true },
    }
    assert.is_false(NS.Model.captureCharacter(db, character, expandedRoster, '171:50:100:1|164:40:75:1', helpers.profession(171)))
  end)
end)
