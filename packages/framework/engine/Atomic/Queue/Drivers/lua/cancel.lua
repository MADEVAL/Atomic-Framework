-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: meta.pid_map key
-- KEYS[3]: {queue}.idx.pending key
-- KEYS[4]: {queue}.idx.running key
-- KEYS[5]: {queue}.idx.cancel_requested key
-- KEYS[6]: {queue}.idx.cancelled key
-- ARGV[1]: uuid
-- ARGV[2]: redis prefix
-- ARGV[3]: timestamp
-- ARGV[4]: pending state
-- ARGV[5]: running state
-- ARGV[6]: cancel-requested state
-- ARGV[7]: completed state
-- ARGV[8]: failed state
-- ARGV[9]: cancelled state

local registry_key = KEYS[1]
local pid_map_key = KEYS[2]
local pending_idx_key = KEYS[3]
local running_idx_key = KEYS[4]
local cancel_requested_idx_key = KEYS[5]
local cancelled_idx_key = KEYS[6]
local uuid = ARGV[1]
local prefix = ARGV[2]
local timestamp = tonumber(ARGV[3])
local pending_state = ARGV[4]
local running_state = ARGV[5]
local cancel_requested_state = ARGV[6]
local completed_state = ARGV[7]
local failed_state = ARGV[8]
local cancelled_state = ARGV[9]

if timestamp == nil then
    error('missing or invalid required argument: timestamp')
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

if redis.call('EXISTS', registry_key) == 0 then
    error('missing job registry: ' .. registry_key)
end

local state = require_field('state')

if state == completed_state or state == failed_state or state == cancelled_state then
    return {'', ''}
end

local queue = require_field('queue')
local pid = redis.call('HGET', registry_key, 'pid')
if pid == false or pid == nil then
    error('missing required job field: pid')
end

if state == pending_state then
    local created_at = require_number_field('created_at')
    redis.call('HMSET', registry_key,
        'state', cancelled_state,
        'cancelled_at', tostring(timestamp),
        'updated_at', tostring(timestamp),
        'reason', '',
        'pid', '',
        'process_start_ticks', ''
    )

    redis.call('ZREM', pending_idx_key, uuid)
    redis.call('ZREM', running_idx_key, uuid)
    redis.call('ZREM', cancel_requested_idx_key, uuid)
    redis.call('ZADD', cancelled_idx_key, created_at * 1000000, uuid)

    if pid ~= '' then
        redis.call('HDEL', pid_map_key, pid)
    end

    return {cancelled_state, pid}
end

if state == running_state or state == cancel_requested_state then
    local running_score = redis.call('ZSCORE', running_idx_key, uuid)
    local cancel_requested_score = redis.call('ZSCORE', cancel_requested_idx_key, uuid)
    local score = tonumber(running_score or cancel_requested_score)
    if score == nil then
        error('missing running score for job: ' .. uuid)
    end
    redis.call('HMSET', registry_key,
        'state', cancel_requested_state,
        'cancel_requested_at', tostring(timestamp),
        'updated_at', tostring(timestamp)
    )
    redis.call('ZREM', running_idx_key, uuid)
    redis.call('ZADD', cancel_requested_idx_key, score, uuid)
    return {cancel_requested_state, pid}
end

return {'', ''}
