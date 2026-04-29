/**
 * Conditional formatting — aria-label parity for visual-only cues (slice 6 of #51).
 * Stacked on PR #100 (kind: 'colorScale').
 *
 * Closes AC item 6: built-in `colorScale` and `dataBar` cells get an
 * `aria-label="${field}: ${value}"` by default; consumer-supplied
 * `ariaLabel(value, row)` overrides the default.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, score: 25, label: 'Low'  },
  { id: 2, score: 75, label: 'High' }
];
const columns = [{ field: 'id' }, { field: 'score' }, { field: 'label' }];

function makeTable(rule) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns,
    conditionalFormatting: { enabled: true, rules: [rule] }
  });
}

describe('Conditional formatting: aria-label for visual-only cues', () => {
  test('colorScale cells get aria-label="${field}: ${value}" by default', () => {
    const table = makeTable({
      id: 'g', field: 'score', when: () => true, kind: 'colorScale',
      min: 0, max: 100, minColor: '#ff0000', maxColor: '#00ff00'
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(cells[0].getAttribute('aria-label')).toBe('score: 25');
    expect(cells[1].getAttribute('aria-label')).toBe('score: 75');
  });

  test('dataBar cells get aria-label="${field}: ${value}" by default', () => {
    const table = makeTable({
      id: 'b', field: 'score', when: () => true, kind: 'dataBar',
      min: 0, max: 100
    });
    table.render();

    const cell = document.querySelectorAll('td[data-field="score"]')[0];
    expect(cell.getAttribute('aria-label')).toBe('score: 25');
  });

  test('custom ariaLabel(value, row) overrides the default', () => {
    const table = makeTable({
      id: 'g', field: 'score', when: () => true, kind: 'colorScale',
      min: 0, max: 100, minColor: '#ff0000', maxColor: '#00ff00',
      ariaLabel: (value, row) => `${row.label} score (${value} of 100)`
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(cells[0].getAttribute('aria-label')).toBe('Low score (25 of 100)');
    expect(cells[1].getAttribute('aria-label')).toBe('High score (75 of 100)');
  });

  test('plain className/style rules do not add aria-label by themselves', () => {
    const table = makeTable({
      id: 'flag', field: 'score', when: () => true, className: 'plain'
    });
    table.render();

    const cell = document.querySelectorAll('td[data-field="score"]')[0];
    expect(cell.classList.contains('plain')).toBe(true);
    expect(cell.getAttribute('aria-label')).toBeNull();
  });

  test('rule with kind: "icon" does not add aria-label automatically (icon is visible text)', () => {
    const table = makeTable({
      id: 'i', field: 'score', when: () => true, kind: 'icon', icon: '✓'
    });
    table.render();

    const cell = document.querySelectorAll('td[data-field="score"]')[0];
    // Icon is rendered as inline text, screen readers read it directly,
    // so no extra aria-label is needed (consumers can opt in via ariaLabel).
    expect(cell.getAttribute('aria-label')).toBeNull();
  });
});
