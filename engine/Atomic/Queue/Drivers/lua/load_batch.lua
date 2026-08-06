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
    if limit <= 0 then
        return {}
    end

    local results = {}
    local candidates = {}

    local max_score = (now * 1000000) + 999999
    local candidate_scan_limit = math.min(math.max(limit * 100, 1000), 10000)
    local uuids = redis.call('ZRANGEBYSCORE', pending_idx_key, '-inf', max_score, 'LIMIT', 0, candidate_scan_limit)

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
            local priority = tonumber(job.priority)
            local available_at = tonumber(job.available_at)
            local pending_sequence = tonumber(job.pending_sequence)
            if attempts == nil then
                error('missing or invalid required job field: attempts')
            end
            if timeout == nil then
                error('missing or invalid required job field: timeout')
            end
            if priority == nil then
                error('missing or invalid required job field: priority')
            end
            if available_at == nil then
                error('missing or invalid required job field: available_at')
            end
            if job.payload == nil or job.payload == '' then
                error('missing required job field: payload')
            end

            if pending_sequence == nil then
                pending_sequence = tonumber(redis.call('ZSCORE', pending_idx_key, uuid)) or 0
            end

            table.insert(candidates, {
                uuid = uuid,
                registry_key = registry_key,
                job = job,
                attempts = attempts,
                timeout = timeout,
                priority = priority,
                available_at = available_at,
                pending_sequence = pending_sequence
            })
        elseif job.state == nil or job.state == '' then
            error('missing required job field: state')
        end
    end

    table.sort(candidates, function(a, b)
        if a.priority ~= b.priority then
            return a.priority < b.priority
        end
        if a.available_at ~= b.available_at then
            return a.available_at < b.available_at
        end
        if a.pending_sequence ~= b.pending_sequence then
            return a.pending_sequence < b.pending_sequence
        end
        return a.uuid < b.uuid
    end)

    for i = 1, math.min(limit, #candidates) do
        local candidate = candidates[i]
        local job = candidate.job

        job.attempts = candidate.attempts + 1
        job.state = 'running'
        job.available_at = now + candidate.timeout
        job.updated_at = now

        local running_score = job.available_at * 1000

        redis.call('HMSET', candidate.registry_key,
            'state', 'running',
            'attempts', tostring(job.attempts),
            'available_at', tostring(job.available_at),
            'updated_at', tostring(job.updated_at)
        )

        redis.call('ZREM', pending_idx_key, candidate.uuid)
        redis.call('ZADD', running_idx_key, running_score, candidate.uuid)

        job.payload = cjson.decode(job.payload)
        table.insert(results, cjson.encode(job))
    end

    return results
