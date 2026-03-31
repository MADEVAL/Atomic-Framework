-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: meta.pid_map key
-- ARGV[1]: uuid
-- ARGV[2]: pid
-- ARGV[3]: process_start_ticks

local registry_key = KEYS[1]
local pid_map_key = KEYS[2]
local uuid = ARGV[1]
local pid = ARGV[2]
local process_start_ticks = ARGV[3]

if not uuid or uuid == '' then
    return 0
end

if not pid or pid == '' or pid == '0' then
    return 0
end

if redis.call('EXISTS', registry_key) == 0 then
    return 0
end

local time_result = redis.call('TIME')
local current_time = tostring(time_result[1])

redis.call('HMSET', registry_key,
    'pid', tostring(pid),
    'process_start_ticks', tostring(process_start_ticks),
    'updated_at', current_time
)

redis.call('HSET', pid_map_key, tostring(pid), uuid)

return 1
