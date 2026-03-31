-- KEYS[1]: registry.{uuid} key
-- KEYS[2]: {queue}.idx.pending key
-- KEYS[3]: {queue}.meta.sequence key
-- KEYS[4]: meta.queues key (queue registry set)
-- ARGV[1]: uuid
-- ARGV[2]: available_at (unix timestamp)
-- ARGV[3]: priority
-- ARGV[4]: queue name
-- ARGV[5]: max_attempts
-- ARGV[6]: attempts
-- ARGV[7]: timeout
-- ARGV[8]: retry_delay
-- ARGV[9]: created_at
-- ARGV[10]: handler
-- ARGV[11]: payload JSON (pre-encoded, no decode needed)

local registry_key  = KEYS[1]
local pending_key   = KEYS[2]
local sequence_key  = KEYS[3]
local queues_key    = KEYS[4]

local uuid         = ARGV[1]
local available_at = tonumber(ARGV[2])
local priority     = tonumber(ARGV[3])
local queue        = ARGV[4]

local seq   = redis.call('INCR', sequence_key)
local score = (available_at * 1000000) + (priority * 1000) + (seq % 1000)

redis.call('HMSET', registry_key,
    'uuid',                uuid,
    'queue',               queue,
    'state',               'pending',
    'priority',            ARGV[3],
    'max_attempts',        ARGV[5],
    'attempts',            ARGV[6],
    'timeout',             ARGV[7],
    'retry_delay',         ARGV[8],
    'available_at',        ARGV[2],
    'created_at',          ARGV[9],
    'updated_at',          ARGV[9],
    'pid',                 '',
    'process_start_ticks', '',
    'handler',             ARGV[10],
    'payload',             ARGV[11]
)

redis.call('ZADD', pending_key, score, uuid)
redis.call('SADD', queues_key, queue)

return 1
