-- Quota reservation.
--
-- KEYS[1]    balance key
-- KEYS[2]    epoch key
-- KEYS[3]    reservation key
-- KEYS[4..n] pacing pairs, in order: zset key, used-counter key
--
-- ARGV[1]    now, unix seconds
-- ARGV[2]    cost
-- ARGV[3]    reservation_ttl
-- ARGV[4]    reservation_id
-- ARGV[5]    nonce, unique per successful reserve
-- ARGV[6..]  one triple per pacing window: limit, window, zset_key_index
--            the used counter for that window is KEYS[index + 1]
--
-- returns { allowed, reason, balance, retry_after, limited_by }
--         retry_after is -1 when there is none.

local balance_key = KEYS[1]
local epoch_key = KEYS[2]
local reservation_key = KEYS[3]

local now = tonumber(ARGV[1])
local cost = tonumber(ARGV[2])
local reservation_ttl = tonumber(ARGV[3])
local reservation_id = ARGV[4]
local nonce = ARGV[5]

if now == nil then error('missing or invalid required argument: now') end
if cost == nil then error('missing or invalid required argument: cost') end
if reservation_ttl == nil then error('missing or invalid required argument: reservation_ttl') end
if reservation_id == nil or reservation_id == '' then error('missing required argument: reservation_id') end
if nonce == nil or nonce == '' then error('missing required argument: nonce') end

cost = math.floor(cost)
now = math.floor(now)

if cost < 0 then error('cost must not be negative') end

local windows = {}
local seen = {}
local i = 6
while ARGV[i] ~= nil do
  local limit = tonumber(ARGV[i])
  local window = tonumber(ARGV[i + 1])
  local index = tonumber(ARGV[i + 2])
  if limit == nil or window == nil or index == nil then
    error('malformed pacing triple at ARGV[' .. i .. ']')
  end
  if KEYS[index] == nil or KEYS[index + 1] == nil then
    error('pacing triple at ARGV[' .. i .. '] points at a missing KEYS[' .. index .. '] pair')
  end
  if seen[KEYS[index]] then
    error('duplicate pacing key at ARGV[' .. i .. ']')
  end
  seen[KEYS[index]] = true
  windows[#windows + 1] = {
    key = KEYS[index],
    used_key = KEYS[index + 1],
    limit = limit,
    window = math.floor(window),
  }
  i = i + 3
end

-- 1. Duplicate reservation id. Balance is unread until this passes.
if redis.call('EXISTS', reservation_key) == 1 then
  local balance = tonumber(redis.call('GET', balance_key)) or 0
  return { 0, 'duplicate_reservation', balance, -1, '' }
end

-- 2. Balance.
local balance = tonumber(redis.call('GET', balance_key)) or 0
if balance < cost then
  local ttl = tonumber(redis.call('TTL', balance_key)) or -1
  if ttl < 0 then ttl = -1 end
  return { 0, 'insufficient_balance', balance, ttl, '' }
end

local function member_cost(member)
  return tonumber(string.match(member, ':(%d+)$')) or 0
end

local function sum_members(key)
  local total = 0
  local members = redis.call('ZRANGE', key, 0, -1)
  for _, member in ipairs(members) do
    total = total + member_cost(member)
  end
  return total
end

local function expire_used(w)
  local ttl = tonumber(redis.call('TTL', w.key)) or -1
  if ttl < 1 then ttl = w.window end
  redis.call('EXPIRE', w.used_key, ttl)
end

-- Key lifetime follows the same clock as scores. EXPIRE(window) is Redis
-- TIME; trim uses ARGV now. If PHP is ahead, the zset must outlive `window`
-- real seconds so it cannot vanish while members are still inside the
-- sliding window. Never shorter than `window`, so an injected past now
-- (T / T+59 / T+60) still keeps the key for the duration of the test.
local function pacing_ttl(w, redis_now)
  local ttl = math.floor(now + w.window - redis_now)
  if ttl < w.window then
    ttl = w.window
  end
  if ttl > 2147483647 then
    ttl = 2147483647
  end
  return ttl
end

-- Running total lives in used_key. Trim subtracts only expired members
-- (O(expired)); the allow path never reads the zset. A missing counter
-- with a non-empty zset rebuilds from a full scan — rare, keeps drift
-- from becoming a hard failure.
local function window_used(w)
  local cutoff = now - w.window
  local expired = redis.call('ZRANGEBYSCORE', w.key, '-inf', cutoff)
  if #expired > 0 then
    redis.call('ZREMRANGEBYSCORE', w.key, '-inf', cutoff)
  end

  if redis.call('EXISTS', w.key) == 0 then
    redis.call('DEL', w.used_key)
    return 0
  end

  local used = tonumber(redis.call('GET', w.used_key))
  if used == nil or used < 0 then
    used = sum_members(w.key)
    redis.call('SET', w.used_key, used)
    expire_used(w)
    return used
  end

  local expired_sum = 0
  for _, member in ipairs(expired) do
    expired_sum = expired_sum + member_cost(member)
  end
  if expired_sum > 0 then
    used = tonumber(redis.call('DECRBY', w.used_key, expired_sum))
    if used < 0 then
      used = sum_members(w.key)
      redis.call('SET', w.used_key, used)
      expire_used(w)
      return used
    end
  end
  return used
end

-- 3. Every pacing window. Trim first, then read the running total.
for _, w in ipairs(windows) do
  local used = window_used(w)
  if used + cost > w.limit then
    local retry_after = w.window
    local oldest = redis.call('ZRANGE', w.key, 0, 0, 'WITHSCORES')
    if oldest[2] ~= nil then
      retry_after = math.ceil(tonumber(oldest[2]) + w.window - now)
    end
    if retry_after < 1 then retry_after = 1 end
    return { 0, 'pacing', balance, retry_after, w.key }
  end
end

-- Checks passed. Trim and counter heal may have written; the reservation,
-- the balance, and the new pacing members have not.
-- Member is id:nonce:cost so a reused reservation id after settle is a new
-- spend in the window. Cost stays last so member_cost() still parses it.
-- Every successful charge is a pacing event. If any window refuses the
-- member, this reserve did not happen: roll back inserts already done in
-- this call and do not DECRBY / HSET.
local member = reservation_id .. ':' .. nonce .. ':' .. string.format('%d', cost)
local pacing_keys = {}
local used_keys = {}
local inserted = {}
for _, w in ipairs(windows) do
  local added = redis.call('ZADD', w.key, 'NX', now, member)
  if added ~= 1 then
    for _, done in ipairs(inserted) do
      redis.call('ZREM', done.key, member)
    end
    error('duplicate pacing member')
  end
  inserted[#inserted + 1] = w
end
local redis_now = tonumber(redis.call('TIME')[1])
for _, w in ipairs(windows) do
  redis.call('INCRBY', w.used_key, cost)
  local ttl = pacing_ttl(w, redis_now)
  redis.call('EXPIRE', w.key, ttl)
  redis.call('EXPIRE', w.used_key, ttl)
  pacing_keys[#pacing_keys + 1] = w.key
  used_keys[#used_keys + 1] = w.used_key
end

local new_balance = tonumber(redis.call('DECRBY', balance_key, cost))
local epoch = redis.call('GET', epoch_key)
if epoch == false or epoch == nil then
  epoch = ''
end

redis.call(
  'HSET',
  reservation_key,
  'cost', cost,
  'pacing_keys', table.concat(pacing_keys, '\n'),
  'used_keys', table.concat(used_keys, '\n'),
  'epoch', epoch,
  'member', member
)
redis.call('EXPIRE', reservation_key, reservation_ttl)

return { 1, 'ok', new_balance, -1, '' }
