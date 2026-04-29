/**
 * Formula evaluator foundation (slice 1 of #47).
 *
 * Lands a safe expression evaluator that resolves `{field}` placeholders,
 * supports +, -, *, /, parentheses, and numeric literals, and rejects
 * anything outside that allowlist (no eval / Function).
 *
 * Function library, formula bar UI, dependency tracking, and SUM/AVG
 * cross-row references remain queued under #47.
 */

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
