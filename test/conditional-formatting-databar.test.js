/**
 * Conditional formatting — kind: 'dataBar' shorthand (slice 4 of #51).
 * Stacked on PR #98 (kind: 'icon').
 *
 * Renders a horizontal bar inside the cell as a child <span class="tc-cf-databar">
 * whose inline-style width is (value - min) / (max - min) * 100%.
 * Min/max come from the rule when explicit, otherwise are auto-computed
 * from this.data values for that field.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, score: 0   },
  { id: 2, score: 25  },
  { id: 3, score: 50  },
  { id: 4, score: 75  },
  { id: 5, score: 100 }
];
const columns = [{ field: 'id' }, { field: 'score' }];

function makeTable(rules) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns,
    conditionalFormatting: { enabled: true, rules }
  });
}

function widthOf(cell) {
  const bar = cell.querySelector('.tc-cf-databar');
  if (!bar) return null;
  return bar.style.width;
}

describe('Conditional formatting: kind: "dataBar"', () => {
  test('explicit min/max produces width = (value - min) / (max - min) * 100%', () => {
    const table = makeTable([
      { id: 'bar', field: 'score', when: () => true, kind: 'dataBar', min: 0, max: 100 }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(widthOf(cells[0])).toBe('0%');
    expect(widthOf(cells[1])).toBe('25%');
    expect(widthOf(cells[2])).toBe('50%');
    expect(widthOf(cells[3])).toBe('75%');
    expect(widthOf(cells[4])).toBe('100%');
  });

  test('auto-computes min/max from this.data when not provided', () => {
    const autoData = [{ score: 10 }, { score: 30 }, { score: 50 }];
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: autoData,
      columns: [{ field: 'score' }],
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'bar', field: 'score', when: () => true, kind: 'dataBar' }]
      }
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(widthOf(cells[0])).toBe('0%');   // value 10 = min
    expect(widthOf(cells[1])).toBe('50%');  // value 30 = midpoint
    expect(widthOf(cells[2])).toBe('100%'); // value 50 = max
  });

  test('clamps out-of-range values to [0%, 100%]', () => {
    const table = makeTable([
      { id: 'bar', field: 'score', when: () => true, kind: 'dataBar', min: 20, max: 80 }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(widthOf(cells[0])).toBe('0%');   // value 0 < min
    expect(widthOf(cells[4])).toBe('100%'); // value 100 > max
  });

  test('skips dataBar entirely when value is non-numeric', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: [{ score: 'n/a' }, { score: 50 }],
      columns: [{ field: 'score' }],
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'bar', field: 'score', when: () => true, kind: 'dataBar', min: 0, max: 100 }]
      }
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(cells[0].querySelector('.tc-cf-databar')).toBeNull();
    expect(widthOf(cells[1])).toBe('50%');
  });

  test('zero range (min === max) renders 0% rather than NaN', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: [{ score: 5 }, { score: 5 }],
      columns: [{ field: 'score' }],
      conditionalFormatting: {
        enabled: true,
        rules: [{ id: 'bar', field: 'score', when: () => true, kind: 'dataBar', min: 5, max: 5 }]
      }
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(widthOf(cells[0])).toBe('0%');
    expect(widthOf(cells[1])).toBe('0%');
  });
});
