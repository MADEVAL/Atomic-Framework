-- KEYS[1]: telemetry.jobs key (global hash)
-- KEYS[2]: telemetry.batch.{batch_uuid} key
-- ARGV[1]: job_uuid
-- ARGV[2]: batch_uuid
-- ARGV[3]: event_data (JSON serialized)
-- ARGV[4]: ttl

local telemetry_jobs_key = KEYS[1]
local telemetry_batch_key = KEYS[2]
local job_uuid = ARGV[1]
local batch_uuid = ARGV[2]
local event_data = ARGV[3]
local ttl = tonumber(ARGV[4])

local batches_json = redis.call('HGET', telemetry_jobs_key, job_uuid)
local batches = {}
if batches_json then
    batches = cjson.decode(batches_json)
end

local batch_exists = false
for _, b in ipairs(batches) do
    if b == batch_uuid then
        batch_exists = true
        break
    end
end
if not batch_exists then
    table.insert(batches, batch_uuid)
    redis.call('HSET', telemetry_jobs_key, job_uuid, cjson.encode(batches))
end

redis.call('RPUSH', telemetry_batch_key, event_data)

if ttl > 0 then
    redis.call('EXPIRE', telemetry_batch_key, ttl)
end

return 1
