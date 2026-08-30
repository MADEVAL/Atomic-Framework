-- Quota settlement.
--
-- KEYS[1] balance key
-- KEYS[2] reservation key
--
-- The charge taken at reserve stands. Settle only drops the reservation record
-- and reports the balance. It never throws on a missing key.
--
-- returns the balance after the call.

redis.call('DEL', KEYS[2])

return tonumber(redis.call('GET', KEYS[1])) or 0
