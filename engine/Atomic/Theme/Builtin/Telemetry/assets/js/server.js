/* Atomic Telemetry
   The server renders every tab (views/*.php). This script only:
     - swaps a tab's HTML in place, prefetching it while the pointer rests on its nav button;
     - filters and searches rendered rows and opens their drawers (data-* attributes, see Ui.php);
     - fetches server fragments on demand: [data-modal=url] opens one in the shared modal,
       [data-load=url] fills itself once it is shown;
     - runs the shell tools: theme, refresh, auto refresh, range bar, copy, Escape.
   Config, set by the server: window.TELEMETRY_CONFIG = { base, publicAccess, history } */
(function () {
  'use strict';

  const config = Object.assign({ base: '/telemetry', history: [] }, window.TELEMETRY_CONFIG || {});

  const PREFETCH_DELAY_MS = 65; // a pointer passing over the nav doesn't fetch
  const FRESH_MS = 10000; // a fetched tab is reused this long
  const COPIED_MS = 1500;
  const AUTO_REFRESH_OPTIONS = [0, 10, 30, 60]; // seconds; 0 is off

  const find = (selector, root = document) => root.querySelector(selector);
  const findAll = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const pane = tab => find('#tab-' + tab);
  const isTyping = node => !!node && /^(INPUT|SELECT|TEXTAREA)$/.test(node.tagName);

  const store = {
    get(key, fallback) {
      try {
        const value = localStorage.getItem(key);
        return value == null ? fallback : value;
      } catch (e) {
        return fallback;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch (e) {
        // Storage is blocked; the setting just isn't remembered.
      }
    },
  };

  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'ic');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  }

  function failedNode(message, url) {
    const box = document.createElement('div');
    box.className = 'failed';
    const body = document.createElement('div');
    const title = document.createElement('b');
    title.textContent = message;
    const where = document.createElement('div');
    where.className = 'mono';
    where.textContent = url;
    const retry = document.createElement('button');
    retry.className = 'btn';
    retry.dataset.retry = '';
    retry.appendChild(document.createElement('span')).textContent = 'Retry';
    body.append(title, where, retry);
    box.append(icon('alert'), body);
    return box;
  }

  /* ── Fetching tabs ──
     One request per tab at a time; a fetched tab stays fresh for FRESH_MS. */
  const fetched = new Map(); // tab => { at, request }
  let range = store.get('tl-range', '24h');
  let active = null;

  const tabQueries = new Map(); // tab => query string its rail picked (Logs: channel and date)

  function tabUrl(tab) {
    const query = [config.history.includes(tab) ? 'range=' + encodeURIComponent(range) : '', tabQueries.get(tab) || ''].filter(Boolean).join('&');
    return config.base + '/view/' + tab + (query ? '?' + query : '');
  }

  function request(tab, reuse) {
    const cached = fetched.get(tab);
    if (reuse && cached && Date.now() - cached.at < FRESH_MS) return cached.request;

    const url = tabUrl(tab);
    const pending = fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
      .then(response => {
        if (!response.ok) throw new Error(response.status + ' ' + response.statusText);
        return response.text();
      })
      .catch(error => {
        fetched.delete(tab);
        throw Object.assign(error, { url });
      });
    fetched.set(tab, { at: Date.now(), request: pending });
    return pending;
  }

  function prefetch(tab) {
    if (tab !== active) request(tab, true).catch(() => {});
  }

  async function load(tab, reuse) {
    const target = pane(tab);
    if (!target.childElementCount) target.replaceChildren(Object.assign(document.createElement('div'), { className: 'empty', textContent: 'Loading…' }));
    try {
      const html = await request(tab, reuse);
      if (tab === active) swap(tab, html);
    } catch (error) {
      if (tab === active) target.replaceChildren(failedNode(error.message || 'Could not reach the server.', error.url || ''));
    }
  }

  function swap(tab, html) {
    const target = pane(tab);
    const previous = loadedLines(target);
    target.innerHTML = html;
    keepLoadedLines(target, previous);
    findAll('[data-badge]', target).forEach(node => setBadge(node.dataset.badge, Number(node.dataset.value)));
    if (expanded.has(tab)) applyExpanded(target, true);
    const page = find('[data-list]', target);
    if (page) applyList(page, listState(tab), true);
    const tree = find('[data-tree]', target);
    if (tree) applyTree(tree, treeState(tab));
    if (tab === active) watchMore();
  }

  /* ── Tabs ── */
  function show(tab, reuse = true) {
    const changed = active !== tab;
    active = tab;
    findAll('.nav-tab').forEach(button => button.classList.toggle('is-on', button.dataset.tab === tab));
    findAll('.pane').forEach(section => section.classList.toggle('is-on', section.id === 'tab-' + tab));
    find('#rangebar').classList.toggle('hide', !config.history.includes(tab));

    try {
      const url = new URL(location.href);
      url.searchParams.set('tab', tab);
      history.replaceState({}, '', url);
    } catch (e) {
      // The address bar keeps the tab that was open.
    }
    store.set('tl-tab', tab);
    if (changed) scrollTo(0, 0);
    watchMore();
    load(tab, reuse);
  }

  function setBadge(id, count) {
    const badge = find('#' + id);
    if (!badge) return;
    badge.textContent = count;
    badge.classList.toggle('hide', !count);
  }

  /* ── Lists: filter pills, search, selection, drawer ──
     State is kept per tab, so it survives refreshes and tab switches. */
  const lists = new Map();
  const listState = tab => {
    if (!lists.has(tab)) lists.set(tab, { filter: 'all', search: '', selected: null });
    return lists.get(tab);
  };

  function applyList(page, state, fresh) {
    // A page can open on another pill than "all" (Security: sessions, Database: the app's tables).
    if (!state.opened) {
      state.opened = true;
      if (page.dataset.default) state.filter = page.dataset.default;
    }
    const needle = state.search.trim().toLowerCase();
    const rows = findAll('[data-row]', page);
    const searched = rows.filter(row => !needle || row.dataset.text.includes(needle));
    // Logs count levels over every loaded line, whatever the search.
    const counted = 'countAll' in page.dataset ? rows : searched;
    const matching = key => counted.filter(row => row.dataset.keys.split(' ').includes(key)).length;
    const visible = new Set(searched.filter(row => row.dataset.keys.split(' ').includes(state.filter)));
    const shownIn = root => findAll('[data-row]', root).filter(row => visible.has(row)).length;

    findAll('[data-count]', page).forEach(node => {
      if (node.dataset.count === '@group') return;
      if (node.dataset.count === '@loaded') {
        node.textContent = rows.length.toLocaleString('en-US');
        return;
      }
      const count = node.dataset.count === '@visible' ? shownIn(node.closest('.list') || page)
        : 'total' in node.dataset && !needle ? Number(node.dataset.total) : matching(node.dataset.count);
      node.textContent = count;
      if ('hot' in node.dataset) node.classList.toggle('is-hot', count > 0);
    });
    findAll('[data-hide-zero]', page).forEach(node => node.classList.toggle('hide', !matching(node.dataset.hideZero)));
    const heads = findAll('[data-group-head]', page);
    heads.forEach(head => {
      const shown = rows.filter(row => row.dataset.group === head.dataset.groupHead && visible.has(row)).length;
      head.classList.toggle('hide', !shown);
      find('[data-count="@group"]', head).textContent = shown;
    });
    // The first visible group sits tight under the list head; the first visible row of a group has no top border.
    let previous = null;
    if (heads.length) findAll('[data-group-head], [data-row]', page).forEach(node => {
      const shown = node.dataset.groupHead ? !node.classList.contains('hide') : visible.has(node);
      if (!shown) return;
      if (node.dataset.groupHead) node.classList.toggle('is-first', previous === null);
      else node.classList.toggle('is-lead', !!previous && !!previous.dataset.groupHead);
      previous = node;
    });
    // Switched lists sit their first row right under the list head, without a top border.
    if ('views' in page.dataset) findAll('.list', page).forEach(list => {
      const first = findAll('[data-row]', list).find(row => visible.has(row));
      findAll('[data-row]', list).forEach(row => row.classList.toggle('is-lead', row === first));
    });
    findAll('.pill[data-filter]', page).forEach(pill => pill.classList.toggle('is-on', pill.dataset.filter === state.filter));
    rows.forEach(row => {
      row.classList.toggle('hide', !visible.has(row));
      row.classList.toggle('is-on', row.dataset.row === state.selected);
    });
    findAll('[data-empty]', page).forEach(node => node.classList.toggle('hide', shownIn(node.closest('.list') || page) > 0));
    findAll('[data-when]', page).forEach(node => node.classList.toggle('hide', node.dataset.when !== state.filter));

    const search = find('[data-search]', page);
    if (search.value !== state.search) search.value = state.search;
    showDrawer(page, state, fresh);
  }

  // The drawer shows the selected row's <template>, even while a filter hides the row.
  function showDrawer(page, state, fresh) {
    if ('inline' in page.dataset) return showInline(page, state);
    const drawer = find('.drawer', page);
    if (!drawer) return; // a list without details
    const row = state.selected == null ? null : rows(page).find(item => item.dataset.row === state.selected);
    drawer.classList.toggle('hide', !row);
    if (!row) {
      delete drawer.dataset.for;
      return;
    }
    if (!fresh && drawer.dataset.for === state.selected) return;
    drawer.dataset.for = state.selected;
    drawer.replaceChildren(find('template', row).content.cloneNode(true));
    fillParts(drawer);
    drawLinks(drawer);
  }

  /* ── Inline rows (Logs) ──
     The open line shows its <template> under it. A line opens only when that shows
     something new: a message cut off by the single-line layout, or a hive dump.
     Wrapped lines show the whole message already, so only their dump is left. */
  function showInline(page, state) {
    findAll('.log-main', page).forEach(main => main.classList.toggle('is-wrapped', !!state.wrap));
    findAll('[data-wrap]', page).forEach(button => button.classList.toggle('is-on', !!state.wrap));
    rows(page).forEach(row => {
      const open = row.dataset.row === state.selected && ('dump' in row.dataset || !state.wrap);
      const more = find(':scope > .more', row);
      row.classList.toggle('is-open', open);
      if (!open) {
        if (more) more.remove();
        return;
      }
      if (more && more.dataset.wrap === String(!!state.wrap)) return;
      if (more) more.remove();
      const fresh = find(':scope > template', row).content.firstElementChild.cloneNode(true);
      fresh.dataset.wrap = String(!!state.wrap);
      if (state.wrap) findAll(':scope > .code', fresh).forEach(node => node.remove());
      row.appendChild(fresh);
    });
    markExpandable(page, state);
  }

  const canExpand = (row, state) => 'dump' in row.dataset
    || (!state.wrap && ('multiline' in row.dataset || (() => {
      const message = find('.msg', row);
      return message.scrollWidth > message.clientWidth;
    })()));

  function markExpandable(page, state) {
    rows(page).forEach(row => {
      if (!row.classList.contains('hide')) row.classList.toggle('can-open', canExpand(row, state));
    });
  }

  let resizeQueued = false;
  window.addEventListener('resize', () => {
    if (resizeQueued || !active) return;
    resizeQueued = true;
    requestAnimationFrame(() => {
      resizeQueued = false;
      const page = find('[data-inline]', pane(active));
      if (page) markExpandable(page, listState(active));
    });
  });

  /* ── Older lines (Logs) ──
     [data-more=url] marks where older lines load from. Once it nears the screen its
     page is fetched and appended; pages overlap as lines arrive (offsets shift), so
     lines already loaded are dropped. Identical lines are counted, so repeats in the
     same second survive. */
  const PRELOAD_MARGIN = '0px 0px 600px 0px';
  let moreObserver = null;
  let moreBusy = false;

  function watchMore() {
    if (!('IntersectionObserver' in window)) return;
    if (!moreObserver) {
      moreObserver = new IntersectionObserver(entries => {
        const entry = entries.find(item => item.isIntersecting);
        if (entry) loadMore(entry.target);
      }, { rootMargin: PRELOAD_MARGIN });
    }
    moreObserver.disconnect();
    const marker = active && find('[data-more]', pane(active));
    if (marker && !marker.dataset.failed) moreObserver.observe(marker);
  }

  const lineCounts = list => {
    const counts = new Map();
    list.forEach(row => counts.set(row.dataset.key, (counts.get(row.dataset.key) || 0) + 1));
    return counts;
  };

  // Lines of incoming not already in have (each loaded copy cancels one incoming copy).
  function unseen(have, incoming) {
    const seen = lineCounts(have);
    return incoming.filter(row => {
      const count = seen.get(row.dataset.key);
      if (!count) return true;
      seen.set(row.dataset.key, count - 1);
      return false;
    });
  }

  function placeLines(page, list) {
    const box = find('[data-lines]', page);
    if (page.dataset.sort) list.sort((a, b) => (a.dataset.ts < b.dataset.ts) - (a.dataset.ts > b.dataset.ts));
    box.prepend(...list);
    numberLines(page);
  }

  // Identical lines share a key (data-key); their row ids count them, so each opens on its own.
  function numberLines(page) {
    const seen = new Map();
    rows(page).forEach(row => {
      const n = seen.get(row.dataset.key) || 0;
      seen.set(row.dataset.key, n + 1);
      row.dataset.row = row.dataset.key + '.' + n;
    });
  }

  function setMoreStatus(page, content) {
    const status = find('[data-more-status]', page);
    if (status) status.replaceChildren(...[].concat(content));
  }

  async function loadMore(marker) {
    const section = marker.closest('.pane');
    const page = marker.closest('[data-list]');
    if (moreBusy || !section || !page) return;
    const tab = section.id.slice('tab-'.length);
    const scope = page.dataset.logScope;
    moreBusy = true;
    setMoreStatus(page, 'Loading older lines…');
    try {
      const response = await fetch(marker.dataset.more, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
      if (!response.ok) throw new Error(response.status + ' ' + response.statusText);
      const html = await response.text();
      if (!page.isConnected || page.dataset.logScope !== scope) return;
      const incoming = document.createElement('template');
      incoming.innerHTML = html;
      const parts = Array.from(incoming.content.children); // a fragment has no :scope
      const have = rows(page);
      placeLines(page, have.concat(unseen(have, parts.filter(node => 'row' in node.dataset))));
      const next = parts.find(node => 'more' in node.dataset);
      if (next) marker.replaceWith(next);
      else marker.remove();
      setMoreStatus(page, next ? 'Scroll for older lines' : 'Start of log');
      applyList(page, listState(tab));
    } catch (error) {
      if (!page.isConnected) return;
      marker.dataset.failed = '1';
      const retry = document.createElement('button');
      retry.className = 'btn';
      retry.dataset.moreRetry = '';
      retry.appendChild(document.createElement('span')).textContent = 'Retry';
      const message = Object.assign(document.createElement('span'), { className: 'danger', textContent: 'Could not load older lines.' });
      setMoreStatus(page, [message, ' ', retry]);
    } finally {
      moreBusy = false;
    }
    watchMore();
  }

  /* A refresh of the same channel and date keeps the older lines already loaded
     and where they load from; only lines that arrived since are added on top. */
  function keepLoadedLines(target, previous) {
    const page = find('[data-log-scope]', target);
    if (!previous || !page || previous.scope !== page.dataset.logScope) return;
    const fresh = rows(page);
    placeLines(page, fresh.concat(unseen(fresh, previous.rows)));
    const marker = find('[data-more]', page);
    if (marker && previous.marker) marker.replaceWith(previous.marker);
    else if (marker && !previous.marker) marker.remove();
    if (!previous.marker) setMoreStatus(page, previous.status);
  }

  function loadedLines(target) {
    const page = find('[data-log-scope]', target);
    if (!page) return null;
    const marker = find('[data-more]', page);
    if (marker) delete marker.dataset.failed;
    return {
      scope: page.dataset.logScope,
      rows: rows(page),
      marker,
      status: Array.from(find('[data-more-status]', page).childNodes),
    };
  }

  /* ── Relation diagrams ([data-links]: anchor pairs as JSON, drawn left to right) ──
     Curves run from the right edge of one [data-anchor] row to the left edge of the
     other; they are drawn after layout and again on resize. */
  const LINK_DOT_RADIUS = 2.5;
  const svgNode = (name, attrs) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', name);
    Object.keys(attrs).forEach(key => node.setAttribute(key, attrs[key]));
    return node;
  };

  function drawLinks(root) {
    findAll('[data-links]', root).forEach(diagram => {
      if (!diagram.isConnected) return;
      const origin = diagram.getBoundingClientRect();
      const anchors = new Map(findAll('[data-anchor]', diagram).map(node => [node.dataset.anchor, node]));
      find('svg', diagram).replaceChildren(...JSON.parse(diagram.dataset.links).flatMap(([fromKey, toKey]) => {
        const from = anchors.get(fromKey);
        const to = anchors.get(toKey);
        if (!from || !to) return [];
        const a = from.getBoundingClientRect();
        const b = to.getBoundingClientRect();
        const x1 = a.right - origin.left;
        const y1 = a.top + a.height / 2 - origin.top;
        const x2 = b.left - origin.left;
        const y2 = b.top + b.height / 2 - origin.top;
        const middle = (x1 + x2) / 2;
        return [
          svgNode('path', { d: 'M' + x1 + ' ' + y1 + 'C' + middle + ' ' + y1 + ' ' + middle + ' ' + y2 + ' ' + x2 + ' ' + y2 }),
          svgNode('circle', { cx: x1, cy: y1, r: LINK_DOT_RADIUS }),
          svgNode('circle', { cx: x2, cy: y2, r: LINK_DOT_RADIUS }),
        ];
      }));
    });
  }

  window.addEventListener('resize', () => {
    if (active) drawLinks(pane(active));
  });

  /* ── Fragments fetched on demand (file sources, job events) ──
     Each URL is fetched once per page view; a failed fetch is retried next time. */
  const parts = new Map(); // url => Promise<html>

  function part(url) {
    if (!parts.has(url)) {
      parts.set(url, fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
        .then(response => {
          if (!response.ok) throw new Error(response.status + ' ' + response.statusText);
          return response.text();
        })
        .catch(error => {
          parts.delete(url);
          throw Object.assign(error, { url });
        }));
    }
    return parts.get(url);
  }

  function fillParts(root) {
    findAll('[data-load]', root).forEach(async node => {
      try {
        node.innerHTML = await part(node.dataset.load);
      } catch (error) {
        node.replaceChildren(failedNode(error.message || 'Could not reach the server.', error.url || ''));
      }
    });
  }

  function modal() {
    let dialog = find('#modal');
    if (!dialog) {
      dialog = Object.assign(document.createElement('dialog'), { id: 'modal', className: 'modal' });
      dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
      });
      document.body.appendChild(dialog);
    }
    return dialog;
  }

  const modalOpen = () => !!(find('#modal') && find('#modal').open);

  async function openModal(url) {
    const dialog = modal();
    const body = Object.assign(document.createElement('div'), { className: 'modal-in' });
    body.appendChild(Object.assign(document.createElement('div'), { className: 'empty', textContent: 'Loading…' }));
    dialog.replaceChildren(body);
    if (!dialog.open) dialog.showModal();
    try {
      dialog.innerHTML = await part(url);
    } catch (error) {
      body.replaceChildren(failedNode(error.message || 'Could not reach the server.', error.url || ''));
    }
  }

  const rows = page => findAll('[data-row]', page);

  function listOf(node) {
    const page = node.closest('[data-list]');
    const section = page && page.closest('.pane');
    return page && section ? { page, state: listState(section.id.slice('tab-'.length)) } : null;
  }

  /* ── Clipboard ──
     The async clipboard API needs a secure context; plain http falls back to execCommand. */
  function copy(text, button) {
    const done = () => {
      if (button.dataset.busy) return;
      const original = Array.from(button.childNodes);
      button.dataset.busy = '1';
      button.classList.add('is-done');
      button.replaceChildren(icon('check'));
      setTimeout(() => {
        button.replaceChildren(...original);
        button.classList.remove('is-done');
        delete button.dataset.busy;
      }, COPIED_MS);
    };
    const legacy = () => {
      const area = Object.assign(document.createElement('textarea'), { className: 'offscreen', readOnly: true, value: text });
      document.body.appendChild(area);
      area.select();
      let copied = false;
      try {
        copied = document.execCommand('copy');
      } catch (e) {
        copied = false;
      }
      area.remove();
      if (copied) done();
    };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, legacy);
    else legacy();
  }

  /* ── A tab's own query (Logs: the rail picks channel and date) ── */
  function pickTabQuery(query) {
    tabQueries.set(active, query);
    listState(active).selected = null;
    scrollTo(0, 0);
    load(active, false);
  }

  /* ── Links between tabs: the dashboard opens Queues on a state ── */
  function goTo(tab, filter) {
    if (filter) Object.assign(listState(tab), { filter, selected: null });
    show(tab);
  }

  /* ── Expandable sections ([data-expand] toggles every [data-clip] of its tab; kept across refreshes) ── */
  const expanded = new Set();

  function setExpanded(tab, open) {
    if (open) expanded.add(tab);
    else expanded.delete(tab);
    applyExpanded(pane(tab), open);
  }

  function applyExpanded(root, open) {
    findAll('[data-clip]', root).forEach(node => node.classList.toggle('is-clipped', !open));
    findAll('[data-expand] span', root).forEach(label => {
      label.textContent = open ? 'Show less' : 'Show all';
    });
  }

  /* ── Trees (Hive): branches fold, a search shows matching leaves flat with full paths ──
     State is kept per tab: closed branch paths, the collapse-all toggle and the search. */
  const trees = new Map();
  const treeState = tab => {
    if (!trees.has(tab)) trees.set(tab, { closed: new Set(), collapsed: false, search: '' });
    return trees.get(tab);
  };
  const treeOf = node => {
    const section = node.closest('.pane');
    return section ? { root: find('[data-tree]', section), state: treeState(section.id.slice('tab-'.length)) } : null;
  };

  // Nodes come parents first, so a closed branch hides every following node deeper than itself.
  function applyTree(root, state) {
    const needle = state.search.trim().toLowerCase();
    let foldedBelow = Infinity;
    let shown = 0;
    findAll('[data-node]', root).forEach(node => {
      const depth = Number(node.dataset.depth);
      const branch = 'branch' in node.dataset;
      let visible;
      if (needle) {
        visible = !branch && node.dataset.text.includes(needle);
      } else {
        if (depth <= foldedBelow) foldedBelow = Infinity; // back out of the folded branch
        visible = foldedBelow === Infinity;
        if (visible && branch && state.closed.has(node.dataset.node)) foldedBelow = depth;
      }
      node.classList.toggle('hide', !visible);
      if (!visible) return;
      shown++;
      if (branch) {
        find('.c', node).textContent = state.closed.has(node.dataset.node) ? '▸' : '▾';
      } else {
        find('b', node).textContent = needle ? node.dataset.full : node.dataset.key;
        node.style.setProperty('--depth', needle ? 0 : depth);
      }
    });
    find('[data-empty]', root).classList.toggle('hide', shown > 0);
    const section = root.closest('.pane');
    const search = find('[data-tree-search]', section);
    if (search.value !== state.search) search.value = state.search;
    find('[data-tree-toggle] span', section).textContent = state.collapsed ? 'Expand all' : 'Collapse all';
  }

  function toggleTree(tree) {
    const { root, state } = tree;
    state.collapsed = !state.collapsed;
    state.closed.clear();
    if (state.collapsed) findAll('[data-branch]', root).forEach(node => state.closed.add(node.dataset.node));
    applyTree(root, state);
  }

  /* ── Clicks ── */
  document.addEventListener('click', event => {
    const target = event.target;

    // Opens another row's drawer (Database: a related table), leaving a filter that hides that row.
    const select = target.closest('[data-select]');
    if (select) {
      const list = listOf(select);
      if (!list) return;
      const id = select.dataset.select;
      const row = rows(list.page).find(item => item.dataset.row === id);
      if (row && !row.dataset.keys.split(' ').includes(list.state.filter)) list.state.filter = 'all';
      list.state.selected = id;
      return applyList(list.page, list.state);
    }

    const button = target.closest('button');

    if (button) {
      if (button.matches('.nav-tab')) return show(button.dataset.tab);
      if ('copy' in button.dataset) return copy(button.dataset.copy, button);
      if ('retry' in button.dataset) return load(active, false);
      if ('range' in button.dataset) return setRange(button.dataset.range);
      if ('modal' in button.dataset) return openModal(button.dataset.modal);
      if ('goto' in button.dataset) return goTo(button.dataset.goto, button.dataset.gotoFilter);
      if ('expand' in button.dataset) return setExpanded(active, !expanded.has(active));
      if ('close' in button.dataset && button.closest('#modal')) return find('#modal').close();
      if ('treeToggle' in button.dataset) return toggleTree(treeOf(button));
      if ('tabQuery' in button.dataset) return pickTabQuery(button.dataset.tabQuery);
      if ('copyShown' in button.dataset) {
        const page = button.closest('[data-list]');
        return copy(rows(page).filter(row => !row.classList.contains('hide')).map(row => find('.r [data-copy]', row).dataset.copy).join('\n'), button);
      }
      if ('moreRetry' in button.dataset) {
        const marker = find('[data-more]', button.closest('[data-list]'));
        if (marker) {
          delete marker.dataset.failed;
          loadMore(marker);
        }
        return;
      }
      if ('dismiss' in button.dataset) {
        store.set('tl-public-ack', '1');
        return find('#' + button.dataset.dismiss).remove();
      }
      const list = listOf(button);
      if (!list) return;
      if ('wrap' in button.dataset) list.state.wrap = !list.state.wrap;
      else if ('close' in button.dataset) list.state.selected = null;
      else if ('filter' in button.dataset) {
        // Switching lists closes the drawer; a filter of one list keeps it.
        if ('views' in list.page.dataset) list.state.selected = null;
        list.state.filter = button.dataset.filter;
      }
      else return;
      return applyList(list.page, list.state);
    }

    // A queue lane searches for its queue; clicking it again clears the search.
    const searchToggle = target.closest('[data-search-toggle]');
    if (searchToggle) {
      const list = listOf(searchToggle);
      if (!list) return;
      list.state.search = list.state.search === searchToggle.dataset.searchToggle ? '' : searchToggle.dataset.searchToggle;
      return applyList(list.page, list.state);
    }

    const branch = target.closest('[data-branch]');
    if (branch) {
      const tree = treeOf(branch);
      const path = branch.dataset.node;
      if (tree.state.closed.has(path)) tree.state.closed.delete(path);
      else tree.state.closed.add(path);
      return applyTree(tree.root, tree.state);
    }

    if (target.closest('.more')) return; // the open part of an inline row
    const row = target.closest('[data-row]');
    const list = row && find(':scope > template', row) && listOf(row);
    if (!list) return;
    if ('inline' in list.page.dataset && list.state.selected !== row.dataset.row && !canExpand(row, list.state)) return;
    list.state.selected = list.state.selected === row.dataset.row ? null : row.dataset.row;
    applyList(list.page, list.state);
  });

  document.addEventListener('input', event => {
    if ('treeSearch' in event.target.dataset) {
      const tree = treeOf(event.target);
      tree.state.search = event.target.value;
      return applyTree(tree.root, tree.state);
    }
    if (!('search' in event.target.dataset)) return;
    const list = listOf(event.target);
    if (!list) return;
    list.state.search = event.target.value;
    applyList(list.page, list.state);
  });

  // Escape closes the open drawer; an open modal handles Escape itself.
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || isTyping(event.target) || modalOpen()) return;
    const close = find('.pane.is-on .drawer:not(.hide) [data-close]');
    if (close) close.click();
  });

  /* ── Prefetch: a pointer resting on a nav button, or a touch, fetches its tab ── */
  let prefetchTimer = null;
  document.addEventListener('pointerover', event => {
    const button = event.target.closest && event.target.closest('.nav-tab');
    if (!button || button.contains(event.relatedTarget)) return;
    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(() => prefetch(button.dataset.tab), PREFETCH_DELAY_MS);
  });
  document.addEventListener('pointerout', event => {
    const button = event.target.closest && event.target.closest('.nav-tab');
    if (button && !button.contains(event.relatedTarget)) clearTimeout(prefetchTimer);
  });
  document.addEventListener('touchstart', event => {
    const button = event.target.closest && event.target.closest('.nav-tab');
    if (button) prefetch(button.dataset.tab);
  }, { passive: true });

  /* ── Range bar (history tabs) ── */
  function setRange(value) {
    range = value;
    store.set('tl-range', value);
    findAll('[data-range]').forEach(pill => pill.classList.toggle('is-on', pill.dataset.range === value));
    config.history.forEach(tab => fetched.delete(tab));
    load(active, false);
  }

  /* ── Refresh: refetch the open tab and the nav badges; content stays until new HTML arrives ── */
  async function refresh() {
    fetched.clear();
    load(active, false);
    try {
      const response = await fetch(config.base + '/summary', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const summary = await response.json();
      setBadge('q-badge', summary.queues.failed);
      setBadge('m-badge', summary.migrations.pending);
      setBadge('rl-badge', summary.ratelimit.limited);
      setBadge('pl-badge', summary.plugins.problems);
      setBadge('mx-badge', summary.mutex.expired);
      setBadge('sy-badge', summary.security.locked);
    } catch (e) {
      // Badges keep their last values.
    }
  }

  // Auto refresh pauses while the page is hidden or a field has focus.
  let autoTimer = null;
  function setAutoRefresh(seconds) {
    clearInterval(autoTimer);
    store.set('tl-auto', seconds);
    if (seconds <= 0) return;
    autoTimer = setInterval(() => {
      if (!document.hidden && !modalOpen() && !isTyping(document.activeElement)) refresh();
    }, seconds * 1000);
  }

  /* ── Theme ── */
  function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    store.set('tl-theme', theme);
    find('#theme-btn').replaceChildren(icon(theme === 'dark' ? 'sun' : 'moon'));
  }

  /* ── Boot ── */
  document.addEventListener('DOMContentLoaded', () => {
    setTheme(store.get('tl-theme', 'dark'));
    find('#theme-btn').addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));
    find('#refresh-btn').addEventListener('click', refresh);

    const autoSelect = find('#auto-refresh');
    const saved = Number(store.get('tl-auto', 0)) || 0;
    const seconds = AUTO_REFRESH_OPTIONS.includes(saved) ? saved : 0;
    autoSelect.value = String(seconds);
    setAutoRefresh(seconds);
    autoSelect.addEventListener('change', () => setAutoRefresh(Number(autoSelect.value)));

    if (store.get('tl-public-ack') === '1') {
      const note = find('#public-note');
      if (note) note.remove();
    }

    // The server rendered the tab named in the URL; without one, reopen the last tab.
    const rendered = find('.nav-tab.is-on').dataset.tab;
    const named = new URL(location.href).searchParams.get('tab');
    const remembered = store.get('tl-tab', rendered);
    active = rendered;
    findAll('[data-range]').forEach(pill => pill.classList.toggle('is-on', pill.dataset.range === range));
    find('#rangebar').classList.toggle('hide', !config.history.includes(rendered));

    const page = find('.pane.is-on [data-list]');
    if (page) applyList(page, listState(rendered), true);
    const tree = find('.pane.is-on [data-tree]');
    if (tree) applyTree(tree, treeState(rendered));
    watchMore();
    fetched.set(rendered, { at: Date.now(), request: Promise.resolve(find('.pane.is-on').innerHTML) });

    if (!named && remembered !== rendered && find('.nav-tab[data-tab="' + remembered + '"]')) show(remembered);
    else if (config.history.includes(rendered) && range !== '24h') load(rendered, false);
  });
})();
