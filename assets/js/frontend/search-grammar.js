/**
 * TableCrafter — frontend/search-grammar.js
 *
 * #2278 Phase 1 — advanced global search grammar (client-side only).
 *
 * Ports the query tokenizer and AST evaluator from tablecrafter.js
 * (_tokenizeQuery / _evalQueryAst) into a frontend module attached to
 * GravityTable.prototype. Covers: implicit AND, OR (case-insensitive),
 * -negation, "quoted phrases", field:value with column-label resolution,
 * comparison operators (> >= < <=), wildcards (* ?), regex.
 *
 * Non-SSP wire-in: when the global search term contains grammar
 * operators the main search path (load-entries.js) calls
 * grammarFilterEntries() to evaluate the AST client-side over the
 * loaded row set; server-side LIKE search is suppressed so the grammar
 * filter operates over the full (unfiltered) page. Plain queries fall
 * through to the existing behavior byte-identical.
 *
 * Fuzzy mode: when enable_fuzzy_search is set and the AST contains any
 * `field`, `not`, or `or` node, grammar evaluation wins over fuzzy.
 * Collect highlight terms via _getGrammarHighlightTerms() — returns []
 * when fuzzy-disable nodes are present (per spec, suppress highlighting
 * for not/comparison/or nodes).
 *
 * Field resolution: matched against col.label (case-insensitive,
 * whitespace collapsed to _) and col.field_id. Falls back to raw key
 * lookup in the row object.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   _hasGrammarOperators(query)
 *   parseSearchGrammarQuery(input)         — returns AST root
 *   _evalGrammarAst(node, row)
 *   _resolveColumnValue(row, fieldName)
 *   _grammarAstHasFuzzyDisableNode(ast)
 *   _getGrammarHighlightTerms(ast)
 *   grammarFilterEntries(entries, query)
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    // ── Closure-private helpers ─────────────────────────────────────────

    /**
     * Convert a glob-style wildcard pattern (* ?) into a RegExp.
     * Anchored to full string; case-insensitive.
     */
    function wildcardToRegex(pattern) {
        var escaped = pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&')
            .replace(/\*/g, '.*')
            .replace(/\?/g, '.');
        return new RegExp('^' + escaped + '$', 'i');
    }

    /**
     * Evaluate a resolved field value against the operator/value from
     * a `field` AST node.  Ported from tablecrafter.js _evalFieldNode.
     */
    function evalFieldNode(node, raw) {
        var op    = node.op;
        var value = node.value;

        if (op === 'regex') {
            try {
                var m  = value.match(/^\/(.*)\/([gimsuy]*)$/);
                var re = m ? new RegExp(m[1], m[2]) : new RegExp(value, 'i');
                return re.test(String(raw == null ? '' : raw));
            } catch (_) {
                return String(raw == null ? '' : raw).toLowerCase().indexOf(value.toLowerCase()) !== -1;
            }
        }

        if (op === 'gt' || op === 'gte' || op === 'lt' || op === 'lte') {
            var num = parseFloat(raw);
            var cmp = parseFloat(value);
            if (isNaN(num) || isNaN(cmp)) { return false; }
            if (op === 'gt')  { return num > cmp; }
            if (op === 'gte') { return num >= cmp; }
            if (op === 'lt')  { return num < cmp; }
            /* op === 'lte' */ return num <= cmp;
        }

        var cellStr = String(raw == null ? '' : raw);
        var valStr  = String(value);

        if (op === 'eq') {
            if (valStr.indexOf('*') !== -1 || valStr.indexOf('?') !== -1) {
                return wildcardToRegex(valStr).test(cellStr);
            }
            return cellStr.toLowerCase().indexOf(valStr.toLowerCase()) !== -1;
        }

        return cellStr.toLowerCase().indexOf(valStr.toLowerCase()) !== -1;
    }

    /**
     * Read a double-quoted string starting at startIdx.
     * Returns { value, next } where next is the index after the
     * closing quote (or end-of-string when unclosed).
     */
    function readQuoted(s, startIdx) {
        var end = s.indexOf('"', startIdx + 1);
        if (end === -1) {
            return { value: s.slice(startIdx + 1), next: s.length };
        }
        return { value: s.slice(startIdx + 1, end), next: end + 1 };
    }

    /**
     * Tokenize a search query string.
     * Ported from tablecrafter.js _tokenizeQuery.
     *
     * Token shapes:
     *   { type: 'not' }
     *   { type: 'or' }
     *   { type: 'phrase', value: string }
     *   { type: 'term',   value: string }
     *   { type: 'field',  field: string, op: string, value: string }
     */
    function tokenizeQuery(s) {
        var tokens = [];
        var i      = 0;

        while (i < s.length) {
            var ch = s[i];

            // Skip whitespace
            if (/\s/.test(ch)) { i++; continue; }

            // Negation prefix: - immediately followed by non-whitespace
            if (ch === '-' && i + 1 < s.length && !/\s/.test(s[i + 1])) {
                tokens.push({ type: 'not' });
                i++;
                continue;
            }

            // Quoted phrase
            if (ch === '"') {
                var q = readQuoted(s, i);
                tokens.push({ type: 'phrase', value: q.value });
                i = q.next;
                continue;
            }

            // Read a bare word (stops at whitespace, quote, colon)
            var wordStart = i;
            while (i < s.length && !/[\s":]/.test(s[i])) { i++; }
            var word = s.slice(wordStart, i);

            // OR keyword (case-insensitive)
            if (word.toUpperCase() === 'OR') {
                tokens.push({ type: 'or' });
                continue;
            }

            // field: prefix
            if (i < s.length && s[i] === ':') {
                i++; // consume colon
                var fieldValue = '';
                var op = 'eq';

                // Comparison operators immediately after colon
                if (i < s.length) {
                    if (s[i] === '>' && i + 1 < s.length && s[i + 1] === '=') { op = 'gte'; i += 2; }
                    else if (s[i] === '<' && i + 1 < s.length && s[i + 1] === '=') { op = 'lte'; i += 2; }
                    else if (s[i] === '>') { op = 'gt'; i++; }
                    else if (s[i] === '<') { op = 'lt'; i++; }
                    else if (s[i] === '=') { op = 'eq'; i++; }
                }

                // Quoted value
                if (i < s.length && s[i] === '"') {
                    var qv = readQuoted(s, i);
                    fieldValue = qv.value;
                    i = qv.next;
                } else if (i < s.length && s[i] === '/') {
                    // Regex literal: /pattern/flags
                    op = 'regex';
                    var regexStart = i;
                    i++; // skip opening /
                    while (i < s.length && s[i] !== '/' && s[i] !== ' ') { i++; }
                    if (i < s.length && s[i] === '/') {
                        i++; // skip closing /
                        while (i < s.length && /[gimsuy]/.test(s[i])) { i++; }
                    }
                    fieldValue = s.slice(regexStart, i);
                } else {
                    // Bare value: read to next whitespace
                    var valueStart = i;
                    while (i < s.length && !/\s/.test(s[i])) { i++; }
                    fieldValue = s.slice(valueStart, i);
                }

                tokens.push({ type: 'field', field: word, op: op, value: fieldValue });
                continue;
            }

            // Plain term
            if (word.length > 0) {
                tokens.push({ type: 'term', value: word });
            }
        }

        return tokens;
    }

    /**
     * Consume one logical node from the token stream starting at index i.
     * Returns { node, next } where next is the updated token index.
     */
    function consumeQueryNode(tokens, i) {
        var tok = tokens[i];

        if (tok.type === 'not') {
            if (i + 1 >= tokens.length) {
                return { node: { type: 'term', value: '' }, next: i + 1 };
            }
            var inner = consumeQueryNode(tokens, i + 1);
            return { node: { type: 'not', child: inner.node }, next: inner.next };
        }

        if (tok.type === 'phrase') {
            return { node: { type: 'phrase', value: tok.value }, next: i + 1 };
        }

        if (tok.type === 'field') {
            return {
                node: { type: 'field', field: tok.field, op: tok.op || 'eq', value: tok.value },
                next: i + 1
            };
        }

        // default: term
        return { node: { type: 'term', value: tok.value }, next: i + 1 };
    }

    /**
     * Build an AST from a token stream.
     * Handles implicit AND (juxtaposition) and explicit OR.
     * Returns a root { type: 'and', children } node.
     * Ported from tablecrafter.js parseQuery.
     */
    function buildAst(tokens) {
        var children = [];
        var i = 0;

        while (i < tokens.length) {
            var tok = tokens[i];

            if (tok.type === 'or') {
                var prev = children.pop();
                i++;
                if (i >= tokens.length) {
                    if (prev) { children.push(prev); }
                    break;
                }
                var consumed = consumeQueryNode(tokens, i);
                i = consumed.next;
                var orNode;
                if (prev && prev.type === 'or') {
                    orNode = { type: 'or', children: prev.children.concat([consumed.node]) };
                } else {
                    orNode = { type: 'or', children: [prev || { type: 'term', value: '' }, consumed.node] };
                }
                children.push(orNode);
            } else {
                var c = consumeQueryNode(tokens, i);
                i = c.next;
                children.push(c.node);
            }
        }

        return { type: 'and', children: children };
    }

    /**
     * Normalize a column label for field name matching.
     * Lowercases and collapses whitespace runs to a single underscore.
     */
    function normalizeLabel(s) {
        return String(s == null ? '' : s).toLowerCase().replace(/\s+/g, '_');
    }

    // ── Prototype surface ───────────────────────────────────────────────

    Object.assign(window.GravityTable.prototype, {

        /**
         * Returns true when the query string contains any grammar operator
         * tokens: colon (field:), double-quote (phrase), word-OR, or
         * leading-dash negation (-word).
         *
         * Plain term-only queries return false so existing LIKE-based
         * search runs unchanged.
         */
        _hasGrammarOperators: function (query) {
            if (typeof query !== 'string' || query.trim() === '') { return false; }
            if (query.indexOf('"') !== -1 || query.indexOf(':') !== -1) { return true; }
            if (/\bOR\b/i.test(query)) { return true; }
            // -word: dash not followed by whitespace (negation prefix)
            if (/-[^\s]/.test(query)) { return true; }
            return false;
        },

        /**
         * Tokenize and parse a query string into an AST.
         * Public entry-point for tests and callers that need the AST directly.
         */
        parseSearchGrammarQuery: function (input) {
            var s = String(input == null ? '' : input);
            return buildAst(tokenizeQuery(s));
        },

        /**
         * Resolve a field name token (from field:value) to the raw cell
         * value stored in the row object.
         *
         * Priority order:
         *  1. Column whose label (normalised) matches fieldName
         *  2. Column whose field_id matches fieldName exactly
         *  3. Raw key lookup in the row object (fallback)
         */
        _resolveColumnValue: function (row, fieldName) {
            var cols = this.config && Array.isArray(this.config.columns)
                ? this.config.columns : [];
            var norm = normalizeLabel(fieldName);

            for (var i = 0; i < cols.length; i++) {
                var col = cols[i];
                if (normalizeLabel(col.label) === norm) {
                    return row[col.field_id];
                }
                if (String(col.field_id) === String(fieldName)) {
                    return row[col.field_id];
                }
            }

            // Raw key fallback (allows direct field-id or arbitrary key lookup)
            return row[fieldName];
        },

        /**
         * Evaluate an AST node against a single row object.
         *
         * Ported from tablecrafter.js _evalQueryAst, extended with
         * column-label field resolution via _resolveColumnValue.
         */
        _evalGrammarAst: function (node, row) {
            var self = this;

            switch (node.type) {
                case 'and':
                    return node.children.every(function (c) {
                        return self._evalGrammarAst(c, row);
                    });

                case 'or':
                    return node.children.some(function (c) {
                        return self._evalGrammarAst(c, row);
                    });

                case 'not':
                    return !self._evalGrammarAst(node.child, row);

                case 'phrase': {
                    var needle = node.value.toLowerCase();
                    return Object.values(row).some(function (v) {
                        return v != null && String(v).toLowerCase().indexOf(needle) !== -1;
                    });
                }

                case 'term': {
                    var pattern = node.value;
                    if (pattern.indexOf('*') !== -1 || pattern.indexOf('?') !== -1) {
                        var re = wildcardToRegex(pattern);
                        return Object.values(row).some(function (v) {
                            return v != null && re.test(String(v));
                        });
                    }
                    var termNeedle = pattern.toLowerCase();
                    return Object.values(row).some(function (v) {
                        return v != null && String(v).toLowerCase().indexOf(termNeedle) !== -1;
                    });
                }

                case 'field': {
                    var raw = self._resolveColumnValue(row, node.field);
                    return evalFieldNode(node, raw);
                }

                default:
                    return true;
            }
        },

        /**
         * Returns true when the AST contains any node whose type would
         * disable fuzzy search: 'field', 'not', or 'or'.
         *
         * Used by callers to gate enable_fuzzy_search: if this returns
         * true, grammar evaluation takes precedence over fuzzy matching.
         */
        _grammarAstHasFuzzyDisableNode: function (ast) {
            if (!ast) { return false; }
            var self = this;
            if (ast.type === 'field' || ast.type === 'not' || ast.type === 'or') {
                return true;
            }
            if (Array.isArray(ast.children)) {
                if (ast.children.some(function (c) {
                    return self._grammarAstHasFuzzyDisableNode(c);
                })) { return true; }
            }
            if (ast.child && self._grammarAstHasFuzzyDisableNode(ast.child)) {
                return true;
            }
            return false;
        },

        /**
         * Collect leaf term/phrase values from the AST for DOM highlighting.
         *
         * Returns [] when the AST contains any fuzzy-disable node (field,
         * not, or), because highlighting is suppressed in those cases
         * (per spec: "Suppress highlighting when not, comparison operators,
         * or or nodes are present").
         */
        _getGrammarHighlightTerms: function (ast) {
            if (!ast) { return []; }
            if (this._grammarAstHasFuzzyDisableNode(ast)) { return []; }

            var terms = [];
            (function collect(node) {
                if ((node.type === 'term' || node.type === 'phrase') && node.value) {
                    terms.push(node.value);
                }
                if (Array.isArray(node.children)) { node.children.forEach(collect); }
                if (node.child) { collect(node.child); }
            }(ast));

            return terms;
        },

        /**
         * Filter an entries array by evaluating the grammar AST for query.
         *
         * When query contains no grammar operators the original array
         * reference is returned unchanged so the existing LIKE-based
         * search path is byte-identical for plain queries (regression-safe).
         *
         * When grammar operators are present, the query is parsed into an
         * AST and each entry is evaluated against it. Only matching entries
         * are returned (new array).
         */
        grammarFilterEntries: function (entries, query) {
            if (!Array.isArray(entries) || entries.length === 0) { return entries; }
            if (!this._hasGrammarOperators(query)) { return entries; }

            var ast  = buildAst(tokenizeQuery(String(query)));
            var self = this;

            return entries.filter(function (row) {
                return self._evalGrammarAst(ast, row);
            });
        }

    });

})(window);
