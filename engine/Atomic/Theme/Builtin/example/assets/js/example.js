/**
 * Atomic Example Showcase — JavaScript
 *
 * Demonstrates:
 *   - window.ExampleData (populated by localize_script)
 *   - Section scroll spy for nav highlighting
 *   - Smooth scroll navigation
 *   - Copy-to-clipboard for nonce values
 */
(function () {
    'use strict';

    var data = window.ExampleData || {};
    console.log('[Atomic Example] localized data:', data);
    console.log('[Atomic Example] ajax_url:', data.ajax_url);
    console.log('[Atomic Example] locale:', data.locale);

    // ── Scroll spy: highlight active nav item ──────────────
    var nav = document.getElementById('showcase-nav');
    var links = nav ? nav.querySelectorAll('a') : [];
    var sections = [];

    links.forEach(function (link) {
        var href = link.getAttribute('href');
        if (href && href.startsWith('#section-')) {
            var el = document.querySelector(href);
            if (el) sections.push({ el: el, link: link });
        }
    });

    function updateActive() {
        var scrollY = window.scrollY + 120;
        var active = null;
        sections.forEach(function (s) {
            if (s.el.offsetTop <= scrollY) active = s.link;
        });
        links.forEach(function (l) { l.style.borderColor = 'transparent'; });
        if (active) active.style.borderColor = 'var(--primary, #6c5ce7)';
    }

    window.addEventListener('scroll', updateActive, { passive: true });
    updateActive();

    // ── Copy nonce to clipboard ────────────────────────────
    document.querySelectorAll('.kv-val').forEach(function (el) {
        var text = el.textContent || '';
        if (text.length === 32 && /^[a-f0-9]{32}$/.test(text)) {
            el.style.cursor = 'pointer';
            el.title = 'Click to copy nonce';
            el.addEventListener('click', function () {
                navigator.clipboard.writeText(text).then(function () {
                    var orig = el.textContent;
                    el.textContent = 'Copied!';
                    el.style.color = 'var(--success, #00b894)';
                    setTimeout(function () {
                        el.textContent = orig;
                        el.style.color = '';
                    }, 1500);
                }).catch(function () {});
            });
        }
    });

    console.log('[Atomic Example] showcase ready — ' + sections.length + ' sections');
})();
