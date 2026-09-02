-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.running key
-- ARGV[1]: uuid
-- ARGV[2]: expected worker PID
-- ARGV[3]: current timestamp
-- ARGV[4]: lease duration in seconds

local registry_key = KEYS[1]
local running_idx_key = KEYS[2]
local uuid = ARGV[1]
local expected_pid = tostring(ARGV[2] or '')
local now = tonumber(ARGV[3])
local duration = tonumber(ARGV[4])

if not uuid or uuid == '' then
    error('missing required argument: uuid')
end
if expected_pid == '' or expected_pid == '0' then
    error('missing required argument: expected worker PID')
end
if now == nil then
    error('missing or invalid required argument: current timestamp')
end
if duration == nil or duration <= 0 then
    error('missing or invalid required argument: lease duration')
end
if redis.call('EXISTS', registry_key) == 0 then
    return 0
end

local state = redis.call('HGET', registry_key, 'state')
if state ~= 'running' then
    return 0
end

local stored_pid = redis.call('HGET', registry_key, 'pid')
if stored_pid == false or stored_pid == nil or tostring(stored_pid) ~= expected_pid then
    return 0
end

local available_at = tonumber(redis.call('HGET', registry_key, 'available_at'))
if available_at == nil or available_at <= now then
    return 0
end

local renewed_until = now + duration
redis.call('HMSET', registry_key,
    'available_at', tostring(renewed_until),
    'updated_at', tostring(now)
)
redis.call('ZADD', running_idx_key, renewed_until * 1000, uuid)

return 1
