local generation = redis.call('GET', KEYS[1])
local current = 1

if generation and string.match(generation, '^%d+$') then
    current = tonumber(generation)
end

local next_generation = current + 1
redis.call('SET', KEYS[1], next_generation)
return next_generation
