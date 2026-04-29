/**
 * Formula comparisons + IF (slice 3 of #47).
 * Stacked on PR #113 (function library).
 *
 * Adds the comparison operators (>, <, >=, <=, ==, !=) and the IF(cond, then,
 * else) function. Comparison results are numeric (1 / 0) so they compose
 * with arithmetic. IF picks `then` when cond is truthy, `else` otherwise.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'a' }, { field: 'b' }],
    data: []
  });
}

const ev = (formula, row = {}) => makeTable().evaluateFormula(formula, row);

describe('Formula: comparison operators', () => {
  test('>', () => {
    expect(ev('{a} > {b}', { a: 5, b: 3 })).toBe(1);
    expect(ev('{a} > {b}', { a: 3, b: 5 })).toBe(0);
    expect(ev('{a} > {b}', { a: 3, b: 3 })).toBe(0);
  });

  test('<', () => {
    expect(ev('{a} < {b}', { a: 3, b: 5 })).toBe(1);
    expect(ev('{a} < {b}', { a: 5, b: 3 })).toBe(0);
  });

  test('>= and <=', () => {
    expect(ev('{a} >= {b}', { a: 3, b: 3 })).toBe(1);
    expect(ev('{a} >= {b}', { a: 2, b: 3 })).toBe(0);
    expect(ev('{a} <= {b}', { a: 3, b: 3 })).toBe(1);
    expect(ev('{a} <= {b}', { a: 4, b: 3 })).toBe(0);
  });

  test('== and !=', () => {
    expect(ev('{a} == {b}', { a: 3, b: 3 })).toBe(1);
    expect(ev('{a} == {b}', { a: 3, b: 4 })).toBe(0);
    expect(ev('{a} != {b}', { a: 3, b: 4 })).toBe(1);
    expect(ev('{a} != {b}', { a: 3, b: 3 })).toBe(0);
  });

  test('comparisons compose with arithmetic via numeric result', () => {
    // (a > b) * 100 → 100 when true, 0 when false
    expect(ev('({a} > {b}) * 100', { a: 5, b: 3 })).toBe(100);
    expect(ev('({a} > {b}) * 100', { a: 3, b: 5 })).toBe(0);
  });
});

describe('Formula: IF(cond, then, else)', () => {
  test('returns the then branch when cond is truthy', () => {
    expect(ev('IF({a} > {b}, 1, 0)', { a: 5, b: 3 })).toBe(1);
  });

  test('returns the else branch when cond is falsy', () => {
    expect(ev('IF({a} > {b}, 1, 0)', { a: 3, b: 5 })).toBe(0);
  });

  test('branches can be expressions, not just literals', () => {
    expect(ev('IF({a} > {b}, {a} - {b}, {b} - {a})', { a: 8, b: 3 })).toBe(5);
    expect(ev('IF({a} > {b}, {a} - {b}, {b} - {a})', { a: 3, b: 8 })).toBe(5);
  });

  test('nested IFs', () => {
    // IF(a > 0, IF(a > 100, 2, 1), 0)
    expect(ev('IF({a} > 0, IF({a} > 100, 2, 1), 0)', { a: 50  })).toBe(1);
    expect(ev('IF({a} > 0, IF({a} > 100, 2, 1), 0)', { a: 200 })).toBe(2);
    expect(ev('IF({a} > 0, IF({a} > 100, 2, 1), 0)', { a: -1  })).toBe(0);
  });

  test('wrong arity returns null', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    expect(ev('IF({a}, 1)', { a: 1 })).toBeNull();
    expect(ev('IF({a}, 1, 2, 3)', { a: 1 })).toBeNull();
    warnSpy.mockRestore();
  });
});
