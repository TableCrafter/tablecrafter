/**
 * Formula function library (slice 2 of #47).
 * Stacked on PR #112 (safe expression evaluator).
 *
 * Adds the small numeric function set: ROUND, FLOOR, CEIL, ABS, MIN, MAX.
 * IF / comparison operators / CONCAT / DATE remain queued for follow-ups.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'a' }, { field: 'b' }, { field: 'c' }],
    data: []
  });
}

describe('Formula functions: ROUND', () => {
  test('ROUND(value) rounds to nearest integer', () => {
    expect(makeTable().evaluateFormula('ROUND({a})', { a: 1.4 })).toBe(1);
    expect(makeTable().evaluateFormula('ROUND({a})', { a: 1.5 })).toBe(2);
    expect(makeTable().evaluateFormula('ROUND({a})', { a: -1.5 })).toBe(-1);
  });

  test('ROUND(value, digits) rounds to N decimal places', () => {
    expect(makeTable().evaluateFormula('ROUND({a}, 2)', { a: 1.005 })).toBeCloseTo(1.01);
    expect(makeTable().evaluateFormula('ROUND({a}, 0)', { a: 3.7 })).toBe(4);
  });

  test('ROUND with arithmetic inside', () => {
    expect(makeTable().evaluateFormula('ROUND({a} / {b}, 2)', { a: 10, b: 3 })).toBeCloseTo(3.33);
  });
});

describe('Formula functions: FLOOR / CEIL / ABS', () => {
  test('FLOOR truncates toward negative infinity', () => {
    expect(makeTable().evaluateFormula('FLOOR({a})', { a: 1.9 })).toBe(1);
    expect(makeTable().evaluateFormula('FLOOR({a})', { a: -1.1 })).toBe(-2);
  });

  test('CEIL rounds toward positive infinity', () => {
    expect(makeTable().evaluateFormula('CEIL({a})', { a: 1.1 })).toBe(2);
    expect(makeTable().evaluateFormula('CEIL({a})', { a: -1.9 })).toBe(-1);
  });

  test('ABS', () => {
    expect(makeTable().evaluateFormula('ABS({a})', { a: -5 })).toBe(5);
    expect(makeTable().evaluateFormula('ABS({a})', { a: 5 })).toBe(5);
    expect(makeTable().evaluateFormula('ABS({a} - {b})', { a: 3, b: 10 })).toBe(7);
  });
});

describe('Formula functions: MIN / MAX (variadic)', () => {
  test('MIN over a small set', () => {
    expect(makeTable().evaluateFormula('MIN({a}, {b}, {c})', { a: 5, b: 2, c: 8 })).toBe(2);
  });

  test('MAX over a small set', () => {
    expect(makeTable().evaluateFormula('MAX({a}, {b}, {c})', { a: 5, b: 2, c: 8 })).toBe(8);
  });

  test('MIN / MAX with arithmetic args', () => {
    expect(makeTable().evaluateFormula('MIN({a} * 2, {b})', { a: 3, b: 7 })).toBe(6);
  });

  test('single-argument MIN / MAX', () => {
    expect(makeTable().evaluateFormula('MIN({a})', { a: 5 })).toBe(5);
  });
});

describe('Formula functions: invalid input', () => {
  test('unknown function returns null', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    expect(makeTable().evaluateFormula('UNKNOWN({a})', { a: 5 })).toBeNull();
    warnSpy.mockRestore();
  });

  test('wrong arity returns null', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    expect(makeTable().evaluateFormula('ABS()', { a: 5 })).toBeNull();
    expect(makeTable().evaluateFormula('ROUND({a}, 2, 3)', { a: 5 })).toBeNull();
    warnSpy.mockRestore();
  });

  test('non-numeric arg returns null', () => {
    expect(makeTable().evaluateFormula('ROUND({a})', { a: 'hello' })).toBeNull();
  });
});
