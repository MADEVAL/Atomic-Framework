-- KEYS[1]: {queue}.idx.{state} key
-- ARGV[1]: prefix
-- ARGV[2]: offset (0-based)
-- ARGV[3]: limit

local index_key = KEYS[1]
local prefix = ARGV[1]
local offset = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])

if offset == nil then
    error('missing or invalid required argument: offset')
end
if limit == nil then
    error('missing or invalid required argument: limit')
end

local result = {}
local cursor = offset
local batch_size = math.max(limit, 25)

while #result < limit do
    local uuids = redis.call('ZREVRANGE', index_key, cursor, cursor + batch_size - 1)
    if #uuids == 0 then
        break
    end

    cursor = cursor + #uuids

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

        table.insert(result, {uuid, cjson.encode(job)})
        if #result >= limit then
            break
        end
    end
end

local total = redis.call('ZCARD', index_key)

return {total, result}
