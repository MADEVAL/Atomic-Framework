-- KEYS[1]: {queue}.idx.failed key
-- KEYS[2]: {queue}.idx.pending key
-- KEYS[3]: {queue}.meta.sequence key
-- ARGV[1]: prefix (for registry keys)
-- ARGV[2]: current_time

local failed_idx_key = KEYS[1]
local pending_idx_key = KEYS[2]
local sequence_key = KEYS[3]
local prefix = ARGV[1]
local current_time = tonumber(ARGV[2])

local failed_uuids = redis.call('ZRANGE', failed_idx_key, 0, -1)
local retried_count = 0

for _, uuid in ipairs(failed_uuids) do
    local registry_key = prefix .. 'registry.' .. uuid
    local job_data = redis.call('HGETALL', registry_key)
    
    if #job_data > 0 then
        local job = {}
        for i = 1, #job_data, 2 do
            job[job_data[i]] = job_data[i + 1]
        end
        
        local sequence = redis.call('INCR', sequence_key)
        local score = (current_time * 1000000) + (tonumber(job.priority) * 1000) + (sequence % 1000)
        
        redis.call('HMSET', registry_key,
            'state', 'pending',
            'attempts', '0',
            'available_at', tostring(current_time),
            'updated_at', tostring(current_time),
            'pid', '',
            'process_start_ticks', '',
            'exception', ''
        )
        
        redis.call('PERSIST', registry_key)
        
        redis.call('ZREM', failed_idx_key, uuid)
        redis.call('ZADD', pending_idx_key, score, uuid)
        
        retried_count = retried_count + 1
    end
end

return retried_count
