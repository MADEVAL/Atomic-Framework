local raw = redis.call('GET', KEYS[2])
if not raw then return 0 end
local reserved = tonumber(raw)
if reserved == nil then error('invalid reservation key: ' .. KEYS[2]) end
if reserved > 0 then
  if redis.call('EXISTS', KEYS[1]) == 0 then error('missing quota key: ' .. KEYS[1]) end
  redis.call('INCRBY', KEYS[1], reserved)
end
redis.call('DEL', KEYS[2])
