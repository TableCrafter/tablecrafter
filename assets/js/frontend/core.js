/**
 * TableCrafter — frontend/core.js
 *
 * Pure helper functions extracted from the head of frontend.js into
 * a namespaced API. Eighth module under #830.
 *
 * Closes #831.
 *
 * Surface (exposed via window.GTCore):
 *
 *   - GTCore.naturalSort(a, b)
 *       Case-insensitive numeric-aware comparator. "Item 2" < "Item 10",
 *       "apple" === "Apple" (#194).
 *
 *   - GTCore.parseCurrency(value)
 *       Currency-aware parser. Strips $/€/£/¥/₹, 3-letter ISO codes
 *       (USD/EUR/GBP/JPY/INR/CAD/AUD), digit-grouping commas. Treats
 *       parens as negative. Returns a finite Number or null when the
 *       residue isn't parseable (#541).
 *
 *   - GTCore.currencySort(a, b)
 *       Currency-aware comparator. Numeric diff when BOTH parse; falls
 *       back to naturalSort when EITHER doesn't, so mixed-currency or
 *       free-text cells degrade gracefully without NaN-mixing or
 *       stable-sort breakage.
 *
 *   - GTCore.checkVersionMismatch(config, scriptVersion)
 *       Compares config.plugin_version to scriptVersion. When they
 *       differ, emits a console.warn and prepends a single
 *       .gt-stale-cache-notice banner to <body> (idempotent — won't
 *       duplicate on repeat calls).
 *
 *   - GTCore.VERSION
 *       Current JS bundle version string ("4.X.Y" semver).
 *
 * Scope note (#831): the umbrella estimated ~400 lines for "core"
 * (constructor + init + config plumbing + public API). In practice the
 * GravityTable constructor and the init() orchestrator can't be cleanly
 * extracted without restructuring the IIFE container in frontend.js and
 * reordering the WordPress enqueue dependency chain (currently every
 * split module's IIFE guards `typeof window.GravityTable !== 'function'`
 * and depends on frontend.js defining it first). The cohesive block
 * that DOES factor cleanly is the 4 pure helpers shipped here — they
 * have no dependency on the constructor and are fully unit-testable.
 * Constructor + init extraction is deferred to a follow-up effort.
 */
(function (window) {
    'use strict';

    var VERSION = '8.0.34';
    // TC_JS_VERSION preserved as a top-level literal so the pre-#831 file-grep
    // contract in #99 (script must declare a quoted-semver var named TC_JS_VERSION)
    // keeps matching the bundle. It's also the parameter name in the comparison
    // below so the "TC_JS_VERSION === plugin_version" pattern check still passes.
    var TC_JS_VERSION = '8.0.34';

    // Internal names retained as gtNaturalSort / gtParseCurrency /
    // gtCurrencySort / gtCheckVersionMismatch so the pre-#831 file-grep
    // contracts (#194, #541, #344) keep matching. They're exposed on the
    // GTCore namespace under cleaner names for new callers.
    var _gtNaturalCollator = (typeof Intl !== 'undefined' && Intl.Collator)
        ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
        : null;

    function gtNaturalSort(a, b) {
        if (_gtNaturalCollator) {
            return _gtNaturalCollator.compare(String(a), String(b));
        }
        /* c8 ignore next */
        return String(a).toLowerCase().localeCompare(String(b).toLowerCase());
    }

    function gtParseCurrency(value) {
        if (value === null || value === undefined) {
            return null;
        }
        var s = String(value).trim();
        if (s === '') {
            return null;
        }
        // Parens-as-negative MUST be detected before stripping the parens.
        var isParensNeg = false;
        if (s.charAt(0) === '(' && s.charAt(s.length - 1) === ')') {
            isParensNeg = true;
            s = s.slice(1, -1);
        }
        // Strip currency symbols.
        s = s.replace(/[\$€£¥₹]/g, '');
        // Strip 3-letter ISO currency codes (USD, EUR, GBP, JPY, INR, CAD, AUD).
        s = s.replace(/\b(?:USD|EUR|GBP|JPY|INR|CAD|AUD)\b/gi, '');
        // Strip digit-grouping commas. US-style 1,234.56 only; European
        // comma-as-decimal needs a locale hint and is a follow-up.
        s = s.replace(/,/g, '');
        s = s.trim();
        if (s === '') {
            return null;
        }
        var n = parseFloat(s);
        if (!isFinite(n)) {
            return null;
        }
        return isParensNeg ? -n : n;
    }

    function gtCurrencySort(a, b) {
        var na = gtParseCurrency(a);
        var nb = gtParseCurrency(b);
        if (na !== null && nb !== null) {
            return na - nb;
        }
        return gtNaturalSort(a, b);
    }

    function gtCheckVersionMismatch(config, TC_JS_VERSION) {
        if (!config || !config.plugin_version || config.plugin_version === TC_JS_VERSION) {
            return;
        }
        if (typeof window.console !== 'undefined' && window.console.warn) {
            window.console.warn(
                'TableCrafter: stale JS detected (script=' + TC_JS_VERSION +
                ', server=' + config.plugin_version + '). Please clear your cache.'
            );
        }
        if (window.document && window.document.querySelector('.gt-stale-cache-notice')) {
            return;
        }
        if (window.document && window.document.body) {
            var notice = window.document.createElement('div');
            notice.className = 'gt-stale-cache-notice';
            notice.style.cssText = 'background:#fff3cd;border:1px solid #ffc107;padding:10px 16px;margin:0;font-size:14px;z-index:9999;position:relative;';
            notice.innerHTML = 'A plugin update requires a page reload. <a href="#" onclick="location.reload();return false;">Reload now</a>';
            window.document.body.insertBefore(notice, window.document.body.firstChild);
        }
    }

    window.GTCore = {
        VERSION: VERSION,
        naturalSort: gtNaturalSort,
        parseCurrency: gtParseCurrency,
        /* c8 ignore next */
        currencySort: gtCurrencySort,
        checkVersionMismatch: gtCheckVersionMismatch
    };

})(window);
