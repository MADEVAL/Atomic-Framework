-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: running index key ({queue}.idx.running)
-- KEYS[3]: pending index key ({queue}.idx.pending)
-- KEYS[4]: meta.pid_map key
-- KEYS[5]: {queue}.meta.sequence key
-- ARGV[1]: uuid
-- ARGV[2]: queue
-- ARGV[3]: pid
-- ARGV[4]: available_at (when job should be available)

local registry_key  = KEYS[1]
local running_index = KEYS[2]
local pending_index = KEYS[3]
local pid_map_key   = KEYS[4]
local sequence_key  = KEYS[5]
local uuid          = ARGV[1]
local queue         = ARGV[2]
local pid           = tostring(ARGV[3])
local available_at  = tonumber(ARGV[4])

if not uuid or uuid == '' then
    return 0
end

if redis.call('EXISTS', registry_key) == 0 then
    return 0
end

local removed = redis.call('ZREM', running_index, uuid)
if removed == 0 then
    return 0
end

local priority = tonumber(redis.call('HGET', registry_key, 'priority')) or 0
local sequence = redis.call('INCR', sequence_key)
local score = (available_at * 1000000) + (priority * 1000) + (sequence % 1000)

redis.call('HMSET', registry_key,
    'state', 'pending',
    'pid', '',
    'process_start_ticks', '',
    'available_at', available_at
)

redis.call('HDEL', pid_map_key, pid)

redis.call('ZADD', pending_index, score, uuid)

return 1
