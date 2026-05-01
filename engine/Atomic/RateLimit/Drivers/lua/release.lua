local reserved = tonumber(redis.call('GET', KEYS[2]) or '0')
if reserved > 0 then redis.call('INCRBY', KEYS[1], reserved) end
redis.call('DEL', KEYS[2])
