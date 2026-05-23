local generation = redis.call('GET', KEYS[1])
if generation and string.match(generation, '^%d+$') then
    return tonumber(generation)
end

redis.call('SET', KEYS[1], 1)
return 1
