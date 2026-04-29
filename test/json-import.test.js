/**
 * JSON import (slice 2 of #60).
 * Stacked on PR #124 (CSV import).
 *
 * Mirrors the parseCSV / importCSV API surface for JSON input. Two accepted
 * shapes: a top-level array of row objects, or a top-level object with a
 * `data` array. Anything else surfaces in `errors` rather than throwing.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 0, name: 'Existing' }]
  });
}

describe('parseJSON', () => {
  test('top-level array of objects → rows', () => {
    const t = makeTable();
    const out = t.parseJSON('[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]');
    expect(out.errors).toEqual([]);
    expect(out.rows).toEqual([
      { id: 1, name: 'Alice' },
      { id: 2, name: 'Bob' }
    ]);
  });

  test('top-level { data: [...] } envelope is unwrapped', () => {
    const t = makeTable();
    const out = t.parseJSON('{"data":[{"id":1}]}');
    expect(out.rows).toEqual([{ id: 1 }]);
  });

  test('accepts a parsed object as well as a string', () => {
    const t = makeTable();
    const out = t.parseJSON([{ id: 1 }, { id: 2 }]);
    expect(out.rows).toEqual([{ id: 1 }, { id: 2 }]);
  });

  test('non-object array entries are dropped with an error', () => {
    const t = makeTable();
    const out = t.parseJSON('[{"id":1}, 5, null, {"id":2}]');
    expect(out.rows).toEqual([{ id: 1 }, { id: 2 }]);
    expect(out.errors).toHaveLength(2);
  });

  test('malformed JSON returns rows: [] with an error rather than throwing', () => {
    const t = makeTable();
    const out = t.parseJSON('{not json');
    expect(out.rows).toEqual([]);
    expect(out.errors).toHaveLength(1);
    expect(out.errors[0].message).toMatch(/json/i);
  });

  test('top-level non-array, non-{data} object returns rows: [] with an error', () => {
    const t = makeTable();
    const out = t.parseJSON('{"foo":"bar"}');
    expect(out.rows).toEqual([]);
    expect(out.errors).toHaveLength(1);
  });

  test('empty input returns { rows: [], errors: [] }', () => {
    const t = makeTable();
    expect(t.parseJSON('').rows).toEqual([]);
    expect(t.parseJSON(null).rows).toEqual([]);
  });
});

describe('importJSON', () => {
  test('replaces this.data by default', () => {
    const t = makeTable();
    t.importJSON('[{"id":1}]');
    expect(t.data).toEqual([{ id: 1 }]);
  });

  test('append: true extends this.data', () => {
    const t = makeTable();
    t.importJSON('[{"id":7}]', { append: true });
    expect(t.data).toEqual([{ id: 0, name: 'Existing' }, { id: 7 }]);
  });

  test('returns { rows, errors } so callers can inspect after import', () => {
    const t = makeTable();
    const result = t.importJSON('[{"id":1}, "bad", {"id":2}]');
    expect(result.rows).toHaveLength(2);
    expect(result.errors).toHaveLength(1);
  });
});
