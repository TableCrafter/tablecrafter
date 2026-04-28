/**
 * Conditional formatting foundation (slice of #51).
 *
 * This PR lands only:
 *   - the config.conditionalFormatting surface
 *   - addRule / removeRule / setRules public API
 *   - evaluateRule(rule, value, row) pure evaluator (function + core ops)
 *   - getMatchingRules(field, value, row) lookup helper
 *
 * Render-loop wiring (className / style / dataBar / colorScale / aria-label)
 * is intentionally deferred to follow-up PRs and remains tracked in #51.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'value', label: 'Value' }],
    data: [{ value: 1 }, { value: 5 }, { value: 10 }],
    ...extra
  });
}

describe('Conditional formatting: evaluateRule', () => {
  test('function predicate is invoked with (value, row, ctx)', () => {
    const table = makeTable();
    const fn = jest.fn(() => true);
    const row = { value: 7 };
    expect(table.evaluateRule({ id: 'r1', field: 'value', when: fn }, 7, row)).toBe(true);
    expect(fn).toHaveBeenCalledWith(7, row, expect.any(Object));
  });

  test('declarative gt / lt / eq / neq', () => {
    const t = makeTable();
    expect(t.evaluateRule({ field: 'value', when: { op: 'gt', value: 5 } }, 6)).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'gt', value: 5 } }, 5)).toBe(false);
    expect(t.evaluateRule({ field: 'value', when: { op: 'lt', value: 5 } }, 4)).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'eq', value: 'a' } }, 'a')).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'neq', value: 'a' } }, 'b')).toBe(true);
  });

  test('declarative between / contains / empty', () => {
    const t = makeTable();
    expect(t.evaluateRule({ field: 'value', when: { op: 'between', value: [1, 10] } }, 5)).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'between', value: [1, 10] } }, 11)).toBe(false);
    expect(t.evaluateRule({ field: 'value', when: { op: 'contains', value: 'oo' } }, 'foobar')).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'empty' } }, '')).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'empty' } }, 'x')).toBe(false);
  });

  test('declarative regex', () => {
    const t = makeTable();
    expect(t.evaluateRule({ field: 'value', when: { op: 'regex', value: '^foo' } }, 'foobar')).toBe(true);
    expect(t.evaluateRule({ field: 'value', when: { op: 'regex', value: '^foo' } }, 'barfoo')).toBe(false);
  });

  test('returns false for unknown op without throwing', () => {
    const t = makeTable();
    expect(t.evaluateRule({ field: 'value', when: { op: 'xyz', value: 5 } }, 5)).toBe(false);
  });
});

describe('Conditional formatting: rule state API', () => {
  test('addRule appends, getMatchingRules returns matches in priority order', () => {
    const table = makeTable({
      conditionalFormatting: { enabled: true, rules: [] }
    });
    const renderSpy = jest.spyOn(table, 'render');

    table.addRule({ id: 'low', field: 'value', when: { op: 'lt', value: 3 }, className: 'low', priority: 0 });
    table.addRule({ id: 'critical', field: 'value', when: { op: 'lt', value: 2 }, className: 'critical', priority: 5 });
    table.addRule({ id: 'high', field: 'value', when: { op: 'gt', value: 100 }, className: 'high', priority: 0 });

    const matches = table.getMatchingRules('value', 1, { value: 1 });
    expect(matches.map(r => r.id)).toEqual(['critical', 'low']); // higher priority first
    expect(renderSpy).toHaveBeenCalled();
  });

  test('removeRule removes by id and re-renders', () => {
    const table = makeTable({
      conditionalFormatting: {
        enabled: true,
        rules: [
          { id: 'r1', field: 'value', when: () => true, className: 'a' },
          { id: 'r2', field: 'value', when: () => true, className: 'b' }
        ]
      }
    });
    const renderSpy = jest.spyOn(table, 'render');

    table.removeRule('r1');

    expect(table.config.conditionalFormatting.rules.map(r => r.id)).toEqual(['r2']);
    expect(renderSpy).toHaveBeenCalled();
  });

  test('setRules replaces the rule list and re-renders', () => {
    const table = makeTable({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'old', field: 'value', when: () => true }]
      }
    });
    const renderSpy = jest.spyOn(table, 'render');

    table.setRules([
      { id: 'new1', field: 'value', when: () => true },
      { id: 'new2', field: 'value', when: () => true }
    ]);

    expect(table.config.conditionalFormatting.rules.map(r => r.id)).toEqual(['new1', 'new2']);
    expect(renderSpy).toHaveBeenCalled();
  });

  test('getMatchingRules returns [] when conditionalFormatting is disabled', () => {
    const table = makeTable({
      conditionalFormatting: {
        enabled: false,
        rules: [{ id: 'r1', field: 'value', when: () => true }]
      }
    });
    expect(table.getMatchingRules('value', 1, { value: 1 })).toEqual([]);
  });

  test('getMatchingRules respects field — wildcard "*" rules match every field', () => {
    const table = makeTable({
      conditionalFormatting: {
        enabled: true,
        rules: [
          { id: 'specific', field: 'value', when: () => true, scope: 'cell' },
          { id: 'wildcard', field: '*', when: () => true, scope: 'row' }
        ]
      }
    });

    const matches = table.getMatchingRules('value', 1, { value: 1 });
    const otherField = table.getMatchingRules('something_else', 1, { value: 1 });

    expect(matches.map(r => r.id).sort()).toEqual(['specific', 'wildcard']);
    expect(otherField.map(r => r.id)).toEqual(['wildcard']);
  });
});
