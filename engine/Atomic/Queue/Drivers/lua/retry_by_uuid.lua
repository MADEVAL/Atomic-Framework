-- KEYS[1]: registry.{uuid} key
-- ARGV[1]: uuid
-- ARGV[2]: prefix
-- ARGV[3]: current_time

local registry_key = KEYS[1]
local uuid = ARGV[1]
local prefix = ARGV[2]
local current_time = tonumber(ARGV[3])

local job_data = redis.call('HGETALL', registry_key)
if #job_data == 0 then
    return 0
end

local job = {}
for i = 1, #job_data, 2 do
    job[job_data[i]] = job_data[i + 1]
end

if job.state ~= 'failed' then
    return 0
end

local queue = job.queue
local failed_idx_key = prefix .. queue .. '.idx.failed'
local pending_idx_key = prefix .. queue .. '.idx.pending'
local sequence_key = prefix .. queue .. '.meta.sequence'

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

return 1
