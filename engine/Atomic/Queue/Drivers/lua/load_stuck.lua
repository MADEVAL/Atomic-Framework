-- KEYS[1]: {queue}.idx.running key
-- ARGV[1]: prefix
-- ARGV[2]: now (timestamp)
-- ARGV[3]: exclude_pids_json

local running_idx_key = KEYS[1]
local prefix = ARGV[1]
local now = tonumber(ARGV[2])
local exclude_json = ARGV[3]

local exclude_set = {}
if exclude_json and exclude_json ~= '' then
    local exclude = cjson.decode(exclude_json)
    for _, pid in ipairs(exclude) do
        exclude_set[tostring(pid)] = true
    end
end

local stuck_jobs = {}

local max_score = now * 1000
local uuids = redis.call('ZRANGEBYSCORE', running_idx_key, '-inf', max_score)

for _, uuid in ipairs(uuids) do
    local registry_key = prefix .. 'registry.' .. uuid
    local job_data = redis.call('HGETALL', registry_key)
    
    if #job_data > 0 then
        local job = {}
        for i = 1, #job_data, 2 do
            job[job_data[i]] = job_data[i + 1]
        end
        
        local include = true
        if job.pid and job.pid ~= '' then
            if exclude_set[job.pid] then
                include = false
            end
        end
        
        if include then
            if job.payload then
                job.payload = cjson.decode(job.payload)
            end
            table.insert(stuck_jobs, cjson.encode(job))
        end
    end
end

return stuck_jobs
