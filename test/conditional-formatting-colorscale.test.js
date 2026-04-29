/**
 * Conditional formatting — kind: 'colorScale' shorthand (slice 5 of #51).
 * Stacked on PR #99 (kind: 'dataBar').
 *
 * Interpolates the cell's backgroundColor between minColor / [midColor] /
 * maxColor based on the numeric value's position in [min, max]. Two-stop
 * (min → max) and three-stop (min → mid → max) gradients are supported.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, score: 0   },
  { id: 2, score: 50  },
  { id: 3, score: 100 }
];
const columns = [{ field: 'id' }, { field: 'score' }];

function makeTable(rule) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data,
    columns,
    conditionalFormatting: { enabled: true, rules: [rule] }
  });
}

function bgOf(cell) {
  // jsdom serialises rgb() as the canonical form.
  return cell.style.backgroundColor;
}

describe('Conditional formatting: kind: "colorScale"', () => {
  test('two-stop scale: min/max colours land at the endpoints', () => {
    const table = makeTable({
      id: 'g', field: 'score', when: () => true, kind: 'colorScale',
      min: 0, max: 100,
      minColor: '#ff0000', maxColor: '#00ff00'
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(bgOf(cells[0])).toBe('rgb(255, 0, 0)');   // min
    expect(bgOf(cells[2])).toBe('rgb(0, 255, 0)');   // max
  });

  test('two-stop scale midpoint is the average of the two endpoint colours', () => {
    const table = makeTable({
      id: 'g', field: 'score', when: () => true, kind: 'colorScale',
      min: 0, max: 100,
      minColor: '#ff0000', maxColor: '#00ff00'
    });
    table.render();

    const mid = document.querySelectorAll('td[data-field="score"]')[1];
    // value 50 = midpoint → average of red and green channels.
    expect(bgOf(mid)).toBe('rgb(128, 128, 0)');
  });

  test('three-stop scale interpolates through midColor at the midpoint', () => {
    const table = makeTable({
      id: 'g', field: 'score', when: () => true, kind: 'colorScale',
      min: 0, max: 100, mid: 50,
      minColor: '#ff0000', midColor: '#ffff00', maxColor: '#00ff00'
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(bgOf(cells[0])).toBe('rgb(255, 0, 0)');
    expect(bgOf(cells[1])).toBe('rgb(255, 255, 0)'); // exactly midColor
    expect(bgOf(cells[2])).toBe('rgb(0, 255, 0)');
  });

  test('clamps below min and above max', () => {
    const wider = [{ score: -10 }, { score: 200 }];
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: wider,
      columns: [{ field: 'score' }],
      conditionalFormatting: {
        enabled: true,
        rules: [{
          id: 'g', field: 'score', when: () => true, kind: 'colorScale',
          min: 0, max: 100,
          minColor: '#ff0000', maxColor: '#00ff00'
        }]
      }
    });
    table.render();

    const cells = document.querySelectorAll('td[data-field="score"]');
    expect(bgOf(cells[0])).toBe('rgb(255, 0, 0)');
    expect(bgOf(cells[1])).toBe('rgb(0, 255, 0)');
  });

  test('non-numeric value skips colorScale entirely', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: [{ score: 'n/a' }],
      columns: [{ field: 'score' }],
      conditionalFormatting: {
        enabled: true,
        rules: [{
          id: 'g', field: 'score', when: () => true, kind: 'colorScale',
          min: 0, max: 100,
          minColor: '#ff0000', maxColor: '#00ff00'
        }]
      }
    });
    table.render();

    const cell = document.querySelectorAll('td[data-field="score"]')[0];
    expect(bgOf(cell)).toBe('');
  });
});
