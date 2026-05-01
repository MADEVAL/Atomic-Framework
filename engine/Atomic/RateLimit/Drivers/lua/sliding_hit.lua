local key = KEYS[1]
local now = tonumber(ARGV[1])
local min = now - tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
redis.call('ZREMRANGEBYSCORE', key, 0, min)
if redis.call('ZCARD', key) >= limit then
  return 0
end
redis.call('ZADD', key, now, ARGV[4])
redis.call('EXPIRE', key, ARGV[2])
return 1
