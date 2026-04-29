/**
 * Memory management foundation (slice 1 of #39).
 *
 * Lands clearCaches() + getMemoryFootprint() helpers. LRU eviction policies,
 * detached-node helpers, and heap-snapshot integration remain queued under
 * #39 follow-ups.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }]
  });
}

describe('clearCaches', () => {
  test('empties the lookup cache', () => {
    const t = makeTable();
    t.lookupCache.set('a', { x: 1 });
    t.lookupCache.set('b', { x: 2 });
    expect(t.lookupCache.size).toBe(2);

    t.clearCaches();
    expect(t.lookupCache.size).toBe(0);
  });

  test('preserves persistent state (data, columns, validationErrors, plugins)', () => {
    const t = makeTable();
    t.validationErrors.set('0_name', ['msg']);
    t._plugins = [{ plugin: { name: 'p' } }];
    const dataBefore = t.data;
    const columnsBefore = t.config.columns;

    t.clearCaches();

    expect(t.data).toBe(dataBefore);
    expect(t.config.columns).toBe(columnsBefore);
    expect(t.validationErrors.size).toBe(1);
    expect(t._plugins).toHaveLength(1);
  });

  test('safe on a freshly-constructed table', () => {
    const t = makeTable();
    expect(() => t.clearCaches()).not.toThrow();
  });

  test('clears optional internal caches when present', () => {
    const t = makeTable();
    t._regexCache = new Map([['a|', /a/]]);
    t._badRegexWarned = new Set(['bad']);
    t._missingI18nKeys = new Set(['toolbar.search']);
    t._formulaWarned = new Set(['1+x']);

    t.clearCaches();

    expect(t._regexCache.size).toBe(0);
    expect(t._badRegexWarned.size).toBe(0);
    expect(t._missingI18nKeys.size).toBe(0);
    expect(t._formulaWarned.size).toBe(0);
  });
});

describe('getMemoryFootprint', () => {
  test('reports row / column counts', () => {
    const t = makeTable();
    const fp = t.getMemoryFootprint();
    expect(fp.rows).toBe(2);
    expect(fp.columns).toBe(2);
  });

  test('reports cache sizes', () => {
    const t = makeTable();
    t.lookupCache.set('a', { x: 1 });
    t.lookupCache.set('b', { x: 2 });
    expect(t.getMemoryFootprint().lookupCacheSize).toBe(2);
  });

  test('row count tracks data mutation', () => {
    const t = makeTable();
    expect(t.getMemoryFootprint().rows).toBe(2);
    t.data.push({ id: 3, name: 'Carol' });
    expect(t.getMemoryFootprint().rows).toBe(3);
  });

  test('safe on a freshly-constructed table', () => {
    const t = makeTable();
    const fp = t.getMemoryFootprint();
    expect(typeof fp.rows).toBe('number');
    expect(typeof fp.columns).toBe('number');
  });
});
