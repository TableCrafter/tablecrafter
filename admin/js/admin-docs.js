/* TableCrafter - Admin Docs Page JS (#1976) */
(function () {
    /* Section icons keyed by id */
    var ICONS = {
        'getting-started':          'dashicons-welcome-learn-more',
        'creating-tables':          'dashicons-editor-table',
        'table-features':           'dashicons-admin-generic',
        'shortcodes':               'dashicons-shortcode',
        'frontend-editing':         'dashicons-edit',
        'bulk-actions':             'dashicons-forms',
        'advanced-filters':         'dashicons-filter',
        'detail-rows':              'dashicons-visibility',
        'drilldown':                'dashicons-search',
        'row-link':                 'dashicons-admin-links',
        'pivot':                    'dashicons-chart-bar',
        'sorting':                  'dashicons-sort',
        'row-display':              'dashicons-screenoptions',
        'cell-types':               'dashicons-grid-view',
        'data-management-tools':    'dashicons-database',
        'data-sources':             'dashicons-cloud',
        'ai-tools':                 'dashicons-superhero',
        'url-filters':              'dashicons-admin-site',
        'print-export':             'dashicons-download',
        'conditional-formatting':   'dashicons-art',
        'lookup-fields':            'dashicons-randomize',
        'customization':            'dashicons-admin-appearance',
        'datecoder-integration':    'dashicons-calendar-alt',
        'mobile-responsiveness':    'dashicons-smartphone',
        'troubleshooting':          'dashicons-sos',
        'permission-troubleshooting': 'dashicons-lock',
        'whats-new':                'dashicons-megaphone',
    };

    var OPEN_DEFAULT = ['getting-started', 'whats-new'];

    function init() {
        buildHero();
        transformSections();
        wireNavLinks();
        scrollSpy();
        openFromHash();
    }

    /* ── Hero ───────────────────────────────────────────────────────────── */
    function buildHero() {
        var wrap = document.querySelector('.wrap');
        var container = document.querySelector('.gt-docs-container');
        if (!wrap || !container) { return; }

        var version = (window.gtDocsData && window.gtDocsData.version) ? window.gtDocsData.version : '';

        var hero = document.createElement('div');
        hero.className = 'gt-docs-hero';
        hero.innerHTML =
            '<span class="dashicons dashicons-book gt-docs-hero-icon"></span>' +
            '<div>' +
              '<h1>TableCrafter Documentation</h1>' +
              '<p>Guides, shortcode reference, feature walkthroughs, and release notes.</p>' +
            '</div>' +
            (version ? '<span class="gt-docs-hero-badge">v' + version + '</span>' : '');

        wrap.insertBefore(hero, container);

        /* Hide the original WP h1 */
        var h1 = wrap.querySelector('h1:not(.gt-docs-hero h1)');
        if (h1 && h1.parentNode === wrap) { h1.style.display = 'none'; }
    }

    /* ── Transform sections into collapsible cards ───────────────────────── */
    function transformSections() {
        document.querySelectorAll('.gt-docs-section').forEach(function (section) {
            var h2 = section.querySelector(':scope > h2');
            if (!h2) { return; }

            var id      = section.id || '';
            var icon    = ICONS[id] || 'dashicons-arrow-right-alt2';
            var isOpen  = OPEN_DEFAULT.indexOf(id) !== -1;

            /* Build header */
            var header = document.createElement('div');
            header.className = 'gt-docs-section-header';
            header.innerHTML =
                '<span class="dashicons ' + icon + ' gt-docs-section-icon"></span>';
            header.appendChild(h2.cloneNode(true));
            header.innerHTML +=
                '<span class="dashicons dashicons-arrow-down-alt2 gt-docs-section-chevron"></span>';

            /* Wrap remaining children in body */
            var body = document.createElement('div');
            body.className = 'gt-docs-section-body';

            /* Remove original h2, collect remaining children */
            h2.remove();
            while (section.firstChild) {
                body.appendChild(section.firstChild);
            }

            section.appendChild(header);
            section.appendChild(body);

            if (isOpen) { section.classList.add('gt-docs-open'); }

            header.addEventListener('click', function () {
                section.classList.toggle('gt-docs-open');
            });
        });
    }

    /* ── Nav link clicks open the target section ─────────────────────────── */
    function wireNavLinks() {
        document.querySelectorAll('.gt-docs-nav a').forEach(function (a) {
            a.addEventListener('click', function () {
                var href = a.getAttribute('href') || '';
                if (!href.startsWith('#')) { return; }
                var target = document.querySelector(href);
                if (target && target.classList.contains('gt-docs-section')) {
                    target.classList.add('gt-docs-open');
                }
            });
        });
    }

    /* ── Scroll spy - highlight active TOC link ──────────────────────────── */
    function scrollSpy() {
        var sections = Array.prototype.slice.call(
            document.querySelectorAll('.gt-docs-section[id]')
        );
        var links    = document.querySelectorAll('.gt-docs-nav a');
        if (!sections.length || !links.length) { return; }

        var contentEl = document.querySelector('.gt-docs-content');
        var scrollEl  = contentEl || window;

        function onScroll() {
            var scrollTop = (contentEl
                ? contentEl.scrollTop
                : (window.scrollY || document.documentElement.scrollTop));
            var offset = 80;
            var active = sections[0];

            sections.forEach(function (s) {
                if (s.offsetTop - offset <= scrollTop) { active = s; }
            });

            links.forEach(function (a) {
                var href = a.getAttribute('href') || '';
                if (href === '#' + (active ? active.id : '')) {
                    a.classList.add('gt-docs-nav-active');
                } else {
                    a.classList.remove('gt-docs-nav-active');
                }
            });
        }

        scrollEl.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ── Open section from URL hash on page load ─────────────────────────── */
    function openFromHash() {
        var hash = window.location.hash;
        if (!hash) { return; }
        var target = document.querySelector(hash);
        if (target && target.classList.contains('gt-docs-section')) {
            target.classList.add('gt-docs-open');
            setTimeout(function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 80);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
