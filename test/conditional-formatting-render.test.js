/**
 * Conditional formatting render-loop wiring (slice 2 of #51).
 * Stacked on PR #80 (rule evaluator + state API).
 *
 * Wires getMatchingRules() into the table render so className / style /
 * scope='row' produce visible DOM mutations. Built-in `kind` shorthands
 * (dataBar / colorScale / icon) and aria-label parity are deferred to a
 * follow-up PR.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, status: 'low',  amount: 5 },
  { id: 2, status: 'mid',  amount: 50 },
  { id: 3, status: 'high', amount: 95 }
];
const columns = [
  { field: 'id',     label: 'ID' },
  { field: 'status', label: 'Status' },
  { field: 'amount', label: 'Amount' }
];

function makeTable(rules = []) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns,
    conditionalFormatting: { enabled: true, rules }
  });
}

describe('Conditional formatting: cell-scope className', () => {
  test('matching cells receive the rule className; non-matching do not', () => {
    const table = makeTable([
      { id: 'r1', field: 'amount', when: { op: 'lt', value: 10 }, className: 'cell-low', scope: 'cell' }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="amount"]');
    expect(cells[0].classList.contains('cell-low')).toBe(true);
    expect(cells[1].classList.contains('cell-low')).toBe(false);
    expect(cells[2].classList.contains('cell-low')).toBe(false);
  });

  test('className may be an array — all entries are added', () => {
    const table = makeTable([
      { id: 'r1', field: 'amount', when: { op: 'gt', value: 90 }, className: ['hot', 'flash'] }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="amount"]');
    expect(cells[2].classList.contains('hot')).toBe(true);
    expect(cells[2].classList.contains('flash')).toBe(true);
  });
});

describe('Conditional formatting: cell-scope inline style', () => {
  test('matching cells receive the rule style', () => {
    const table = makeTable([
      { id: 'r1', field: 'amount', when: { op: 'gt', value: 50 }, style: { backgroundColor: 'red' } }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="amount"]');
    expect(cells[2].style.backgroundColor).toBe('red');
    expect(cells[1].style.backgroundColor).toBe('');
  });

  test('multiple rules merge — higher priority wins on conflicting style props', () => {
    const table = makeTable([
      { id: 'low-pri',  field: 'amount', when: { op: 'gt', value: 10 }, style: { color: 'blue' }, priority: 1 },
      { id: 'high-pri', field: 'amount', when: { op: 'gt', value: 10 }, style: { color: 'green' }, priority: 5 }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="amount"]');
    expect(cells[1].style.color).toBe('green'); // amount=50 matches both, higher priority wins
  });
});

describe('Conditional formatting: row scope', () => {
  test('scope: "row" applies className to the entire <tr>', () => {
    const table = makeTable([
      { id: 'flag-low', field: '*', when: row => row.amount < 10, className: 'row-low', scope: 'row' }
    ]);
    table.render();

    const rows = document.querySelectorAll('tbody tr');
    expect(rows[0].classList.contains('row-low')).toBe(true);
    expect(rows[1].classList.contains('row-low')).toBe(false);
  });
});

describe('Conditional formatting: disabled = no-op', () => {
  test('when conditionalFormatting.enabled is false, nothing is added', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data,
      columns,
      conditionalFormatting: {
        enabled: false,
        rules: [{ id: 'x', field: 'amount', when: () => true, className: 'should-not-appear' }]
      }
    });
    table.render();

    expect(document.querySelector('.should-not-appear')).toBeNull();
  });
});
