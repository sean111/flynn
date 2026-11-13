local _, NS = ...
NS = NS or _G.FlynnTestNamespace

local Base64 = {}
local alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

function Base64.encode(input)
  local output = {}
  for index = 1, #input, 3 do
    local a, b, c = input:byte(index, index + 2)
    b, c = b or 0, c or 0
    local triple = a * 65536 + b * 256 + c
    local n1 = math.floor(triple / 262144) % 64 + 1
    local n2 = math.floor(triple / 4096) % 64 + 1
    local n3 = math.floor(triple / 64) % 64 + 1
    local n4 = triple % 64 + 1
    output[#output + 1] = alphabet:sub(n1, n1)
    output[#output + 1] = alphabet:sub(n2, n2)
    output[#output + 1] = index + 1 <= #input and alphabet:sub(n3, n3) or '='
    output[#output + 1] = index + 2 <= #input and alphabet:sub(n4, n4) or '='
  end
  return table.concat(output)
end

NS.Base64 = Base64
