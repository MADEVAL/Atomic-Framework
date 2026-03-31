-- KEYS[1]: meta.pid_map key
-- ARGV[1]: prefix

local pid_map_key = KEYS[1]
local prefix = ARGV[1]

local in_progress_jobs = {}

local pid_uuid_pairs = redis.call('HGETALL', pid_map_key)

for i = 1, #pid_uuid_pairs, 2 do
    local pid = pid_uuid_pairs[i]
    local uuid = pid_uuid_pairs[i + 1]
    
    local registry_key = prefix .. 'registry.' .. uuid
    local job_data = redis.call('HGETALL', registry_key)
    
    if #job_data > 0 then
        local job = {}
        for j = 1, #job_data, 2 do
            job[job_data[j]] = job_data[j + 1]
        end
        
        if job.payload then
            job.payload = cjson.decode(job.payload)
        end
        
        table.insert(in_progress_jobs, cjson.encode(job))
    else
        redis.call('HDEL', pid_map_key, pid)
    end
end

return in_progress_jobs
