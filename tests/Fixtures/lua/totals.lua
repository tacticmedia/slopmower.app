local M = {}

--- Sums the lines of an invoice.
---@param lines number[]
---@return number
function M.total(lines)
  local function add(a, b) return a + b end
  local sum = 0
  for _, line in ipairs(lines) do sum = add(sum, line) end
  return sum
end

function M:count()
  return #self.lines
end

--------------------------------------------------------------------------------
local function round(cents)
  return math.floor(cents + 0.5)
end

function format_total(cents)
  return string.format('%.2f', round(cents) / 100)
end

return M
