/**
 * AST evaluator + setQuery integration (slice 2 of #59).
 * Stacked on PR #87 (parseQuery foundation).
 *
 * Wires the parsed AST into a row-level predicate and a public setQuery()
 * method that drives the existing global-search filtering.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'Alice',   status: 'open',     team: 'core'    },
  { id: 2, name: 'Bob',     status: 'closed',   team: 'core'    },
  { id: 3, name: 'Charlie', status: 'open',     team: 'mobile'  },
  { id: 4, name: 'Dana',    status: 'archived', team: 'mobile'  }
];
const columns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' },
  { field: 'status', label: 'Status' },
  { field: 'team', label: 'Team' }
];

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('evaluateQuery: term matching', () => {
  test('bare term matches case-insensitively across all columns', () => {
    const t = makeTable();
    const ast = t.parseQuery('alice');
    expect(t.evaluateQuery(ast, { id: 1, name: 'Alice', status: 'open' })).toBe(true);
    expect(t.evaluateQuery(ast, { id: 2, name: 'Bob', status: 'closed' })).toBe(false);
  });

  test('multiple bare terms are AND-combined', () => {
    const t = makeTable();
    const ast = t.parseQuery('alice open');
    expect(t.evaluateQuery(ast, { name: 'Alice', status: 'open' })).toBe(true);
    expect(t.evaluateQuery(ast, { name: 'Alice', status: 'closed' })).toBe(false);
  });

  test('empty query matches every row', () => {
    const t = makeTable();
    const ast = t.parseQuery('');
    expect(t.evaluateQuery(ast, { name: 'anything' })).toBe(true);
  });
});

describe('evaluateQuery: OR / NOT / phrase / field', () => {
  test('OR matches if either branch matches', () => {
    const t = makeTable();
    const ast = t.parseQuery('alice OR bob');
    expect(t.evaluateQuery(ast, { name: 'Alice' })).toBe(true);
    expect(t.evaluateQuery(ast, { name: 'Bob' })).toBe(true);
    expect(t.evaluateQuery(ast, { name: 'Charlie' })).toBe(false);
  });

  test('-term excludes matching rows', () => {
    const t = makeTable();
    const ast = t.parseQuery('-archived');
    expect(t.evaluateQuery(ast, { status: 'open' })).toBe(true);
    expect(t.evaluateQuery(ast, { status: 'archived' })).toBe(false);
  });

  test('"quoted phrase" matches as a single substring', () => {
    const t = makeTable();
    const ast = t.parseQuery('"foo bar"');
    expect(t.evaluateQuery(ast, { note: 'lots of foo bar here' })).toBe(true);
    expect(t.evaluateQuery(ast, { note: 'foo and bar separately' })).toBe(false);
  });

  test('field:value scopes the match to the named column', () => {
    const t = makeTable();
    const ast = t.parseQuery('status:open');
    expect(t.evaluateQuery(ast, { status: 'open',   name: 'Alice' })).toBe(true);
    expect(t.evaluateQuery(ast, { status: 'closed', name: 'open'  })).toBe(false);
  });

  test('field match is case-insensitive', () => {
    const t = makeTable();
    const ast = t.parseQuery('status:OPEN');
    expect(t.evaluateQuery(ast, { status: 'open' })).toBe(true);
  });
});

describe('setQuery integration with the existing search filter', () => {
  test('setQuery applies the parsed query to filter visible rows', () => {
    const t = makeTable();
    t.setQuery('mobile');
    const visible = t.getFilteredData();
    expect(visible.map(r => r.name).sort()).toEqual(['Charlie', 'Dana']);
  });

  test('setQuery with field-scoped search narrows further', () => {
    const t = makeTable();
    t.setQuery('team:mobile -archived');
    const visible = t.getFilteredData();
    expect(visible.map(r => r.name)).toEqual(['Charlie']);
  });

  test('setQuery("") clears the active query and shows all rows', () => {
    const t = makeTable();
    t.setQuery('alice');
    expect(t.getFilteredData()).toHaveLength(1);
    t.setQuery('');
    expect(t.getFilteredData()).toHaveLength(4);
  });
});
