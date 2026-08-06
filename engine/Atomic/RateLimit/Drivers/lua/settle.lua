local quota = KEYS[1]
local reservation = KEYS[2]
local actual = tonumber(ARGV[1])
if actual == nil then error('missing or invalid required argument: actual') end
local reserved = redis.call('GET', reservation)
if not reserved then
  local balance = tonumber(redis.call('GET', quota))
  return balance or 0
end
reserved = tonumber(reserved)
if reserved == nil then error('invalid reservation key: ' .. reservation) end
redis.call('DEL', reservation)
if reserved > actual then
  if redis.call('EXISTS', quota) == 0 then error('missing quota key: ' .. quota) end
  redis.call('INCRBY', quota, reserved - actual)
elseif actual > reserved then
  local current = tonumber(redis.call('GET', quota))
  if current == nil then error('missing or invalid quota key: ' .. quota) end
  redis.call('SET', quota, math.max(0, current - (actual - reserved)))
end
local balance = tonumber(redis.call('GET', quota))
if balance == nil then error('missing or invalid quota key: ' .. quota) end
return balance
