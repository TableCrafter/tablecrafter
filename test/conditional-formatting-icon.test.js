/**
 * Conditional formatting — kind: 'icon' shorthand (slice 3 of #51).
 * Stacked on PR #85 (render-loop wiring for className / style / row scope).
 *
 * When a rule sets `kind: 'icon'` and a non-empty `icon` string, the
 * matching cell receives a leading <span class="tc-cf-icon"> with that
 * icon. dataBar / colorScale and aria-label parity remain queued.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, status: 'open' },
  { id: 2, status: 'closed' },
  { id: 3, status: 'archived' }
];
const columns = [{ field: 'id' }, { field: 'status' }];

function makeTable(rules) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns,
    conditionalFormatting: { enabled: true, rules }
  });
}

describe('Conditional formatting: kind: "icon"', () => {
  test('matching cells get a prepended .tc-cf-icon span with the configured icon', () => {
    const table = makeTable([
      { id: 'flag-archived', field: 'status', when: { op: 'eq', value: 'archived' }, kind: 'icon', icon: '✗' }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="status"]');
    expect(cells[0].querySelector('.tc-cf-icon')).toBeNull();
    expect(cells[1].querySelector('.tc-cf-icon')).toBeNull();
    expect(cells[2].querySelector('.tc-cf-icon')).not.toBeNull();
    expect(cells[2].querySelector('.tc-cf-icon').textContent).toBe('✗');
  });

  test('the icon is rendered before the existing cell content', () => {
    const table = makeTable([
      { id: 'flag-open', field: 'status', when: { op: 'eq', value: 'open' }, kind: 'icon', icon: '✓' }
    ]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="status"]')[0];
    expect(cell.firstElementChild).not.toBeNull();
    expect(cell.firstElementChild.classList.contains('tc-cf-icon')).toBe(true);
    expect(cell.textContent).toContain('✓');
    expect(cell.textContent).toContain('open');
  });

  test('rule with kind: "icon" but no icon string is a no-op', () => {
    const table = makeTable([
      { id: 'no-icon', field: 'status', when: () => true, kind: 'icon' }
    ]);
    table.render();

    expect(document.querySelector('.tc-cf-icon')).toBeNull();
  });

  test('icon may also stack with className and style on the same rule', () => {
    const table = makeTable([
      {
        id: 'combo',
        field: 'status',
        when: { op: 'eq', value: 'open' },
        kind: 'icon',
        icon: '✓',
        className: 'flag',
        style: { color: 'green' }
      }
    ]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="status"]')[0];
    expect(cell.classList.contains('flag')).toBe(true);
    expect(cell.style.color).toBe('green');
    expect(cell.querySelector('.tc-cf-icon').textContent).toBe('✓');
  });
});
