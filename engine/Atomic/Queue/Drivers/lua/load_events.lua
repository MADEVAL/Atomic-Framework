-- KEYS[1]: telemetry_jobs key
-- ARGV[1]: job_uuid
-- ARGV[2]: prefix

local telemetry_jobs_key = KEYS[1]
local job_uuid = ARGV[1]
local prefix = ARGV[2]

local results = {}

local batches_json = redis.call('HGET', telemetry_jobs_key, job_uuid)
if not batches_json then
    return results
end

local batches = cjson.decode(batches_json)

for batch_index = #batches, 1, -1 do
    local batch_uuid = batches[batch_index]
    local batch_key = prefix .. 'telemetry.batch.' .. batch_uuid
    local batch_events = redis.call('LRANGE', batch_key, 0, -1)
    local reversed_batch_events = {}

    for event_index = #batch_events, 1, -1 do
        table.insert(reversed_batch_events, batch_events[event_index])
    end

    table.insert(results, {batch_uuid, reversed_batch_events})
end

return results
