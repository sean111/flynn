local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local Json = {}
local arrayKeys = {
  professions = true, recipes = true, outputs = true, reagents = true,
  reagentSlots = true, options = true,
}

local function quote(value)
  return '"' .. value:gsub('[%z\1-\31\\"]', function(char)
    local escapes = { ['"'] = '\\"', ['\\'] = '\\\\', ['\b'] = '\\b', ['\f'] = '\\f', ['\n'] = '\\n', ['\r'] = '\\r', ['\t'] = '\\t' }
    return escapes[char] or string.format('\\u%04x', string.byte(char))
  end) .. '"'
end

local function isArray(value, key)
  if arrayKeys[key] then
    return true
  end
  local count, maximum = 0, 0
  for index in pairs(value) do
    if type(index) ~= 'number' or index < 1 or index % 1 ~= 0 then return false end
    count = count + 1
    if index > maximum then maximum = index end
  end
  return count > 0 and count == maximum
end

local function encode(value, key, depth)
  if depth > 32 then error('JSON nesting exceeds 32 levels') end
  local kind = type(value)
  if kind == 'nil' then return 'null' end
  if kind == 'boolean' then return value and 'true' or 'false' end
  if kind == 'number' then
    if value ~= value or value == math.huge or value == -math.huge then error('non-finite JSON number') end
    return string.format('%.0f', value)
  end
  if kind == 'string' then return quote(value) end
  if kind ~= 'table' then error('unsupported JSON value: ' .. kind) end

  local values = {}
  if isArray(value, key) then
    for index = 1, #value do values[#values + 1] = encode(value[index], nil, depth + 1) end
    return '[' .. table.concat(values, ',') .. ']'
  end
  local keys = {}
  for field in pairs(value) do
    if type(field) ~= 'string' then error('JSON object keys must be strings') end
    keys[#keys + 1] = field
  end
  table.sort(keys)
  for _, field in ipairs(keys) do
    values[#values + 1] = quote(field) .. ':' .. encode(value[field], field, depth + 1)
  end
  return '{' .. table.concat(values, ',') .. '}'
end

function Json.encode(value)
  local ok, result = pcall(encode, value, nil, 0)
  if not ok then return nil, result end
  return result
end

NS.Json = Json
