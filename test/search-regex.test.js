/**
 * Search grammar — regex literals (slice 5 of #59).
 * Stacked on PR #90 (wildcards).
 *
 * Adds support for `field:/pattern/` and `field:/pattern/i`. Invalid regex
 * falls back to substring with a single console.warn (per session, per
 * pattern) so a typo doesn't blow up the table.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, sku: 'A-100' },
  { id: 2, sku: 'A-200' },
  { id: 3, sku: 'B-100' },
  { id: 4, sku: 'b-200' },
  { id: 5, sku: 'plain' }
];

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns: [{ field: 'id' }, { field: 'sku' }]
  });
}

describe('parseQuery: regex literals', () => {
  test('field:/pattern/ parses with op regex (case-sensitive by default)', () => {
    const t = makeTable();
    expect(t.parseQuery('sku:/^A-/')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'sku', op: 'regex', value: '^A-', flags: '' }]
    });
  });

  test('field:/pattern/i preserves the i flag', () => {
    const t = makeTable();
    expect(t.parseQuery('sku:/^b-/i')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'sku', op: 'regex', value: '^b-', flags: 'i' }]
    });
  });
});

describe('evaluateQuery: regex literals', () => {
  test('case-sensitive regex distinguishes A vs b', () => {
    const t = makeTable();
    t.setQuery('sku:/^A-/');
    expect(t.getFilteredData().map(r => r.sku).sort()).toEqual(['A-100', 'A-200']);
  });

  test('i flag enables case-insensitive matching', () => {
    const t = makeTable();

    // Case-sensitive: only b-200 matches `^b-`.
    t.setQuery('sku:/^b-/');
    expect(t.getFilteredData().map(r => r.sku).sort()).toEqual(['b-200']);

    // Case-insensitive: B-100 also matches.
    t.setQuery('sku:/^b-/i');
    expect(t.getFilteredData().map(r => r.sku).sort()).toEqual(['B-100', 'b-200']);
  });

  test('non-string cells are skipped (return false)', () => {
    const t = makeTable();
    const ast = t.parseQuery('sku:/^[A-Z]/');
    expect(t.evaluateQuery(ast, { sku: null })).toBe(false);
    expect(t.evaluateQuery(ast, { sku: undefined })).toBe(false);
  });
});

describe('evaluateQuery: invalid regex falls back gracefully', () => {
  test('invalid pattern matches nothing and warns once per session', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const t = makeTable();

    t.setQuery('sku:/[unclosed/');
    const visible = t.getFilteredData();
    expect(visible).toEqual([]);

    // Re-applying the same bad query should not warn a second time.
    const beforeReapply = warnSpy.mock.calls.length;
    t.setQuery('sku:/[unclosed/');
    const afterReapply = warnSpy.mock.calls.length;
    expect(afterReapply).toBe(beforeReapply);

    warnSpy.mockRestore();
  });
});
