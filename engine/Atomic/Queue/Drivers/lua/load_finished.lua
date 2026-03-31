-- KEYS[1]: {queue}.idx.completed OR {queue}.idx.failed key
-- ARGV[1]: prefix

local finished_idx_key = KEYS[1]
local prefix = ARGV[1]

local uuids = redis.call('ZREVRANGE', finished_idx_key, 0, -1)

local result = {}
for _, uuid in ipairs(uuids) do
    local registry_key = prefix .. 'registry.' .. uuid
    local job_data = redis.call('HGETALL', registry_key)
    
    if #job_data > 0 then
        local job = {}
        for i = 1, #job_data, 2 do
            job[job_data[i]] = job_data[i + 1]
        end
        
        table.insert(result, {uuid, cjson.encode(job)})
    end
end

return result
