// Formula evaluator — arithmetic + function library (#47)

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'a' }, { field: 'b' }, { field: 'c' }],
    data: [{ a: 2, b: 3, c: 4 }],
    ...extra
  });
}

describe('evaluateFormula: basic arithmetic', () => {
  test('addition', () => {
    expect(makeTable().evaluateFormula('{a} + {b}', { a: 2, b: 3 })).toBe(5);
  });

  test('subtraction, multiplication, division', () => {
    const t = makeTable();
    expect(t.evaluateFormula('{a} - {b}', { a: 10, b: 3 })).toBe(7);
    expect(t.evaluateFormula('{a} * {b}', { a: 4, b: 5 })).toBe(20);
    expect(t.evaluateFormula('{a} / {b}', { a: 12, b: 4 })).toBe(3);
  });

  test('honours operator precedence', () => {
    expect(makeTable().evaluateFormula('{a} + {b} * {c}', { a: 1, b: 2, c: 3 })).toBe(7);
  });

  test('parentheses override precedence', () => {
    expect(makeTable().evaluateFormula('({a} + {b}) * {c}', { a: 1, b: 2, c: 3 })).toBe(9);
  });

  test('decimal literals', () => {
    expect(makeTable().evaluateFormula('{a} * 1.5', { a: 4 })).toBe(6);
  });

  test('numeric literals without field references', () => {
    expect(makeTable().evaluateFormula('2 + 3 * 4', {})).toBe(14);
  });
});

describe('evaluateFormula: invalid input', () => {
  test('missing field returns null', () => {
    expect(makeTable().evaluateFormula('{a} + {missing}', { a: 1 })).toBeNull();
  });

  test('non-numeric field value returns null', () => {
    expect(makeTable().evaluateFormula('{a} * 2', { a: 'hello' })).toBeNull();
  });

  test('division by zero returns null (rather than Infinity)', () => {
    expect(makeTable().evaluateFormula('{a} / {b}', { a: 1, b: 0 })).toBeNull();
  });

  test('disallowed token returns null and warns once', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const t = makeTable();

    expect(t.evaluateFormula('alert(1)', {})).toBeNull();
    expect(t.evaluateFormula('alert(1)', {})).toBeNull();
    const matches = warnSpy.mock.calls.filter(c => /alert\(1\)/.test(String(c[0])));
    expect(matches).toHaveLength(1);

    warnSpy.mockRestore();
  });

  test('mismatched parentheses return null', () => {
    expect(makeTable().evaluateFormula('({a} + {b}', { a: 1, b: 2 })).toBeNull();
    expect(makeTable().evaluateFormula('{a} + {b})', { a: 1, b: 2 })).toBeNull();
  });
});

describe('Render integration', () => {
  test('column with formula renders the computed value', () => {
    const table = makeTable({
      columns: [
        { field: 'qty' },
        { field: 'price' },
        { field: 'total', label: 'Total', formula: '{qty} * {price}' }
      ],
      data: [
        { qty: 2,  price: 5  },
        { qty: 10, price: 1.5 }
      ]
    });
    table.render();

    const totals = document.querySelectorAll('td[data-field="total"]');
    expect(totals[0].textContent).toBe('10');
    expect(totals[1].textContent).toBe('15');
  });

  test('formula evaluating to null falls back to empty cell', () => {
    const table = makeTable({
      columns: [
        { field: 'qty' },
        { field: 'total', formula: '{qty} * {missing}' }
      ],
      data: [{ qty: 2 }]
    });
    table.render();

    const total = document.querySelector('td[data-field="total"]');
    expect(total.textContent).toBe('');
  });
});

// ── Math functions ────────────────────────────────────────────────────────────

describe('evaluateFormula: math functions', () => {
  test('ROUND', () => {
    expect(makeTable().evaluateFormula('ROUND({a}, 1)', { a: 2.567 })).toBeCloseTo(2.6);
    expect(makeTable().evaluateFormula('ROUND({a}, 0)', { a: 2.5 })).toBe(3);
  });

  test('ABS', () => {
    expect(makeTable().evaluateFormula('ABS({a})', { a: -5 })).toBe(5);
    expect(makeTable().evaluateFormula('ABS({a})', { a: 3 })).toBe(3);
  });

  test('CEIL and FLOOR', () => {
    expect(makeTable().evaluateFormula('CEIL({a})', { a: 2.1 })).toBe(3);
    expect(makeTable().evaluateFormula('FLOOR({a})', { a: 2.9 })).toBe(2);
  });

  test('SQRT', () => {
    expect(makeTable().evaluateFormula('SQRT({a})', { a: 9 })).toBe(3);
    expect(makeTable().evaluateFormula('SQRT({a})', { a: -1 })).toBeNull();
  });

  test('POWER', () => {
    expect(makeTable().evaluateFormula('POWER({a}, 3)', { a: 2 })).toBe(8);
  });

  test('MIN and MAX', () => {
    expect(makeTable().evaluateFormula('MIN({a}, {b})', { a: 3, b: 7 })).toBe(3);
    expect(makeTable().evaluateFormula('MAX({a}, {b})', { a: 3, b: 7 })).toBe(7);
  });

  test('MOD', () => {
    expect(makeTable().evaluateFormula('MOD({a}, {b})', { a: 10, b: 3 })).toBe(1);
    expect(makeTable().evaluateFormula('MOD({a}, 0)', { a: 5 })).toBeNull();
  });

  test('composed: ROUND(expr)', () => {
    expect(makeTable().evaluateFormula('ROUND({a} * 1.1, 2)', { a: 10 })).toBeCloseTo(11);
  });
});

// ── Text functions ────────────────────────────────────────────────────────────

describe('evaluateFormula: text functions', () => {
  test('CONCAT', () => {
    expect(makeTable().evaluateFormula('CONCAT({a}, "-", {b})', { a: 'foo', b: 'bar' })).toBe('foo-bar');
  });

  test('UPPER and LOWER', () => {
    expect(makeTable().evaluateFormula('UPPER({a})', { a: 'hello' })).toBe('HELLO');
    expect(makeTable().evaluateFormula('LOWER({a})', { a: 'HELLO' })).toBe('hello');
  });

  test('TRIM', () => {
    expect(makeTable().evaluateFormula('TRIM({a})', { a: '  hi  ' })).toBe('hi');
  });

  test('LEN', () => {
    expect(makeTable().evaluateFormula('LEN({a})', { a: 'hello' })).toBe(5);
  });

  test('LEFT and RIGHT', () => {
    expect(makeTable().evaluateFormula('LEFT({a}, 3)', { a: 'abcdef' })).toBe('abc');
    expect(makeTable().evaluateFormula('RIGHT({a}, 2)', { a: 'abcdef' })).toBe('ef');
  });
});

// ── Aggregate functions (cross-row) ──────────────────────────────────────────

describe('evaluateFormula: aggregate functions', () => {
  function makeMultiRow() {
    document.body.innerHTML = '<div id="t"></div>';
    const TC = require('../src/tablecrafter');
    return new TC('#t', {
      columns: [{ field: 'price' }, { field: 'qty' }],
      data: [{ price: 10, qty: 2 }, { price: 20, qty: 5 }, { price: 30, qty: 1 }]
    });
  }

  test('SUM aggregates a column across all rows', () => {
    const t = makeMultiRow();
    expect(t.evaluateFormula('SUM(price)', {})).toBe(60);
  });

  test('AVG aggregates average across rows', () => {
    const t = makeMultiRow();
    expect(t.evaluateFormula('AVG(qty)', {})).toBeCloseTo(2.667, 2);
  });

  test('COUNT counts non-empty cells', () => {
    const t = makeMultiRow();
    expect(t.evaluateFormula('COUNT(price)', {})).toBe(3);
  });
});

// ── Logic ─────────────────────────────────────────────────────────────────────

describe('evaluateFormula: IF function', () => {
  test('IF returns true branch when condition is truthy', () => {
    expect(makeTable().evaluateFormula('IF(1, 42, 0)', {})).toBe(42);
  });

  test('IF returns false branch when condition is falsy', () => {
    expect(makeTable().evaluateFormula('IF(0, 42, 99)', {})).toBe(99);
  });
});
