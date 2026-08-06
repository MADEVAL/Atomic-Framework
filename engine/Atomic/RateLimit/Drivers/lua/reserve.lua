local quota = KEYS[1]
local reservation = KEYS[2]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
if amount == nil then error('missing or invalid required argument: amount') end
if ttl == nil then error('missing or invalid required argument: ttl') end
if redis.call('EXISTS', reservation) == 1 then return 0 end
local balance = tonumber(redis.call('GET', quota))
if balance == nil then error('missing or invalid quota key: ' .. quota) end
if balance < amount then return 0 end
redis.call('DECRBY', quota, amount)
redis.call('SET', reservation, amount, 'EX', ttl)
return 1
