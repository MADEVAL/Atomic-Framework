-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.running key
-- KEYS[3]: {queue}.idx.cancel_requested key
-- ARGV[1]: uuid
-- ARGV[2]: timestamp
-- ARGV[3]: running state
-- ARGV[4]: cancel-requested state

local registry_key = KEYS[1]
local running_idx_key = KEYS[2]
local cancel_requested_idx_key = KEYS[3]
local uuid = ARGV[1]
local timestamp = tonumber(ARGV[2])
local running_state = ARGV[3]
local cancel_requested_state = ARGV[4]

if timestamp == nil then
    error('missing or invalid required argument: timestamp')
end

if redis.call('EXISTS', registry_key) == 0 then
    error('missing job registry: ' .. registry_key)
end

local state = redis.call('HGET', registry_key, 'state')
if state == false or state == nil or state == '' then
    error('missing required job field: state')
end
if state ~= running_state and state ~= cancel_requested_state then
    return {0, ''}
end

redis.call('HMSET', registry_key,
    'state', cancel_requested_state,
    'cancel_requested_at', tostring(timestamp),
    'updated_at', tostring(timestamp)
)
local running_score = redis.call('ZSCORE', running_idx_key, uuid)
local cancel_requested_score = redis.call('ZSCORE', cancel_requested_idx_key, uuid)
local score = tonumber(running_score or cancel_requested_score)
if score == nil then
    error('missing running score for job: ' .. uuid)
end
redis.call('ZREM', running_idx_key, uuid)
redis.call('ZADD', cancel_requested_idx_key, score, uuid)

local pid = redis.call('HGET', registry_key, 'pid')
if pid == false or pid == nil then
    error('missing required job field: pid')
end
return {1, pid}
