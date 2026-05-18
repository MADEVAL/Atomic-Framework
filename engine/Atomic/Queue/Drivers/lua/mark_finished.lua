-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.running key
-- KEYS[3]: {queue}.idx.cancel_requested key
-- KEYS[4]: {queue}.idx.completed OR {queue}.idx.failed key
-- KEYS[5]: meta.pid_map key
-- ARGV[1]: uuid
-- ARGV[2]: is_failed (0 or 1)
-- ARGV[3]: timestamp
-- ARGV[4]: exception_json (empty string when not failed)
-- ARGV[5]: ttl (in seconds)

local registry_key = KEYS[1]
local running_idx_key = KEYS[2]
local cancel_requested_idx_key = KEYS[3]
local finished_idx_key = KEYS[4]
local pid_map_key = KEYS[5]
local uuid = ARGV[1]
local is_failed = tonumber(ARGV[2]) == 1
local timestamp = tonumber(ARGV[3])
local exception_json = ARGV[4]
local config_ttl = tonumber(ARGV[5])

if ARGV[2] == nil then
    error('missing required argument: is_failed')
end
if timestamp == nil then
    error('missing or invalid required argument: timestamp')
end
if exception_json == nil then
    error('missing required argument: exception_json')
end
if config_ttl == nil then
    error('missing or invalid required argument: ttl')
end

local CLEANUP_THRESHOLD = 1000
local CLEANUP_SCAN_LIMIT = 100

if redis.call('EXISTS', registry_key) == 0 then
    error('missing job registry: ' .. registry_key)
end

local function require_field(name)
    local value = redis.call('HGET', registry_key, name)
    if value == false or value == nil or value == '' then
        error('missing required job field: ' .. name)
    end
    return value
end

local function require_number_field(name)
    local value = tonumber(require_field(name))
    if value == nil then
        error('invalid numeric job field: ' .. name)
    end
    return value
end

local pid = redis.call('HGET', registry_key, 'pid')
if pid == false or pid == nil then
    error('missing required job field: pid')
end
local created_at = require_number_field('created_at')
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
redis.call('ZREM', cancel_requested_idx_key, uuid)
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
