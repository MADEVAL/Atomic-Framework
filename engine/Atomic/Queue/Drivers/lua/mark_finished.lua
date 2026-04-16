-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.running key
-- KEYS[3]: {queue}.idx.completed OR {queue}.idx.failed key
-- KEYS[4]: meta.pid_map key
-- ARGV[1]: uuid
-- ARGV[2]: is_failed (0 or 1)
-- ARGV[3]: timestamp
-- ARGV[4]: exception_json (optional, for failed jobs)
-- ARGV[5]: ttl (in seconds)

local registry_key = KEYS[1]
local running_idx_key = KEYS[2]
local finished_idx_key = KEYS[3]
local pid_map_key = KEYS[4]
local uuid = ARGV[1]
local is_failed = tonumber(ARGV[2]) == 1
local timestamp = tonumber(ARGV[3])
local exception_json = ARGV[4] or ''
local config_ttl = tonumber(ARGV[5]) or 0

local CLEANUP_THRESHOLD = 1000
local CLEANUP_SCAN_LIMIT = 100

if redis.call('EXISTS', registry_key) == 0 then
    return 0
end

local pid = redis.call('HGET', registry_key, 'pid')
local created_at = tonumber(redis.call('HGET', registry_key, 'created_at')) or timestamp
local registry_suffix = 'registry.' .. uuid
local prefix = string.sub(registry_key, 1, #registry_key - #registry_suffix)

local new_state = is_failed and 'failed' or 'completed'
local updates = {
    'state', new_state,
    'updated_at', tostring(timestamp),
    'pid', '',
    'process_start_ticks', ''
}

if is_failed and exception_json ~= '' then
    table.insert(updates, 'exception')
    table.insert(updates, exception_json)
end

redis.call('HMSET', registry_key, unpack(updates))

if config_ttl > 0 then
    redis.call('EXPIRE', registry_key, config_ttl)
end

redis.call('ZREM', running_idx_key, uuid)
redis.call('ZADD', finished_idx_key, created_at * 1000000, uuid)

if pid and pid ~= '' then
    redis.call('HDEL', pid_map_key, pid)
end

local finished_count = redis.call('ZCARD', finished_idx_key)

if finished_count > CLEANUP_THRESHOLD then
    local scan_limit = math.min(finished_count, CLEANUP_SCAN_LIMIT)
    local oldest_uuids = redis.call('ZRANGE', finished_idx_key, 0, scan_limit - 1)

    for _, finished_uuid in ipairs(oldest_uuids) do
        local finished_registry_key = prefix .. 'registry.' .. finished_uuid
        if redis.call('EXISTS', finished_registry_key) == 0 then
            redis.call('ZREM', finished_idx_key, finished_uuid)
        end
    end
end

return 1
