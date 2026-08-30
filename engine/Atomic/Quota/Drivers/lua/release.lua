-- Quota release.
--
-- KEYS[1] balance key
-- KEYS[2] epoch key
-- KEYS[3] reservation key
--
-- ARGV[1] reservation_id
--
-- Removes every pacing member the reservation added and decrements the
-- matching used-counter when ZREM actually removed it, then refunds the cost.
-- Used-counter keys are read from the reservation hash, not derived from the
-- zset key, so a suffix change cannot split the counter.
-- The refund happens only when the epoch still matches, so a renewal that
-- landed between reserve and release is never inflated. It never throws on a
-- missing key.
--
-- returns the balance after the call.

local balance_key = KEYS[1]
local epoch_key = KEYS[2]
local reservation_key = KEYS[3]
local reservation_id = ARGV[1]

if reservation_id == nil or reservation_id == '' then error('missing required argument: reservation_id') end

if redis.call('EXISTS', reservation_key) == 0 then
  return tonumber(redis.call('GET', balance_key)) or 0
end

local record = redis.call('HMGET', reservation_key, 'cost', 'pacing_keys', 'used_keys', 'epoch', 'member')
local cost = math.floor(tonumber(record[1]) or 0)
local pacing_keys = record[2]
local used_keys = record[3]
local reserved_epoch = record[4]
if reserved_epoch == false or reserved_epoch == nil then
  reserved_epoch = ''
end
local stored_member = record[5]

redis.call('DEL', reservation_key)

local function lines(value)
  local out = {}
  if value ~= nil and value ~= false and value ~= '' then
    for line in string.gmatch(value, '[^\n]+') do
      out[#out + 1] = line
    end
  end
  return out
end

local pacing = lines(pacing_keys)
local useds = lines(used_keys)
if #pacing > 0 then
  local member = stored_member
  if member == false or member == nil or member == '' then
    member = reservation_id .. ':' .. string.format('%d', cost)
  end
  for i, key in ipairs(pacing) do
    local used_key = useds[i]
    local removed = redis.call('ZREM', key, member)
    -- Only decrement when the member was still present. A trim that already
    -- dropped it has already subtracted its cost from the running total.
    if used_key ~= nil then
      if removed == 1 then
        local used = tonumber(redis.call('DECRBY', used_key, cost))
        if used <= 0 then
          redis.call('DEL', used_key)
        end
      elseif redis.call('EXISTS', key) == 0 then
        redis.call('DEL', used_key)
      end
    end
  end
end

-- A renewal wrote a new epoch id, so the reservation belongs to a balance that
-- no longer exists. Drop the refund. Compare as strings — the epoch is a UID.
local current_epoch = redis.call('GET', epoch_key)
if current_epoch == false or current_epoch == nil then
  current_epoch = ''
end
if current_epoch ~= reserved_epoch then
  return tonumber(redis.call('GET', balance_key)) or 0
end

-- The period expired. Refunding would recreate the balance with no TTL.
if redis.call('EXISTS', balance_key) == 0 then
  return 0
end

if cost <= 0 then
  return tonumber(redis.call('GET', balance_key)) or 0
end

return tonumber(redis.call('INCRBY', balance_key, cost))
