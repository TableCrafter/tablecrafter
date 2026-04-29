/**
 * Formula string functions (slice 4 of #47).
 * Stacked on PR #114 (comparison operators + IF).
 *
 * Adds CONCAT, LENGTH, UPPER, LOWER. The numeric grammar still owns the
 * arithmetic / comparison side; these helpers operate on the {field}
 * placeholder values without breaking the safe-eval guarantee.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'a' }, { field: 'b' }, { field: 'c' }],
    data: []
  });
}

const ev = (formula, row = {}) => makeTable().evaluateFormula(formula, row);

describe('Formula: CONCAT', () => {
  test('joins string and numeric placeholders into a single string', () => {
    expect(ev('CONCAT({a}, "-", {b})', { a: 'foo', b: 42 })).toBe('foo-42');
  });

  test('CONCAT with quoted string literals', () => {
    expect(ev('CONCAT("hello, ", {a})', { a: 'world' })).toBe('hello, world');
  });

  test('empty CONCAT returns empty string', () => {
    expect(ev('CONCAT()', {})).toBe('');
  });
});

describe('Formula: LENGTH', () => {
  test('LENGTH of a string field returns its character count', () => {
    expect(ev('LENGTH({a})', { a: 'hello' })).toBe(5);
  });

  test('LENGTH of a number stringifies before counting', () => {
    expect(ev('LENGTH({a})', { a: 12345 })).toBe(5);
  });

  test('LENGTH of "" is 0', () => {
    expect(ev('LENGTH({a})', { a: '' })).toBe(0);
  });

  test('LENGTH of a literal string', () => {
    expect(ev('LENGTH("abc")', {})).toBe(3);
  });

  test('LENGTH wrong arity returns null', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    expect(ev('LENGTH()', {})).toBeNull();
    expect(ev('LENGTH({a}, "extra")', { a: 'hi' })).toBeNull();
    warnSpy.mockRestore();
  });
});

describe('Formula: UPPER / LOWER', () => {
  test('UPPER and LOWER round-trip', () => {
    expect(ev('UPPER({a})', { a: 'hello' })).toBe('HELLO');
    expect(ev('LOWER({a})', { a: 'WORLD' })).toBe('world');
  });

  test('UPPER / LOWER on numeric placeholder coerces to string', () => {
    expect(ev('UPPER({a})', { a: 42 })).toBe('42');
  });
});
