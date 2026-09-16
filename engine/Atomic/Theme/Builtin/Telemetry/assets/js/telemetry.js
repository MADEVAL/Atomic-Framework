const cache = new Map();
const queueFilterKeys = ['driver', 'queue', 'uuid', 'state', 'date_from', 'date_to'];
const FILTER_ID_MAP = {
    driver:    'filter-driver',
    queue:     'filter-queue',
    uuid:      'filter-uuid',
    state:     'filter-state',
    date_from: 'filter-date-from',
    date_to:   'filter-date-to'
};

function updateUrlSearchParams(mutator, mode = 'replace') {
    const url = new URL(window.location.href);
    mutator(url.searchParams);
    const next = url.pathname + (url.search ? url.search : '');
    try {
        if (mode === 'push') {
            window.history.pushState({}, '', next);
        } else {
            window.history.replaceState({}, '', next);
        }
    } catch (e) {
        // Ignore URL sync issues; UI/data rendering should continue.
    }
}

function readPositiveIntParam(params, key, fallback) {
    const raw = Number(params.get(key));
    if (!Number.isFinite(raw) || raw < 1) return fallback;
    return Math.floor(raw);
}

// ── DOM helpers ────────────────────────────────────────────────────────────

function makeEl(tag, classes, text) {
    const e = document.createElement(tag);
    if (classes) e.className = classes;
    if (text !== undefined) e.textContent = text;
    return e;
}

function makeIcon(faClass) {
    return makeEl('i', faClass);
}

function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

function setChildren(el, ...nodes) {
    clearNode(el);
    nodes.forEach(n => el.appendChild(typeof n === 'string' ? document.createTextNode(n) : n));
}

function makeStatusBox(iconCls, colorCls, labelText) {
    const wrap = makeEl('div', 'w3-center w3-margin-top');
    wrap.appendChild(makeIcon(iconCls + ' ' + colorCls));
    wrap.appendChild(makeEl('span', colorCls + ' w3-margin-left', labelText));
    return wrap;
}
function makeSpinner(labelText)  { return makeStatusBox('fa fa-spinner fa-spin',           'w3-text-grey', labelText); }
function makeErrorBox(labelText) { return makeStatusBox('fa fa-exclamation-triangle',       'w3-text-red',  labelText); }
function makeInfoBox(labelText)  { return makeStatusBox('fa fa-info-circle',                'w3-text-grey', labelText); }

function makeTelemetryHeader() {
    const div = makeEl('div', 'w3-text-grey w3-medium atomic-telemetry-header');
    div.appendChild(makeIcon('fa fa-bar-chart w3-margin-right'));
    div.appendChild(document.createTextNode('Telemetry Events'));
    return div;
}

// ── JSON syntax highlighting (DOM-based, no innerHTML) ─────────────────────

function syntaxHighlightJsonFragment(json) {
    if (typeof json !== 'string') json = JSON.stringify(json, undefined, 4);
    const frag = document.createDocumentFragment();
    const re = /("(?:\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(?:\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?|[{}[\],])/g;
    let last = 0, m;
    while ((m = re.exec(json)) !== null) {
        if (m.index > last) {
            frag.appendChild(document.createTextNode(json.slice(last, m.index)));
        }
        const token = m[0];
        let cls;
        if (token[0] === '"') {
            cls = /:$/.test(token) ? 'json-key' : 'json-string';
        } else if (token === 'true' || token === 'false') {
            cls = 'json-boolean';
        } else if (token === 'null') {
            cls = 'json-null';
        } else if (/^-?\d/.test(token)) {
            cls = 'json-number';
        } else {
            cls = 'json-punctuation';
        }
        const span = makeEl('span', cls);
        span.textContent = token;
        frag.appendChild(span);
        last = m.index + token.length;
    }
    if (last < json.length) {
        frag.appendChild(document.createTextNode(json.slice(last)));
    }
    return frag;
}

function makeJsonCodeBlock(json) {
    const code = document.createElement('code');
    code.appendChild(syntaxHighlightJsonFragment(json));
    const pre = makeEl('pre', 'w3-code w3-round atomic-payload-code');
    pre.appendChild(code);
    return pre;
}

// ── Log tag highlighting (DOM-based, no innerHTML) ─────────────────────────

function highlightLogTagsFragment(text) {
    const frag = document.createDocumentFragment();
    const str = text || '';
    const re = /\[([A-Za-z_]+)\]/g;
    let last = 0, m;
    while ((m = re.exec(str)) !== null) {
        if (m.index > last) frag.appendChild(document.createTextNode(str.slice(last, m.index)));
        const upper = m[1].toUpperCase();
        const strong = document.createElement('strong');
        if (upper === 'HIVE' || upper === 'ONERROR') strong.className = 'w3-text-red';
        strong.textContent = '[' + m[1] + ']';
        frag.appendChild(strong);
        last = m.index + m[0].length;
    }
    if (last < str.length) frag.appendChild(document.createTextNode(str.slice(last)));
    return frag;
}

// ── Exception trace highlighting (DOM-based) ───────────────────────────────

function buildTraceFragment(trace) {
    const frag = document.createDocumentFragment();
    if (!trace) return frag;
    const re = /(#\d+\s)|(\\|\/app\/|\/vendor\/)/g;
    let last = 0, m;
    while ((m = re.exec(trace)) !== null) {
        if (m.index > last) frag.appendChild(document.createTextNode(trace.slice(last, m.index)));
        const span = makeEl('span', m[1] ? 'json-key' : 'json-punctuation');
        span.textContent = m[0];
        frag.appendChild(span);
        last = m.index + m[0].length;
    }
    if (last < trace.length) frag.appendChild(document.createTextNode(trace.slice(last)));
    return frag;
}

// ── UI functions ───────────────────────────────────────────────────────────

function toggleFilter() {
    const filterPanel = document.getElementById('filter-panel');
    if (filterPanel.classList.contains('w3-hide')) {
        filterPanel.classList.remove('w3-hide');
        filterPanel.classList.add('w3-show');
    } else {
        filterPanel.classList.remove('w3-show');
        filterPanel.classList.add('w3-hide');
    }
}

function readQueueFilterInputValue(key) {
    const id = FILTER_ID_MAP[key];
    const el = id ? document.getElementById(id) : null;
    return el ? String(el.value || '').trim() : '';
}

function populateQueueFiltersFromQuery() {
    const params = new URLSearchParams(window.location.search);
    queueFilterKeys.forEach((key) => {
        const id = FILTER_ID_MAP[key];
        const el = id ? document.getElementById(id) : null;
        if (!el) return;
        const value = params.get(key);
        if (value !== null) el.value = value;
    });
}

function navigateToTelemetry(params) {
    const qs = params.toString();
    window.location.href = '/telemetry' + (qs ? '?' + qs : '');
}

function applyFilters() {
    const params = new URLSearchParams(window.location.search);
    queueFilterKeys.forEach((key) => {
        const value = readQueueFilterInputValue(key);
        if (value) params.set(key, value);
        else params.delete(key);
    });
    params.set('page', '1');
    if (!params.get('per_page')) params.set('per_page', '50');
    navigateToTelemetry(params);
}

function clearAllFilters() {
    const params = new URLSearchParams(window.location.search);
    queueFilterKeys.forEach((key) => params.delete(key));
    params.set('page', '1');
    navigateToTelemetry(params);
}

function applyQuickFilter(type) {
    const now = new Date();
    const dateFromEl = document.getElementById('filter-date-from');
    const dateToEl = document.getElementById('filter-date-to');
    const stateEl = document.getElementById('filter-state');

    const toInputDateTime = (d) => {
        const pad = (n) => (n < 10 ? '0' + n : '' + n);
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };

    if (type === 'last_hour') {
        const from = new Date(now.getTime() - 60 * 60 * 1000);
        if (dateFromEl) dateFromEl.value = toInputDateTime(from);
        if (dateToEl) dateToEl.value = toInputDateTime(now);
    }

    if (type === 'today') {
        const from = new Date(now);
        from.setHours(0, 0, 0, 0);
        if (dateFromEl) dateFromEl.value = toInputDateTime(from);
        if (dateToEl) dateToEl.value = toInputDateTime(now);
    }

    if (type === 'failed_only' && stateEl) {
        stateEl.value = 'failed';
    }

    applyFilters();
}

function refreshQueueTabContent(button = null) {
    const queueTabBody = document.querySelector('#tab-queues .tab-body');
    if (!queueTabBody) return Promise.resolve();

    if (button) {
        button.disabled = true;
        clearNode(button);
        button.appendChild(makeIcon('fa fa-spinner fa-spin'));
    }

    const refreshUrl = window.location.pathname + window.location.search;
    return fetch(refreshUrl, { headers: { 'Accept': 'text/html' } })
        .then((response) => {
            if (!response.ok) throw new Error(response.status + ' ' + response.statusText);
            return response.text();
        })
        .then((html) => {
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const nextQueueBody = parsed.querySelector('#tab-queues .tab-body');
            if (!nextQueueBody) throw new Error('Queue tab content not found in response');

            clearNode(queueTabBody);
            Array.from(nextQueueBody.childNodes).forEach((node) => {
                queueTabBody.appendChild(document.importNode(node, true));
            });
            populateQueueFiltersFromQuery();
        })
        .catch((error) => {
            setChildren(queueTabBody, makeErrorBox('Error refreshing queue: ' + error.message));
        })
        .finally(() => {
            if (!button) return;
            clearNode(button);
            button.appendChild(makeIcon('fa fa-refresh'));
            button.appendChild(document.createTextNode(' Refresh'));
            button.disabled = false;
        });
}

function refreshQueueFromInlineButton(button) {
    refreshQueueTabContent(button);
}

function toggleJobDetails(jobHeader) {
    const detailsPanel = jobHeader.nextElementSibling;
    const expandIcon = jobHeader.querySelector('.atomic-job-expand-icon i');

    if (!detailsPanel || !detailsPanel.classList.contains('atomic-job-details-panel')) {
        console.error('Details panel not found');
        return;
    }

    if (!expandIcon) {
        console.error('Expand icon not found');
        return;
    }

    const isExpanded = detailsPanel.classList.contains('w3-show');

    if (isExpanded) {
        detailsPanel.classList.remove('w3-show');
        detailsPanel.classList.add('w3-hide');
        expandIcon.className = 'fa fa-chevron-right';
        jobHeader.classList.remove('open');
    } else {
        detailsPanel.classList.remove('w3-hide');
        detailsPanel.classList.add('w3-show');
        expandIcon.className = 'fa fa-chevron-right';
        jobHeader.classList.add('open');

        const uuidEl = jobHeader.querySelector('.atomic-job-uuid');
        const jobUuid = uuidEl ? uuidEl.textContent.trim() : jobHeader.querySelector('.atomic-job-title').textContent.trim();
        const driver = jobHeader.dataset.driver || 'db';
        if (jobUuid) {
            loadTelemetryEvents(jobUuid, detailsPanel, driver);
            formatJsonPayload(jobUuid);
            formatException(jobUuid);
        }
    }
}

function formatJsonPayload(jobUuid) {
    const payloadElement = document.querySelector(`#payload-${jobUuid} code.json-payload`);
    if (!payloadElement) return;

    let rawJson = payloadElement.textContent;
    let formatted = rawJson;
    try {
        formatted = JSON.stringify(JSON.parse(rawJson), null, 4);
    } catch (e) {
        // Not valid JSON, keep as-is
    }
    setChildren(payloadElement, syntaxHighlightJsonFragment(formatted));
}

function formatException(jobUuid) {
    const container = document.querySelector(`#exception-${jobUuid}`);
    if (!container) return;

    const cacheKey = `exception_data:${jobUuid}`;
    let data = cache.get(cacheKey);

    if (!data) {
        const codeEl = container.querySelector('code.json-exception');
        if (!codeEl) return;

        try {
            const obj = JSON.parse(codeEl.textContent.trim());
            data = {
                message: obj.message || 'Exception',
                file: obj.file || '-',
                line: typeof obj.line !== 'undefined' ? obj.line : '-',
                trace: obj.trace_string || ''
            };
        } catch (e) {
            data = {
                message: container.querySelector('.exception-message')?.textContent.trim() || 'Exception',
                file: container.querySelector('.exception-file')?.textContent.trim() || '-',
                line: container.querySelector('.exception-line')?.textContent.trim() || '-',
                trace: container.querySelector('.exception-trace code')?.textContent || ''
            };
        }
        cache.set(cacheKey, data);
    }

    const root = makeEl('div');
    root.style.cssText = 'display:flex;flex-direction:column;gap:6px';

    const msgRow = makeEl('div');
    msgRow.style.cssText = 'display:flex;align-items:center;gap:8px;color:#ff4c4c;font-size:1rem;font-weight:bold';
    msgRow.appendChild(makeIcon('fa fa-bolt'));
    msgRow.appendChild(makeEl('span', '', String(data.message)));
    root.appendChild(msgRow);

    const fileRow = makeEl('div', 'w3-margin-top');
    fileRow.style.cssText = 'display:flex;align-items:center;gap:8px;color:#e0e0e0;font-size:0.97rem';
    fileRow.appendChild(makeIcon('fa fa-file-code-o'));
    fileRow.appendChild(makeEl('span', 'at-text-coral', String(data.file)));
    const colon = makeEl('span');
    colon.style.color = '#aaa';
    colon.textContent = ':';
    fileRow.appendChild(colon);
    const lineSpan = makeEl('span');
    lineSpan.style.color = '#aaa';
    lineSpan.textContent = String(data.line);
    fileRow.appendChild(lineSpan);
    root.appendChild(fileRow);

    const traceWrap = makeEl('div', 'w3-margin-top');
    const traceLabel = makeEl('div');
    traceLabel.style.cssText = 'color:#aaa;font-size:0.85rem;margin-bottom:2px;display:flex;align-items:center;gap:4px';
    traceLabel.appendChild(makeIcon('fa fa-stream'));
    traceLabel.appendChild(makeEl('span', '', 'Trace'));
    traceWrap.appendChild(traceLabel);

    const pre = makeEl('pre', 'atomic-payload-code');
    pre.style.cssText = 'white-space:pre-wrap;margin:0;border-radius:4px;background:#23272e;color:#e0e0e0;font-size:0.93rem;padding:8px 12px;overflow-x:auto';
    const code = document.createElement('code');
    code.appendChild(buildTraceFragment(data.trace));
    pre.appendChild(code);
    traceWrap.appendChild(pre);
    root.appendChild(traceWrap);

    setChildren(container, root);
}

function copyFromDataAttribute(button, attr) {
    try {
        copyToClipboard(button.dataset[attr] || '', button);
    } catch (e) {
        console.error('Failed to copy from data attribute:', e);
        showCopyFeedback(button, false);
    }
}

function togglePayload(button, jobUuid) {
    const payloadContent = document.getElementById('payload-' + jobUuid);
    const isVisible = !payloadContent.classList.contains('w3-hide');
    if (isVisible) {
        payloadContent.classList.add('w3-hide');
        payloadContent.classList.remove('w3-show');
        clearNode(button);
        button.appendChild(makeIcon('fa fa-chevron-right w3-margin-right'));
        button.appendChild(document.createTextNode('Expand'));
    } else {
        payloadContent.classList.remove('w3-hide');
        payloadContent.classList.add('w3-show');
        clearNode(button);
        button.appendChild(makeIcon('fa fa-chevron-down w3-margin-right'));
        button.appendChild(document.createTextNode('Collapse'));
        formatJsonPayload(jobUuid);
    }
}

function copyToClipboard(text, button) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () {
            showCopyFeedback(button, true);
        }).catch(function (err) {
            console.error('Failed to copy using Clipboard API:', err);
            showCopyFeedback(button, false);
        });
    } else {
        console.error('Clipboard API not available');
        showCopyFeedback(button, false);
    }
}


function showCopyFeedback(button, success, customMessage = null) {
    const savedNodes = [...button.childNodes].map(n => n.cloneNode(true));
    const savedClass = button.className;

    const iconEl = button.querySelector('i');
    const isIconOnly = iconEl && iconEl.className.includes('fa-copy') && button.textContent.trim() === '';

    clearNode(button);

    if (success) {
        button.appendChild(makeIcon(isIconOnly ? 'fa fa-check' : 'fa fa-check w3-margin-right'));
        if (!isIconOnly) button.appendChild(document.createTextNode(customMessage || 'Copied!'));
        button.className = savedClass.replace('w3-blue', 'w3-green').replace('w3-dark-grey', 'w3-green');
        button.classList.add('copied');
    } else {
        button.appendChild(makeIcon(isIconOnly ? 'fa fa-times' : 'fa fa-times w3-margin-right'));
        if (!isIconOnly) button.appendChild(document.createTextNode(customMessage || 'Failed'));
        button.className = savedClass.replace('w3-blue', 'w3-red').replace('w3-dark-grey', 'w3-red');
    }

    setTimeout(function () {
        clearNode(button);
        savedNodes.forEach(n => button.appendChild(n));
        button.className = savedClass;
        button.classList.remove('copied');
    }, 2000);
}

// ── Telemetry events ───────────────────────────────────────────────────────

function loadTelemetryEvents(jobUuid, detailsPanel, driver) {
    const container = detailsPanel.querySelector('[id^="telemetry-"]');
    if (!container) return;

    const eventsCacheKey = `events:${jobUuid}`;
    const cached = cache.get(eventsCacheKey);
    if (cached && Array.isArray(cached.events)) {
        displayTelemetryEvents(cached.events, cached.job_uuid, container);
        return;
    }

    clearNode(container);
    container.appendChild(makeTelemetryHeader());
    const spinner = makeSpinner('Loading telemetry events...');
    spinner.classList.add('atomic-loading-spinner');
    container.appendChild(spinner);

    fetch('/telemetry/events/' + encodeURIComponent(driver) + '/' + encodeURIComponent(jobUuid))
        .then(response => response.json())
        .then(data => {
            cache.set(eventsCacheKey, data);
            displayTelemetryEvents(data.events, data.job_uuid, container);
        })
        .catch(error => {
            clearNode(container);
            container.appendChild(makeTelemetryHeader());
            container.appendChild(makeErrorBox('Error loading telemetry events: ' + error.message));
        });
}

function displayTelemetryEvents(events, jobUuid, container) {
    clearNode(container);
    container.appendChild(makeTelemetryHeader());

    if (!events || events.length === 0) {
        container.appendChild(makeInfoBox('No telemetry events found for this job'));
        return;
    }

    const batches = new Map();
    events.forEach(event => {
        if (!batches.has(event.uuid_batch)) batches.set(event.uuid_batch, []);
        batches.get(event.uuid_batch).push(event);
    });

    const batchColors = generateBatchColors(Array.from(batches.keys()));
    const timeline = makeEl('div', 'w3-margin-top atomic-telemetry-timeline');

    let batchIndex = 0;
    for (const [batchUuid, batchEvents] of batches) {
        const batchColor = batchColors[batchIndex % batchColors.length];
        const isLastBatch = batchIndex === batches.size - 1;

        const batchGroup = makeEl('div', 'atomic-batch-group');
        batchGroup.dataset.batch = batchUuid;

        const batchHeader = makeEl('div', 'atomic-batch-header');
        batchHeader.style.cssText = 'display:flex;align-items:center;gap:8px';

        const marker = makeEl('div', 'atomic-batch-marker');
        marker.style.backgroundColor = batchColor;
        batchHeader.appendChild(marker);

        const titleDiv = makeEl('div', 'w3-text-grey w3-small atomic-batch-title');
        titleDiv.style.cssText = 'display:flex;align-items:center;gap:8px';
        titleDiv.appendChild(makeIcon('fa fa-layer-group w3-margin-right'));

        const batchLabel = makeEl('span');
        batchLabel.appendChild(document.createTextNode('Batch: '));
        const batchUuidSpan = makeEl('span', 'atomic-batch-uuid', batchUuid);
        batchUuidSpan.style.fontFamily = 'monospace';
        batchLabel.appendChild(batchUuidSpan);
        titleDiv.appendChild(batchLabel);

        const copyBtn = makeEl('button', 'atomic-copy-btn');
        copyBtn.title = 'Copy Batch UUID';
        copyBtn.appendChild(makeIcon('fa fa-clone'));
        copyBtn.addEventListener('click', () => copyToClipboard(batchUuid, copyBtn));
        titleDiv.appendChild(copyBtn);

        titleDiv.appendChild(makeEl('span', 'w3-margin-left w3-tag w3-round w3-tiny w3-dark-grey', `${batchEvents.length} events`));
        batchHeader.appendChild(titleDiv);
        batchGroup.appendChild(batchHeader);

        batchEvents.forEach((event, eventIndex) => {
            const eventTypeColor = getEventTypeColor(event.event_type_id);
            const eventIcon = getEventTypeIcon(event.event_type_id);
            const isLastEvent = eventIndex === batchEvents.length - 1;

            const eventWithLine = makeEl('div', 'atomic-event-with-line');

            const timelineLine = makeEl('div', 'atomic-timeline-line');
            timelineLine.style.backgroundColor = batchColor;
            timelineLine.dataset.lastEvent = isLastEvent;
            eventWithLine.appendChild(timelineLine);

            const dot = makeEl('div', 'atomic-timeline-dot');
            dot.style.backgroundColor = eventTypeColor;
            dot.style.borderColor = batchColor;
            eventWithLine.appendChild(dot);

            const eventCard = makeEl('div', 'w3-container w3-round atomic-telemetry-event w3-hover-dark-grey');
            const row = makeEl('div', 'w3-row');

            const leftCol = makeEl('div', 'w3-col s8 m8 l8');
            const descDiv = makeEl('div', 'at-text-coral w3-small');
            const evIcon = makeIcon(`fa ${eventIcon} w3-margin-right`);
            evIcon.style.color = eventTypeColor;
            descDiv.appendChild(evIcon);
            descDiv.appendChild(document.createTextNode(event.event_description));
            leftCol.appendChild(descDiv);
            row.appendChild(leftCol);

            const rightCol = makeEl('div', 'w3-col s4 m4 l4 w3-right-align');
            rightCol.style.cssText = 'display:flex;align-items:center;justify-content:flex-end;gap:6px';
            const uuidTag = makeEl('div', 'w3-tag w3-round w3-tiny w3-dark-grey', 'Event UUID: ' + event.uuid);
            uuidTag.style.fontFamily = 'monospace';
            const evCopyBtn = makeEl('button', 'atomic-copy-btn');
            evCopyBtn.title = 'Copy Event UUID';
            evCopyBtn.appendChild(makeIcon('fa fa-clone'));
            evCopyBtn.addEventListener('click', () => copyToClipboard(event.uuid, evCopyBtn));
            rightCol.appendChild(uuidTag);
            rightCol.appendChild(evCopyBtn);
            row.appendChild(rightCol);
            eventCard.appendChild(row);

            const timeDiv = makeEl('div', 'w3-text-grey w3-tiny w3-margin-top');
            timeDiv.appendChild(makeIcon('fa fa-clock-o w3-margin-right'));
            timeDiv.appendChild(document.createTextNode(event.created_at_formatted));
            eventCard.appendChild(timeDiv);

            eventWithLine.appendChild(eventCard);
            batchGroup.appendChild(eventWithLine);
        });

        timeline.appendChild(batchGroup);

        if (!isLastBatch) {
            timeline.appendChild(makeEl('div', 'atomic-batch-separator'));
        }

        batchIndex++;
    }

    container.appendChild(timeline);
}

function generateBatchColors(batchUuids) {
    const colors = [
        '#58a6ff', // Blue
        '#7c3aed', // Purple
        '#f97316', // Orange
        '#10b981', // Emerald
        '#f59e0b', // Amber
        '#ec4899', // Pink
        '#06b6d4', // Cyan
        '#84cc16', // Lime
        '#f43f5e', // Rose
        '#8b5cf6'  // Violet
    ];

    return batchUuids.map(uuid => {
        let hash = 0;
        for (let i = 0; i < uuid.length; i++) {
            hash = ((hash << 5) - hash) + uuid.charCodeAt(i);
            hash = hash & hash;
        }
        return colors[Math.abs(hash) % colors.length];
    });
}

function getEventTypeColor(eventTypeId) {
    switch (eventTypeId) {
        case 1: return '#28a745'; // JOB_CREATED - green
        case 2: return '#1f6feb'; // JOB_FETCHED - blue
        case 3: return '#2ea043'; // JOB_SUCCESS - green
        case 4: return '#f85149'; // JOB_FAILED - red
        case 5: return '#fb8500'; // JOB_RETRY - orange
        case 6: return '#eab308'; // JOB_INCOMPLETE_HANDLED - yellow
        default: return '#7c3aed'; // custom event - purple
    }
}

function getEventTypeIcon(eventTypeId) {
    switch (eventTypeId) {
        case 1: return 'fa-plus-circle';     // JOB_CREATED
        case 2: return 'fa-download';        // JOB_FETCHED
        case 3: return 'fa-check-circle';    // JOB_SUCCESS
        case 4: return 'fa-times-circle';    // JOB_FAILED
        case 5: return 'fa-repeat';          // JOB_RETRY
        case 6: return 'fa-exclamation-circle'; // JOB_INCOMPLETE_HANDLED
        default: return 'fa-star';           // custom event
    }
}

/* Tabs */
(function () {
    const tabState = {
        active: '',
        loaded: { dashboard: false, hive: false, logs: false }
    };
    const logChannelState = new Map();
    const channelDates = new Map();
    const logsViewState = {
        activeChannel: '',
        page: 1,
        perPage: 100,
        date: ''
    };

    function qs(sel, root = document) { return root.querySelector(sel); }
    function qsa(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

    async function fetchJson(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            const snippet = text.replace(/<[^>]*>/g, ' ').trim().slice(0, 200) || res.status + ' ' + res.statusText;
            throw new Error(snippet);
        }
    }

    function setHeaderActionsVisibility(name) {
        const actions = document.getElementById('queue-header-actions');
        const counters = document.getElementById('queue-header-counters');
        const queueFiltersBtn = document.getElementById('queue-header-filters');
        const queueRefreshBtn = document.getElementById('queue-header-refresh');
        const logsRefreshBtn = document.getElementById('logs-header-refresh');

        if (actions) actions.style.display = (name === 'queues' || name === 'logs') ? '' : 'none';
        if (counters) counters.style.display = (name === 'queues') ? '' : 'none';
        if (queueFiltersBtn) queueFiltersBtn.style.display = (name === 'queues') ? '' : 'none';
        if (queueRefreshBtn) queueRefreshBtn.style.display = (name === 'queues') ? '' : 'none';
        if (logsRefreshBtn) logsRefreshBtn.style.display = (name === 'logs') ? '' : 'none';
    }

    function setActiveTab(name) {
        const wasAlreadyLoaded = Boolean(tabState.loaded[name]);

        qsa('.atomic-nav-tab').forEach(b => b.classList.remove('atomic-active'));
        const btn = qs(`.atomic-nav-tab[data-tab="${name}"]`);
        if (btn) btn.classList.add('atomic-active');

        qsa('[id^="tab-"]').forEach(el => el.classList.add('w3-hide'));
        const pane = qs(`#tab-${name}`);
        if (pane) pane.classList.remove('w3-hide');

        setHeaderActionsVisibility(name);

        if (!tabState.loaded[name]) {
            if (name === 'dashboard') loadDashboardTab();
            if (name === 'hive') loadHiveTab();
            if (name === 'logs') loadLogsTab();
            tabState.loaded[name] = true;
        }

        if (name === 'logs' && wasAlreadyLoaded) {
            syncLogsUrlFromMemory('replace');
        } else {
            updateUrlSearchParams((searchParams) => {
                searchParams.set('tab', name);
                if (name !== 'logs') {
                    searchParams.delete('logs_channel');
                    searchParams.delete('logs_page');
                    searchParams.delete('logs_per_page');
                    searchParams.delete('logs_date');
                }
            });
        }

        tabState.active = name;
    }

    function initTabs() {
        populateQueueFiltersFromQuery();
        const queueHeaderRefreshBtn = document.getElementById('queue-header-refresh');
        if (queueHeaderRefreshBtn) {
            queueHeaderRefreshBtn.addEventListener('click', () => {
                refreshQueueTabContent(queueHeaderRefreshBtn);
            });
        }
        const logsHeaderRefreshBtn = document.getElementById('logs-header-refresh');
        if (logsHeaderRefreshBtn) {
            logsHeaderRefreshBtn.addEventListener('click', () => {
                refreshActiveLogsFromHeader(logsHeaderRefreshBtn);
            });
        }

        qsa('.atomic-nav-tab').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                setActiveTab(btn.getAttribute('data-tab'));
            });
        });

        const params = new URLSearchParams(window.location.search);
        const requestedTab = params.get('tab');
        const queueTabQueryKeys = ['page', 'per_page', ...queueFilterKeys];
        const shouldOpenQueues = queueTabQueryKeys.some((key) => params.has(key));
        const initialTab = ['dashboard', 'queues', 'hive', 'logs'].includes(String(requestedTab))
            ? requestedTab
            : (shouldOpenQueues ? 'queues' : 'dashboard');
        setActiveTab(initialTab);
    }

    function loadDashboardTab() {
        const container = qs('#tab-dashboard .tab-body');
        if (!container) return;
        setChildren(container, makeSpinner('Загрузка сведений...'));
        fetchJson('/telemetry/dashboard')
            .then(info => {
                clearNode(container);
                const addGroup = (title, obj) => {
                    if (!obj) return;
                    const header = makeEl('div', 'w3-margin-top w3-text-grey w3-medium atomic-info-header');
                    header.appendChild(makeIcon('fa fa-info-circle w3-margin-right'));
                    header.appendChild(document.createTextNode(title));
                    container.appendChild(header);
                    const group = makeEl('div', 'w3-margin-bottom');
                    Object.keys(obj).forEach(k => {
                        const v = obj[k];
                        const row = makeEl('div', 'w3-row w3-padding-small w3-border-bottom at-border-grey');
                        const keyCol = makeEl('div', 'w3-col s5 m4 l3 w3-text-grey w3-small', k + ':');
                        const valCol = makeEl('div', 'w3-col s7 m8 l9 at-text-coral w3-small',
                            typeof v === 'object' ? JSON.stringify(v) : String(v));
                        valCol.style.wordWrap = 'break-word';
                        row.appendChild(keyCol);
                        row.appendChild(valCol);
                        group.appendChild(row);
                    });
                    container.appendChild(group);
                };
                addGroup('PHP', info.php);
                addGroup('Fat-Free Framework', info.f3);
                addGroup('Atomic', info.atomic);
                addGroup('База данных', info.db);
                addGroup('Система', info.system);
            })
            .catch(err => {
                setChildren(container, makeErrorBox('Ошибка загрузки: ' + err.message));
            });
    }

    function loadHiveTab() {
        const container = qs('#tab-hive .tab-body');
        if (!container) return;
        setChildren(container, makeSpinner('Загрузка Hive...'));
        fetchJson('/telemetry/hive')
            .then(data => {
                setChildren(container, makeJsonCodeBlock(JSON.stringify(data, null, 4)));
            })
            .catch(err => {
                setChildren(container, makeErrorBox('Ошибка загрузки: ' + err.message));
            });
    }

    function formatLogTs(ts) {
        if (!ts) return '';
        const m = ts.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
        let d;
        if (m) {
            d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), Number(m[6]));
        } else {
            const t = Date.parse(ts);
            if (isNaN(t)) return ts;
            d = new Date(t);
        }
        const mm = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const pad = (n) => (n < 10 ? '0' + n : '' + n);
        return `${pad(d.getDate())} ${mm[d.getMonth()]} ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    function renderLogLines(lines, mountEl, channelName) {
        clearNode(mountEl);
        if (!lines.length) {
            mountEl.appendChild(makeInfoBox('Log is empty'));
            return;
        }
        const prefix = 'ch-' + channelName.replace(/[^a-z0-9]/gi, '_');
        const list = makeEl('div', 'atomic-log-list');

        lines.forEach((ln, idx) => {
            const msgRaw = ln.message || '';
            const hasOnerror = /\[ONERROR\]/i.test(msgRaw);
            const hasHive = /\[HIVE\]/i.test(msgRaw);
            const chipText = hasOnerror ? 'ONERROR' : (hasHive ? 'HIVE' : (ln.level || '').toUpperCase());

            const lineEl = makeEl('div', 'atomic-log-line w3-padding-small w3-border-bottom at-border-dark-grey');

            const metaDiv = makeEl('div', 'w3-small w3-text-grey');
            const chip = makeEl('span', 'atomic-chip');
            chip.appendChild(makeIcon('fa fa-tag'));
            chip.appendChild(document.createTextNode(chipText));
            metaDiv.appendChild(chip);
            metaDiv.appendChild(makeEl('span', 'w3-margin-left', formatLogTs(ln.ts)));
            lineEl.appendChild(metaDiv);

            const msgDiv = makeEl('div', 'w3-margin-top at-text-coral w3-small');
            msgDiv.style.wordBreak = 'break-word';
            msgDiv.appendChild(highlightLogTagsFragment(msgRaw));
            lineEl.appendChild(msgDiv);

            if (ln.dump_id) {
                const dumpDiv = makeEl('div', 'w3-right at-log-dump-buttons');

                const copyBtn = makeEl('button', 'atomic-copy-btn');
                copyBtn.title = 'Copy dump id';
                copyBtn.appendChild(makeIcon('fa fa-clone'));
                copyBtn.addEventListener('click', () => copyToClipboard(ln.dump_id, copyBtn));

                const viewBtn = makeEl('button', 'atomic-copy-btn');
                viewBtn.appendChild(makeIcon('fa fa-eye'));
                viewBtn.appendChild(document.createTextNode(' View dump'));
                viewBtn.addEventListener('click', () => viewDump(ln.dump_id, `${prefix}-dump-${idx}`));

                dumpDiv.appendChild(copyBtn);
                dumpDiv.appendChild(viewBtn);
                lineEl.appendChild(dumpDiv);
            }

            const dumpMount = makeEl('div', 'w3-margin-top w3-hide');
            dumpMount.id = `${prefix}-dump-${idx}`;
            lineEl.appendChild(dumpMount);

            list.appendChild(lineEl);
        });

        mountEl.appendChild(list);
    }

    function logLogsUrl(channelName, page = 1, perPage = 100, logDate = '') {
        const params = new URLSearchParams();
        if (channelName) params.set('channel', channelName);
        if (logDate) params.set('date', logDate);
        params.set('page', String(page));
        params.set('per_page', String(perPage));
        return '/telemetry/logs?' + params.toString();
    }

    function syncLogsUrlState(channelName, page, perPage, logDate = '', mode = 'replace') {
        const normalizedPage = Math.max(1, Number(page) || 1);
        const normalizedPerPage = Math.max(1, Number(perPage) || 100);
        logsViewState.activeChannel = channelName || '';
        logsViewState.page = normalizedPage;
        logsViewState.perPage = normalizedPerPage;
        logsViewState.date = logDate || '';

        updateUrlSearchParams((searchParams) => {
            searchParams.set('tab', 'logs');
            if (channelName) searchParams.set('logs_channel', channelName);
            else searchParams.delete('logs_channel');
            searchParams.set('logs_page', String(normalizedPage));
            searchParams.set('logs_per_page', String(normalizedPerPage));
            if (logDate) searchParams.set('logs_date', logDate);
            else searchParams.delete('logs_date');
        }, mode);
    }

    function syncLogsUrlFromMemory(mode = 'replace') {
        const channelFromUi = qs('.atomic-log-channel-tab.active')?.dataset.channel || '';
        const channelName = channelFromUi || logsViewState.activeChannel || '';
        const state = getLogChannelState(channelName);
        const page = Math.max(1, Number(state.page || logsViewState.page || 1));
        const perPage = Math.max(1, Number(state.per_page || logsViewState.perPage || 100));
        const logDate = state.date || logsViewState.date || '';
        syncLogsUrlState(channelName, page, perPage, logDate, mode);
    }

    function normalizeLogPagination(raw, fallbackPerPage, fallbackLineCount) {
        const perPage = Math.max(1, Number(raw?.per_page || fallbackPerPage || 100));
        const total = Math.max(0, Number(raw?.total ?? fallbackLineCount ?? 0));
        const lastPage = Math.max(1, Number(raw?.last_page || Math.ceil(total / perPage) || 1));
        const pageRaw = Math.max(1, Number(raw?.page || 1));
        const page = Math.min(pageRaw, lastPage);
        return {
            page,
            per_page: perPage,
            total,
            last_page: lastPage
        };
    }

    function getLogChannelState(channelName) {
        if (!logChannelState.has(channelName)) {
            logChannelState.set(channelName, {
                page: 1,
                per_page: 100,
                total: 0,
                last_page: 1,
                date: '',
                dates: []
            });
        }
        return logChannelState.get(channelName);
    }

    function updateChannelMeta(metaEl, pagination, logDate = '') {
        if (!metaEl) return;
        const now = new Date();
        const pad = n => (n < 10 ? '0' + n : '' + n);
        const ts = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        const from = pagination.total > 0 ? ((pagination.page - 1) * pagination.per_page) + 1 : 0;
        const to = pagination.total > 0 ? Math.min(pagination.total, pagination.page * pagination.per_page) : 0;
        clearNode(metaEl);
        metaEl.appendChild(makeIcon('fa fa-list-ul'));
        metaEl.appendChild(document.createTextNode(` ${from}-${to} of ${pagination.total} lines | `));
        if (logDate) {
            metaEl.appendChild(makeIcon('fa fa-calendar'));
            metaEl.appendChild(document.createTextNode(` ${logDate} | `));
        }
        metaEl.appendChild(document.createTextNode(`page ${pagination.page}/${pagination.last_page} | `));
        metaEl.appendChild(makeIcon('fa fa-clock-o'));
        metaEl.appendChild(document.createTextNode(` ${ts}`));
    }

    function normalizeLogDates(rawDates) {
        if (!Array.isArray(rawDates)) return [];
        const valid = rawDates
            .map((d) => String(d || '').trim())
            .filter((d) => /^\d{4}-\d{2}-\d{2}$/.test(d));
        return Array.from(new Set(valid)).sort((a, b) => b.localeCompare(a));
    }

    function renderLogDateRow(channelName, rowEl, bodyEl, metaEl, pagerEl) {
        if (!rowEl) return;
        clearNode(rowEl);

        const state = getLogChannelState(channelName);
        const dates = normalizeLogDates(channelDates.get(channelName) || state.dates || []);
        channelDates.set(channelName, dates);
        state.dates = dates;

        if (!dates.length) {
            rowEl.appendChild(makeEl('span', 'atomic-log-date-empty w3-small w3-text-grey', 'No dated log files found'));
            state.date = '';
            return;
        }

        if (!dates.includes(state.date)) {
            state.date = dates[0];
        }

        const label = makeEl('span', 'atomic-log-date-label w3-small w3-text-grey', 'Dates:');
        rowEl.appendChild(label);

        dates.forEach((dateValue) => {
            const btn = makeEl('button', 'atomic-log-date-tab' + (dateValue === state.date ? ' active' : ''));
            btn.type = 'button';
            btn.dataset.date = dateValue;
            btn.textContent = dateValue;
            btn.addEventListener('click', () => {
                if (state.date === dateValue) return;
                state.date = dateValue;
                state.page = 1;
                renderLogDateRow(channelName, rowEl, bodyEl, metaEl, pagerEl);
                loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, 1, state.per_page, dateValue, 'push');
            });
            rowEl.appendChild(btn);
        });
    }

    function renderLogsPaginationControls(pagerEl, pagination, onChange) {
        if (!pagerEl) return;
        clearNode(pagerEl);

        const summary = makeEl('span', 'atomic-log-pagination-summary w3-small w3-text-grey');
        if (pagination.total > 0) {
            const from = ((pagination.page - 1) * pagination.per_page) + 1;
            const to = Math.min(pagination.total, pagination.page * pagination.per_page);
            summary.textContent = `Showing ${from}-${to} of ${pagination.total}`;
        } else {
            summary.textContent = 'Showing 0-0 of 0';
        }

        const controls = makeEl('div', 'atomic-log-pagination-controls');
        const pageSizeLabel = makeEl('span', 'w3-small w3-text-grey', 'Rows:');
        controls.appendChild(pageSizeLabel);

        const pageSizeSelect = makeEl('select', 'filter-select atomic-pagination-select');
        [50, 100, 200, 500].forEach((opt) => {
            const option = document.createElement('option');
            option.value = String(opt);
            option.textContent = String(opt);
            if (pagination.per_page === opt) option.selected = true;
            pageSizeSelect.appendChild(option);
        });
        pageSizeSelect.addEventListener('change', () => {
            onChange(1, Number(pageSizeSelect.value || 100));
        });
        controls.appendChild(pageSizeSelect);

        const makePagerBtn = (iconClass, disabled, onClick) => {
            const btn = makeEl('button', 'w3-button w3-round w3-small w3-border');
            btn.type = 'button';
            btn.appendChild(makeIcon(iconClass));
            btn.disabled = Boolean(disabled);
            if (btn.disabled) btn.classList.add('atomic-btn-disabled');
            btn.addEventListener('click', onClick);
            return btn;
        };

        controls.appendChild(makePagerBtn('fa fa-angle-double-left', pagination.page <= 1, () => onChange(1, pagination.per_page)));
        controls.appendChild(makePagerBtn('fa fa-angle-left', pagination.page <= 1, () => onChange(Math.max(1, pagination.page - 1), pagination.per_page)));

        const pageLabel = makeEl('span', 'atomic-pagination-current w3-small', `Page ${pagination.page} / ${pagination.last_page}`);
        controls.appendChild(pageLabel);

        controls.appendChild(makePagerBtn('fa fa-angle-right', pagination.page >= pagination.last_page, () => onChange(Math.min(pagination.last_page, pagination.page + 1), pagination.per_page)));
        controls.appendChild(makePagerBtn('fa fa-angle-double-right', pagination.page >= pagination.last_page, () => onChange(pagination.last_page, pagination.per_page)));

        pagerEl.appendChild(summary);
        pagerEl.appendChild(controls);
    }

    function loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, page = null, perPage = null, logDate = null, historyMode = 'replace') {
        if (!bodyEl) return Promise.resolve();
        const state = getLogChannelState(channelName);
        if (page !== null) state.page = Math.max(1, Number(page));
        if (perPage !== null) state.per_page = Math.max(1, Number(perPage));
        if (logDate !== null) state.date = String(logDate || '');
        if (!state.date) {
            const knownDates = normalizeLogDates(channelDates.get(channelName) || state.dates || []);
            if (knownDates.length) state.date = knownDates[0];
        }

        syncLogsUrlState(channelName, state.page, state.per_page, state.date || '', historyMode);

        const cacheKey = `logs:${channelName}:${state.date || 'none'}:${state.page}:${state.per_page}`;
        const cached = cache.get(cacheKey);
        if (cached) {
            state.total     = cached.pagination.total;
            state.last_page = cached.pagination.last_page;
            state.page      = cached.pagination.page;
            state.per_page  = cached.pagination.per_page;
            state.date      = cached.date || state.date || '';
            state.dates     = normalizeLogDates(cached.dates || state.dates || []);
            renderLogLines(cached.lines, bodyEl, channelName);
            updateChannelMeta(metaEl, cached.pagination, state.date);
            renderLogsPaginationControls(pagerEl, cached.pagination, (nextPage, nextPerPage) => {
                loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, nextPage, nextPerPage, state.date, 'push');
            });
            return Promise.resolve();
        }

        setChildren(bodyEl, makeSpinner('Loading logs...'));
        return fetchJson(logLogsUrl(channelName, state.page, state.per_page, state.date))
            .then(payload => {
                const lines = payload.lines || [];
                const pagination = normalizeLogPagination(payload.pagination, state.per_page, lines.length);
                const responseDates = normalizeLogDates(payload.dates || state.dates || []);
                if (responseDates.length) {
                    channelDates.set(channelName, responseDates);
                    state.dates = responseDates;
                }
                const responseDate = String(payload.date || state.date || '');
                if (responseDate) state.date = responseDate;

                const resolvedCacheKey = `logs:${channelName}:${state.date || 'none'}:${pagination.page}:${pagination.per_page}`;
                cache.set(resolvedCacheKey, { lines, pagination, date: state.date, dates: state.dates });

                state.total     = pagination.total;
                state.last_page = pagination.last_page;
                state.page      = pagination.page;
                state.per_page  = pagination.per_page;
                syncLogsUrlState(channelName, state.page, state.per_page, state.date, 'replace');

                renderLogLines(lines, bodyEl, channelName);
                updateChannelMeta(metaEl, pagination, state.date);
                renderLogsPaginationControls(pagerEl, pagination, (nextPage, nextPerPage) => {
                    loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, nextPage, nextPerPage, state.date, 'push');
                });
            })
            .catch(err => {
                setChildren(bodyEl, makeErrorBox('Error: ' + err.message));
                if (pagerEl) clearNode(pagerEl);
            });
    }

    function refreshChannelLogs(channelName, bodyEl, metaEl, pagerEl, btn) {
        const state = getLogChannelState(channelName);
        cache.delete(`logs:${channelName}:${state.date || 'none'}:${state.page}:${state.per_page}`);

        if (!btn) {
            return loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, state.page, state.per_page, state.date, 'replace');
        }

        const savedClass = btn.className;
        btn.disabled = true;
        clearNode(btn);
        btn.appendChild(makeIcon('fa fa-spinner fa-spin'));

        return loadChannelLogs(channelName, bodyEl, metaEl, pagerEl, state.page, state.per_page, state.date, 'replace')
            .finally(() => {
                clearNode(btn);
                btn.appendChild(makeIcon('fa fa-refresh'));
                btn.appendChild(document.createTextNode(' Refresh'));
                btn.className = savedClass;
                btn.disabled = false;
            });
    }

    function getActiveLogsPaneElements() {
        const activeChannel = logsViewState.activeChannel || '';
        const paneSelector = activeChannel
            ? `.atomic-log-channel-pane[data-channel="${CSS.escape(activeChannel)}"]`
            : '.atomic-log-channel-pane';
        const tabBody = qs('#tab-logs .tab-body');
        if (!tabBody) return null;

        const pane = qs(paneSelector, tabBody);
        if (!pane) {
            const bodyEl = qs('.atomic-log-channel-body', tabBody);
            const pagerEl = qs('.atomic-log-pagination', tabBody);
            if (!bodyEl || !pagerEl) return null;
            return { channelName: activeChannel, bodyEl, metaEl: null, pagerEl };
        }

        const bodyEl = qs('.atomic-log-channel-body', pane);
        const metaEl = qs('.atomic-log-channel-meta', pane);
        const pagerEl = qs('.atomic-log-pagination', pane);
        if (!bodyEl || !pagerEl) return null;

        return { channelName: activeChannel, bodyEl, metaEl, pagerEl };
    }

    function refreshActiveLogsFromHeader(btn) {
        const paneState = getActiveLogsPaneElements();
        if (!paneState) return;

        btn.disabled = true;
        clearNode(btn);
        btn.appendChild(makeIcon('fa fa-spinner fa-spin'));

        refreshChannelLogs(
            paneState.channelName,
            paneState.bodyEl,
            paneState.metaEl,
            paneState.pagerEl,
            null
        ).finally(() => {
            clearNode(btn);
            btn.appendChild(makeIcon('fa fa-refresh'));
            btn.appendChild(document.createTextNode(' Refresh'));
            btn.disabled = false;
        });
    }

    function loadLogsTab() {
        const container = qs('#tab-logs .tab-body');
        if (!container) return;
        setChildren(container, makeSpinner('Loading channels...'));
        fetchJson('/telemetry/log-channels')
            .then(payload => {
                const channels = payload.channels || [];
                const channelDatesPayload = payload.channel_dates || {};
                const query = new URLSearchParams(window.location.search);
                const queryLogsChannel = query.get('logs_channel') || '';
                const queryLogsDate = query.get('logs_date') || '';
                const queryLogsPage = readPositiveIntParam(query, 'logs_page', 1);
                const queryLogsPerPage = readPositiveIntParam(query, 'logs_per_page', 100);
                clearNode(container);
                if (!channels.length) {
                    const body = makeEl('div', 'atomic-log-channel-body');
                    const dateRow = makeEl('div', 'atomic-log-dates-row');
                    const pager = makeEl('div', 'atomic-log-pagination atomic-pagination-bar w3-margin-top');
                    container.appendChild(body);
                    container.appendChild(dateRow);
                    container.appendChild(pager);
                    const state = getLogChannelState('');
                    state.page = queryLogsPage;
                    state.per_page = queryLogsPerPage;
                    state.date = queryLogsDate;
                    renderLogDateRow('', dateRow, body, null, pager);
                    loadChannelLogs('', body, null, pager, state.page, state.per_page, state.date, 'replace');
                    return;
                }

                const nav = makeEl('div', 'atomic-log-channels-nav');
                const content = makeEl('div', 'atomic-log-channels-content');

                const initialChannel = channels.includes(queryLogsChannel) ? queryLogsChannel : channels[0];
                channels.forEach((ch) => {
                    const dates = normalizeLogDates(channelDatesPayload[ch] || []);
                    channelDates.set(ch, dates);
                });
                const initialDates = channelDates.get(initialChannel) || [];
                const initialDate = initialDates.includes(queryLogsDate) ? queryLogsDate : (initialDates[0] || '');
                logsViewState.activeChannel = initialChannel;
                logsViewState.page = queryLogsPage;
                logsViewState.perPage = queryLogsPerPage;
                logsViewState.date = initialDate;

                channels.forEach((ch) => {
                    const tabBtn = makeEl('button', 'atomic-log-channel-tab' + (ch === initialChannel ? ' active' : ''));
                    tabBtn.type = 'button';
                    tabBtn.dataset.channel = ch;
                    tabBtn.appendChild(makeIcon('fa fa-file-text-o'));
                    tabBtn.appendChild(document.createTextNode(' ' + ch));
                    nav.appendChild(tabBtn);

                    const pane = makeEl('div', 'atomic-log-channel-pane' + (ch === initialChannel ? '' : ' w3-hide'));
                    pane.dataset.channel = ch;

                    const header = makeEl('div', 'atomic-log-channel-header');
                    const meta = makeEl('span', 'atomic-log-channel-meta w3-text-grey w3-small');
                    header.appendChild(meta);

                    const dateRow = makeEl('div', 'atomic-log-dates-row');
                    const pager = makeEl('div', 'atomic-log-pagination atomic-pagination-bar w3-margin-top');
                    const body = makeEl('div', 'atomic-log-channel-body');
                    pane.appendChild(header);
                    pane.appendChild(dateRow);
                    pane.appendChild(body);
                    pane.appendChild(pager);
                    content.appendChild(pane);

                    const state = getLogChannelState(ch);
                    const channelDatesList = channelDates.get(ch) || [];
                    state.date = ch === initialChannel
                        ? initialDate
                        : (state.date || channelDatesList[0] || '');
                    state.dates = channelDatesList;
                    renderLogDateRow(ch, dateRow, body, meta, pager);

                    tabBtn.addEventListener('click', () => {
                        nav.querySelectorAll('.atomic-log-channel-tab').forEach(b => b.classList.remove('active'));
                        tabBtn.classList.add('active');
                        content.querySelectorAll('.atomic-log-channel-pane').forEach(p => p.classList.add('w3-hide'));
                        pane.classList.remove('w3-hide');

                        const state = getLogChannelState(ch);
                        if (!state.date) {
                            state.date = (channelDates.get(ch) || [])[0] || '';
                        }
                        logsViewState.activeChannel = ch;
                        logsViewState.page = state.page;
                        logsViewState.perPage = state.per_page;
                        logsViewState.date = state.date || '';
                        renderLogDateRow(ch, dateRow, body, meta, pager);

                        // Always load on tab switch; cache avoids unnecessary network traffic.
                        loadChannelLogs(ch, body, meta, pager, state.page, state.per_page, state.date, 'push');
                    });
                });

                container.appendChild(nav);
                container.appendChild(content);

                const firstPane = content.querySelector(`.atomic-log-channel-pane[data-channel="${CSS.escape(initialChannel)}"]`);
                if (firstPane) {
                    const state = getLogChannelState(initialChannel);
                    state.page = queryLogsPage;
                    state.per_page = queryLogsPerPage;
                    state.date = initialDate;
                    state.dates = initialDates;
                    const firstDateRow = firstPane.querySelector('.atomic-log-dates-row');
                    renderLogDateRow(
                        initialChannel,
                        firstDateRow,
                        firstPane.querySelector('.atomic-log-channel-body'),
                        firstPane.querySelector('.atomic-log-channel-meta'),
                        firstPane.querySelector('.atomic-log-pagination')
                    );
                    loadChannelLogs(
                        initialChannel,
                        firstPane.querySelector('.atomic-log-channel-body'),
                        firstPane.querySelector('.atomic-log-channel-meta'),
                        firstPane.querySelector('.atomic-log-pagination'),
                        state.page,
                        state.per_page,
                        state.date,
                        'replace'
                    );
                }
            })
            .catch(() => {
                clearNode(container);
                const body = makeEl('div', 'atomic-log-channel-body');
                const dateRow = makeEl('div', 'atomic-log-dates-row');
                const pager = makeEl('div', 'atomic-log-pagination atomic-pagination-bar w3-margin-top');
                container.appendChild(body);
                container.appendChild(dateRow);
                container.appendChild(pager);
                const query = new URLSearchParams(window.location.search);
                const queryLogsDate = query.get('logs_date') || '';
                loadChannelLogs(
                    '',
                    body,
                    null,
                    pager,
                    readPositiveIntParam(query, 'logs_page', 1),
                    readPositiveIntParam(query, 'logs_per_page', 100),
                    queryLogsDate,
                    'replace'
                );
            });
    }

    function viewDump(dumpId, targetId) {
        const mount = document.getElementById(targetId);
        if (!mount) return;
        if (!mount.classList.contains('w3-hide')) {
            mount.classList.add('w3-hide');
            clearNode(mount);
            return;
        }
        setChildren(mount, makeSpinner('Загрузка дампа...'));
        fetchJson('/telemetry/dumps/' + encodeURIComponent(dumpId))
            .then(data => {
                setChildren(mount, makeJsonCodeBlock(JSON.stringify(data, null, 4)));
                mount.classList.remove('w3-hide');
            })
            .catch(err => {
                const errDiv = makeEl('div', 'w3-text-red');
                errDiv.appendChild(makeIcon('fa fa-exclamation-triangle'));
                errDiv.appendChild(document.createTextNode(' ' + err.message));
                setChildren(mount, errDiv);
                mount.classList.remove('w3-hide');
            });
    }

    window.addEventListener('DOMContentLoaded', initTabs);
})();
