-- Balance renewal.
--
-- KEYS[1] balance key
-- KEYS[2] epoch key
--
-- ARGV[1] credits
-- ARGV[2] ttl
-- ARGV[3] epoch uid
--
-- Overwrites the balance, so unused credit does not roll over, and writes a
-- new epoch id so any reservation taken under the old balance can no longer
-- refund into this one.
--
-- The epoch is a random identity, not a counter. It carries no TTL. A missing
-- key must not be recreatable as the same value, or an old reservation refunds
-- into a fresh balance.
--
-- returns the new balance.

local credits = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
local epoch = ARGV[3]

if credits == nil then error('missing or invalid required argument: credits') end
if ttl == nil or ttl < 1 then error('missing or invalid required argument: ttl') end
if epoch == nil or epoch == '' then error('missing required argument: epoch') end

redis.call('SET', KEYS[1], math.floor(credits), 'EX', math.floor(ttl))
redis.call('SET', KEYS[2], epoch)

return tonumber(redis.call('GET', KEYS[1]))
