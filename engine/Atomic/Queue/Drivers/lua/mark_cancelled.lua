-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.pending key
-- KEYS[3]: {queue}.idx.running key
-- KEYS[4]: {queue}.idx.failed key
-- KEYS[5]: {queue}.idx.completed key
-- KEYS[6]: {queue}.idx.cancelled key
-- KEYS[7]: {queue}.idx.cancel_requested key
-- KEYS[8]: meta.pid_map key
-- ARGV[1]: uuid
-- ARGV[2]: timestamp
-- ARGV[3]: reason
-- ARGV[4]: ttl
-- ARGV[5]: completed state
-- ARGV[6]: failed state
-- ARGV[7]: cancelled state

local registry_key = KEYS[1]
local pending_idx_key = KEYS[2]
local running_idx_key = KEYS[3]
local failed_idx_key = KEYS[4]
local completed_idx_key = KEYS[5]
local cancelled_idx_key = KEYS[6]
local cancel_requested_idx_key = KEYS[7]
local pid_map_key = KEYS[8]
local uuid = ARGV[1]
local timestamp = tonumber(ARGV[2])
local reason = ARGV[3]
local ttl = tonumber(ARGV[4])
local completed_state = ARGV[5]
local failed_state = ARGV[6]
local cancelled_state = ARGV[7]

if timestamp == nil then
    error('missing or invalid required argument: timestamp')
end

if redis.call('EXISTS', registry_key) == 0 then
    error('missing job registry: ' .. registry_key)
end

if reason == nil then
    error('missing required argument: reason')
end
if ttl == nil then
    error('missing or invalid required argument: ttl')
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

local state = require_field('state')
if state == completed_state or state == failed_state or state == cancelled_state then
    return 0
end

local pid = redis.call('HGET', registry_key, 'pid')
if pid == false or pid == nil then
    error('missing required job field: pid')
end
local created_at = require_number_field('created_at')

redis.call('HMSET', registry_key,
    'state', cancelled_state,
    'cancelled_at', tostring(timestamp),
    'updated_at', tostring(timestamp),
    'reason', reason,
    'pid', '',
    'process_start_ticks', ''
)
redis.call('HDEL', registry_key, 'cancel_requested_at')

redis.call('ZREM', pending_idx_key, uuid)
redis.call('ZREM', running_idx_key, uuid)
redis.call('ZREM', failed_idx_key, uuid)
redis.call('ZREM', completed_idx_key, uuid)
redis.call('ZREM', cancel_requested_idx_key, uuid)
redis.call('ZADD', cancelled_idx_key, created_at * 1000000, uuid)

if pid and pid ~= '' then
    redis.call('HDEL', pid_map_key, pid)
end

if ttl > 0 then
    redis.call('EXPIRE', registry_key, ttl)
end

return 1
