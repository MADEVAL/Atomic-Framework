-- KEYS[1]: {queue}.idx.pending key
-- KEYS[2]: {queue}.idx.running key
-- ARGV[1]: prefix (for registry keys)
-- ARGV[2]: now (timestamp)
-- ARGV[3]: limit

local pending_idx_key = KEYS[1]
local running_idx_key = KEYS[2]
local prefix = ARGV[1]
local now = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])

if now == nil then
    error('missing or invalid required argument: now')
end
if limit == nil then
    error('missing or invalid required argument: limit')
end

local results = {}

local max_score = (now * 1000000) + 999999
local uuids = redis.call('ZRANGEBYSCORE', pending_idx_key, '-inf', max_score, 'LIMIT', 0, limit)

for _, uuid in ipairs(uuids) do
    local registry_key = prefix .. 'registry.' .. uuid
    local job_data = redis.call('HGETALL', registry_key)
    
    if #job_data == 0 then
        error('missing job registry: ' .. registry_key)
    end

    local job = {}
    for i = 1, #job_data, 2 do
        job[job_data[i]] = job_data[i + 1]
    end
    
    if job.state == 'pending' then
        local attempts = tonumber(job.attempts)
        local timeout = tonumber(job.timeout)
        if attempts == nil then
            error('missing or invalid required job field: attempts')
        end
        if timeout == nil then
            error('missing or invalid required job field: timeout')
        end
        if job.payload == nil or job.payload == '' then
            error('missing required job field: payload')
        end

        job.attempts = attempts + 1
        job.state = 'running'
        job.available_at = now + timeout
        job.updated_at = now
        
        local running_score = job.available_at * 1000
        
        redis.call('HMSET', registry_key,
            'state', 'running',
            'attempts', tostring(job.attempts),
            'available_at', tostring(job.available_at),
            'updated_at', tostring(job.updated_at)
        )
        
        redis.call('ZREM', pending_idx_key, uuid)
        redis.call('ZADD', running_idx_key, running_score, uuid)
        
        job.payload = cjson.decode(job.payload)
        table.insert(results, cjson.encode(job))
    elseif job.state == nil or job.state == '' then
        error('missing required job field: state')
    end
end

return results
