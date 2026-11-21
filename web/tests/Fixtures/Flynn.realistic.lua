-- Privacy-safe sample SavedVariables document.
FlynnDB = {
  ["schemaVersion"] = 1,
  ["characters"] = {
    ["retail"] = {
      ["us"] = {
        ["Example Realm PvE"] = {
          ["Examplewarlock"] = {
            ["schemaVersion"] = 1,
            ["gameFlavor"] = "retail",
            ["region"] = "us",
            ["realm"] = "Example Realm PvE",
            ["characterName"] = "Examplewarlock",
            ["guid"] = "Player-0000-00000001",
            ["faction"] = "alliance",
            ["class"] = "WARLOCK",
            ["capturedAt"] = 1794528000,
            ["professions"] = {
              [1] = {
                ["skillLineID"] = 185,
                ["currentSkill"] = 75,
                ["maxSkill"] = 100,
                ["recipes"] = {},
              },
              [2] = {
                ["skillLineID"] = 197,
                ["currentSkill"] = 100,
                ["maxSkill"] = 100,
                ["recipes"] = {
                  [1] = {
                    ["spellID"] = 376556,
                    ["outputs"] = { [1] = { ["itemID"] = 193922, ["quantity"] = 1 } },
                    ["reagents"] = { [1] = { ["itemID"] = 193208, ["quantity"] = 5 } },
                    ["reagentSlots"] = {
                      [1] = { ["slotIndex"] = 0, ["kind"] = "required", ["quantity"] = 5, ["options"] = { [1] = { ["itemID"] = 193208 } } },
                      [2] = { ["slotIndex"] = 1, ["kind"] = "optional", ["quantity"] = 1, ["options"] = { [1] = { ["itemID"] = 194123 }, [2] = { ["itemID"] = 194124 } } },
                    },
                  },
                },
              },
              [3] = {
                ["skillLineID"] = 333,
                ["currentSkill"] = 80,
                ["maxSkill"] = 100,
                ["recipes"] = {
                  [1] = {
                    ["spellID"] = 382994,
                    ["outputs"] = {},
                    ["reagents"] = { [1] = { ["itemID"] = 190316, ["quantity"] = 2 } },
                    ["reagentSlots"] = {
                      [1] = { ["slotIndex"] = 0, ["kind"] = "required", ["quantity"] = 2, ["options"] = { [1] = { ["itemID"] = 190316 } } },
                    },
                  },
                },
              },
            },
          },
        },
      },
    },
  },
  ["settings"] = {
    ["tracked"] = { ["retail\031us\031Example Realm PvE\031Examplewarlock"] = true },
    ["ignored"] = {},
    ["capturedProfessions"] = { ["retail\031us\031Example Realm PvE\031Examplewarlock"] = { ["complete"] = true } },
  },
}
