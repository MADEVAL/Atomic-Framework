-- KEYS[1]: registry.{uuid} key
-- ARGV[1]: uuid
-- ARGV[2]: prefix
-- ARGV[3]: current_time

local registry_key = KEYS[1]
local uuid = ARGV[1]
local prefix = ARGV[2]
local current_time = tonumber(ARGV[3])

if current_time == nil then
    error('missing or invalid required argument: current_time')
end

local job_data = redis.call('HGETALL', registry_key)
if #job_data == 0 then
    error('missing job registry: ' .. registry_key)
end

local job = {}
for i = 1, #job_data, 2 do
    job[job_data[i]] = job_data[i + 1]
end

if job.state ~= 'failed' then
    return 0
end

local queue = job.queue
if queue == nil or queue == '' then
    error('missing required job field: queue')
end
local failed_idx_key = prefix .. queue .. '.idx.failed'
local pending_idx_key = prefix .. queue .. '.idx.pending'
local sequence_key = prefix .. queue .. '.meta.sequence'

local sequence = redis.call('INCR', sequence_key)
local priority = tonumber(job.priority)
if priority == nil then
    error('missing or invalid required job field: priority')
end
local score = (current_time * 1000000) + (priority * 1000) + (sequence % 1000)

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

return 1
