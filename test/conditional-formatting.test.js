// Conditional formatting — evaluator, API, and render-loop wiring (#51)

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

// ── Render-loop wiring ────────────────────────────────────────────────────────

function rendered(cfg = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  const t = new (require('../src/tablecrafter'))('#t', {
    columns: [{ field: 'score', label: 'Score' }, { field: 'name', label: 'Name' }],
    data: [{ score: 10, name: 'Alpha' }, { score: 50, name: 'Beta' }, { score: 90, name: 'Gamma' }],
    ...cfg
  });
  t.render();
  return t;
}

describe('Conditional formatting: render-loop wiring', () => {
  test('disabled by default — no extra classes on cells', () => {
    const t = rendered();
    const tds = document.querySelectorAll('td[data-field="score"]');
    tds.forEach(td => expect(td.className).toBe(''));
  });

  test('className applied to matching cells', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'hi', field: 'score', when: { op: 'gt', value: 80 }, className: 'tc-high' }]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    const withClass = tds.filter(td => td.classList.contains('tc-high'));
    expect(withClass).toHaveLength(1); // only Gamma (90)
  });

  test('style applied to matching cells', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'r', field: 'score', when: { op: 'lt', value: 20 }, style: { color: 'red' } }]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    const styled = tds.filter(td => td.style.color === 'red');
    expect(styled).toHaveLength(1); // only Alpha (10)
  });

  test('higher priority rule wins on conflicting style props', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [
          { id: 'lo', field: 'score', when: () => true, style: { color: 'blue' }, priority: 0 },
          { id: 'hi', field: 'score', when: { op: 'gt', value: 80 }, style: { color: 'red' }, priority: 5 }
        ]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    const gamma = tds[2]; // Gamma = 90, matches both
    expect(gamma.style.color).toBe('red'); // priority 5 wins
    const alpha = tds[0]; // Alpha = 10, only 'lo' matches
    expect(alpha.style.color).toBe('blue');
  });

  test('scope:row applies className to <tr>', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'row', field: 'score', when: { op: 'gt', value: 80 }, className: 'tc-row-hi', scope: 'row' }]
      }
    });
    const rows = [...document.querySelectorAll('tr[data-row-index]')];
    const highlighted = rows.filter(tr => tr.classList.contains('tc-row-hi'));
    expect(highlighted).toHaveLength(1); // Gamma row
  });

  test('kind:icon prepends icon span to matching cell', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'ico', field: 'score', when: { op: 'gt', value: 80 }, kind: 'icon', icon: '✓' }]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    const gamma = tds[2];
    expect(gamma.querySelector('.tc-cf-icon')).not.toBeNull();
    expect(gamma.querySelector('.tc-cf-icon').textContent).toMatch('✓');
  });

  test('kind:dataBar appends bar element with correct approximate width', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'bar', field: 'score', kind: 'dataBar', when: () => true, min: 0, max: 100 }]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    // Beta = 50, should be ~50% wide
    const betaBar = tds[1].querySelector('.tc-databar');
    expect(betaBar).not.toBeNull();
    expect(betaBar.style.width).toBe('50%');
  });

  test('kind:colorScale sets background-color and aria-label on matching cell', () => {
    const t = rendered({
      conditionalFormatting: {
        enabled: true,
        rules: [{
          id: 'scale', field: 'score', kind: 'colorScale', when: () => true,
          min: 0, max: 100, minColor: '#ff0000', maxColor: '#00ff00'
        }]
      }
    });
    const tds = [...document.querySelectorAll('td[data-field="score"]')];
    // All three cells should have background-color set
    tds.forEach(td => expect(td.style.backgroundColor).toBeTruthy());
    // Gamma (90) should be close to maxColor (green)
    const gamma = tds[2];
    expect(gamma.getAttribute('aria-label')).toMatch('score');
  });
});
