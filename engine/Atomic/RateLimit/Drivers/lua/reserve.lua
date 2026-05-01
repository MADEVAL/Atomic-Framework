local quota = KEYS[1]
local reservation = KEYS[2]
local amount = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
if redis.call('EXISTS', reservation) == 1 then return 0 end
local balance = tonumber(redis.call('GET', quota) or '0')
if balance < amount then return 0 end
redis.call('DECRBY', quota, amount)
redis.call('SET', reservation, amount, 'EX', ttl)
return 1
