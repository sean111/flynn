std = "lua51"
max_line_length = 240
exclude_files = { "addon/Flynn/Libs/**", "addon/spec/**", "addon/scripts/**" }
globals = {
  "_G", "CreateFrame", "UIParent", "C_Timer", "LibStub", "FlynnDB",
  "strmatch",
  "SlashCmdList", "InCombatLockdown", "GetProfessions", "GetProfessionInfo",
  "GetCurrentRegion", "GetRealmName", "UnitName", "UnitClass", "UnitGUID",
  "UnitFactionGroup", "UnitIsPVP", "FACTION_ALLIANCE", "FACTION_HORDE",
  "WOW_PROJECT_ID", "WOW_PROJECT_MAINLINE", "WOW_PROJECT_CLASSIC",
  "WOW_PROJECT_CLASSIC_ERA", "WOW_PROJECT_BURNING_CRUSADE_CLASSIC",
  "GetTradeSkillLine", "GetNumTradeSkills", "GetTradeSkillInfo",
  "GetTradeSkillItemLink", "GetTradeSkillRecipeLink", "GetTradeSkillNumMade",
  "GetTradeSkillNumReagents", "GetTradeSkillReagentInfo", "GetTradeSkillReagentItemLink",
  "GetCraftSkillLine", "GetNumCrafts", "GetCraftInfo", "GetCraftItemLink",
  "GetCraftRecipeLink", "GetCraftNumMade", "GetCraftNumReagents",
  "GetCraftReagentInfo", "GetCraftReagentItemLink", "time", "print",
  "os", "math", "string", "table", "pairs", "ipairs", "type", "tonumber",
  "tostring", "select", "error", "pcall", "coroutine", "next", "unpack",
}
ignore = { "213", "311", "312", "421", "422", "631", "632" }
