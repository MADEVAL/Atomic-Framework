-- KEYS[1]: {queue}.idx.pending key
-- KEYS[2]: {queue}.idx.running key
-- ARGV[1]: prefix
-- ARGV[2]: offset (0-based)
-- ARGV[3]: limit

local pending_idx_key = KEYS[1]
local running_idx_key = KEYS[2]
local prefix = ARGV[1]
local offset = tonumber(ARGV[2]) or 0
local limit = tonumber(ARGV[3]) or 50

local running_total = redis.call('ZCARD', running_idx_key)
local pending_total = redis.call('ZCARD', pending_idx_key)
local total = running_total + pending_total

local results = {}

-- Determine which items to fetch based on offset across running+pending
if offset < running_total then
    local running_end = math.min(offset + limit - 1, running_total - 1)
    local running_uuids = redis.call('ZRANGE', running_idx_key, offset, running_end)
    for _, uuid in ipairs(running_uuids) do
        local registry_key = prefix .. 'registry.' .. uuid
        local job_data = redis.call('HGETALL', registry_key)

        if #job_data > 0 then
            local job = {}
            for i = 1, #job_data, 2 do
                job[job_data[i]] = job_data[i + 1]
            end

            job.status = 'in_progress'

            if job.payload then
                job.payload = cjson.decode(job.payload)
            end

            table.insert(results, {uuid, cjson.encode(job)})
        end
    end
end

local remaining = limit - #results
if remaining > 0 then
    local pending_offset = math.max(0, offset - running_total)
    local pending_uuids = redis.call('ZRANGE', pending_idx_key, pending_offset, pending_offset + remaining - 1)
    for _, uuid in ipairs(pending_uuids) do
        local registry_key = prefix .. 'registry.' .. uuid
        local job_data = redis.call('HGETALL', registry_key)

        if #job_data > 0 then
            local job = {}
            for i = 1, #job_data, 2 do
                job[job_data[i]] = job_data[i + 1]
            end

            job.status = 'pending'

            if job.payload then
                job.payload = cjson.decode(job.payload)
            end

            table.insert(results, {uuid, cjson.encode(job)})
        end
    end
end

return {total, results}
