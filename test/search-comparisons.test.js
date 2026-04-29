/**
 * Search grammar — comparison operators on field values (slice 3 of #59).
 * Stacked on PR #88 (AST evaluator + setQuery integration).
 *
 * Lands `field:>N`, `field:<N`, `field:>=N`, `field:<=N`, and `field:=value`.
 * Regex literals (`field:/regex/i`) and wildcards (`gold*`, `wo?d`) are still
 * deferred to follow-up PRs.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, age: 18, role: 'guest'  },
  { id: 2, age: 25, role: 'editor' },
  { id: 3, age: 40, role: 'admin'  },
  { id: 4, age: 65, role: 'admin'  }
];
const columns = [
  { field: 'id', label: 'ID' },
  { field: 'age', label: 'Age' },
  { field: 'role', label: 'Role' }
];

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('parseQuery: comparison operators', () => {
  test('field:>N parses with op gt', () => {
    const t = makeTable();
    expect(t.parseQuery('age:>30')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'gt', value: '30' }]
    });
  });

  test('field:<N parses with op lt', () => {
    const t = makeTable();
    expect(t.parseQuery('age:<30')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'lt', value: '30' }]
    });
  });

  test('field:>=N and field:<=N parse with gte / lte', () => {
    const t = makeTable();
    expect(t.parseQuery('age:>=18')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'gte', value: '18' }]
    });
    expect(t.parseQuery('age:<=65')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'lte', value: '65' }]
    });
  });

  test('field:=value parses as a strict-equals match', () => {
    const t = makeTable();
    expect(t.parseQuery('role:=admin')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'role', op: 'eq_strict', value: 'admin' }]
    });
  });
});

describe('evaluateQuery: comparison operators', () => {
  test('age:>30 matches rows where age > 30', () => {
    const t = makeTable();
    const ast = t.parseQuery('age:>30');
    expect(t.evaluateQuery(ast, { age: 40 })).toBe(true);
    expect(t.evaluateQuery(ast, { age: 30 })).toBe(false);
    expect(t.evaluateQuery(ast, { age: 18 })).toBe(false);
  });

  test('age:>=30 includes the boundary', () => {
    const t = makeTable();
    const ast = t.parseQuery('age:>=30');
    expect(t.evaluateQuery(ast, { age: 30 })).toBe(true);
    expect(t.evaluateQuery(ast, { age: 29 })).toBe(false);
  });

  test('age:<=30 includes the boundary', () => {
    const t = makeTable();
    const ast = t.parseQuery('age:<=30');
    expect(t.evaluateQuery(ast, { age: 30 })).toBe(true);
    expect(t.evaluateQuery(ast, { age: 31 })).toBe(false);
  });

  test('role:=admin matches strict equality (not substring)', () => {
    const t = makeTable();
    const ast = t.parseQuery('role:=admin');
    expect(t.evaluateQuery(ast, { role: 'admin' })).toBe(true);
    expect(t.evaluateQuery(ast, { role: 'admins' })).toBe(false);
    expect(t.evaluateQuery(ast, { role: 'super-admin' })).toBe(false);
  });

  test('numeric comparisons are skipped (false) for non-numeric cells', () => {
    const t = makeTable();
    const ast = t.parseQuery('age:>30');
    expect(t.evaluateQuery(ast, { age: 'unknown' })).toBe(false);
    expect(t.evaluateQuery(ast, { age: null })).toBe(false);
  });
});

describe('setQuery integration with comparison operators', () => {
  test('age:>30 narrows to rows where age > 30', () => {
    const t = makeTable();
    t.setQuery('age:>30');
    expect(t.getFilteredData().map(r => r.id).sort()).toEqual([3, 4]);
  });

  test('role:=admin -age:>=65 admins under 65', () => {
    const t = makeTable();
    t.setQuery('role:=admin -age:>=65');
    expect(t.getFilteredData().map(r => r.id)).toEqual([3]);
  });
});
