-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: telemetry.jobs key
-- KEYS[3]: meta.pid_map key
-- ARGV[1]: uuid
-- ARGV[2]: prefix

local registry_key = KEYS[1]
local telemetry_jobs_key = KEYS[2]
local pid_map_key = KEYS[3]
local uuid = ARGV[1]
local prefix = ARGV[2]

local job_data = redis.call('HGETALL', registry_key)
if #job_data == 0 then
    error('missing job registry: ' .. registry_key)
end

local job = {}
for i = 1, #job_data, 2 do
    job[job_data[i]] = job_data[i + 1]
end

local queue = job.queue
local state = job.state

if queue == nil or queue == '' then
    error('missing required job field: queue')
end
if state == nil or state == '' then
    error('missing required job field: state')
end

if state == 'running' or state == 'cancel_requested' then
    return 0
end

local state_suffixes = {
    pending = 'pending',
    failed = 'failed',
    completed = 'completed',
    cancelled = 'cancelled',
    cancel_requested = 'cancel_requested'
}

local state_suffix = state_suffixes[state]

if not state_suffix then
    error('unknown job state: ' .. state)
end

redis.call('DEL', registry_key)
redis.call('ZREM', prefix .. queue .. '.idx.' .. state_suffix, uuid)

local telemetry_batches_json = redis.call('HGET', telemetry_jobs_key, uuid)
if telemetry_batches_json then
    local batches = cjson.decode(telemetry_batches_json)
    for _, batch_uuid in ipairs(batches) do
        redis.call('DEL', prefix .. 'telemetry.batch.' .. batch_uuid)
    end
    redis.call('HDEL', telemetry_jobs_key, uuid)
end

if job.pid and job.pid ~= '' then
    redis.call('HDEL', pid_map_key, job.pid)
end

return 1
