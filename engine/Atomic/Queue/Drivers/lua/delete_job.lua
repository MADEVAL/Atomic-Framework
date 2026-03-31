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
    return 0
end

local job = {}
for i = 1, #job_data, 2 do
    job[job_data[i]] = job_data[i + 1]
end

local queue = job.queue
local state = job.state

if state == 'running' then
    return 0
end

redis.call('DEL', registry_key)

if state == 'pending' then
    redis.call('ZREM', prefix .. queue .. '.idx.pending', uuid)
elseif state == 'failed' then
    redis.call('ZREM', prefix .. queue .. '.idx.failed', uuid)
elseif state == 'completed' then
    redis.call('ZREM', prefix .. queue .. '.idx.completed', uuid)
end

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
