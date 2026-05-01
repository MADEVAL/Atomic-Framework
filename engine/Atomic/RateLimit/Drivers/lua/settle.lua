local quota = KEYS[1]
local reservation = KEYS[2]
local actual = tonumber(ARGV[1])
local reserved = redis.call('GET', reservation)
if not reserved then return tonumber(redis.call('GET', quota) or '0') end
reserved = tonumber(reserved)
redis.call('DEL', reservation)
if reserved > actual then
  redis.call('INCRBY', quota, reserved - actual)
elseif actual > reserved then
  local current = tonumber(redis.call('GET', quota) or '0')
  redis.call('SET', quota, math.max(0, current - (actual - reserved)))
end
return tonumber(redis.call('GET', quota) or '0')
