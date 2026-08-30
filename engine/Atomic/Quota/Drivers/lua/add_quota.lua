-- Balance top-up.
--
-- KEYS[1] balance key
--
-- ARGV[1] credits
-- ARGV[2] ttl
--
-- Adds credit to an existing balance without moving the end of the period. A
-- top-up is not a renewal, so it must never extend the TTL — otherwise a
-- purchase late in a period silently buys another whole period. The TTL is
-- applied only when the key is absent, or when it carries no expiry at all.
--
-- returns the new balance.

local balance_key = KEYS[1]
local credits = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])

if credits == nil then error('missing or invalid required argument: credits') end
if ttl == nil or ttl < 1 then error('missing or invalid required argument: ttl') end

local existed = redis.call('EXISTS', balance_key) == 1
local balance = tonumber(redis.call('INCRBY', balance_key, math.floor(credits)))

if not existed or redis.call('TTL', balance_key) < 0 then
  redis.call('EXPIRE', balance_key, math.floor(ttl))
end

return balance
