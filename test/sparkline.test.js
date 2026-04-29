/**
 * Sparkline cell type (slice 1 of #58).
 *
 * Lands a dependency-free SVG sparkline renderer + cell-type integration.
 * Bar / column / heatmap cell types, hover-tooltip, and animation remain
 * queued under #58.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, history: [1, 3, 2, 4, 5] },
  { id: 2, history: [10, 10, 10, 10] },
  { id: 3, history: [] },
  { id: 4, history: null }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, ...extra });
}

describe('Sparkline: renderSparkline', () => {
  test('returns an <svg> with a <polyline>', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([1, 2, 3, 4]);
    expect(svg.tagName.toLowerCase()).toBe('svg');
    expect(svg.querySelector('polyline')).not.toBeNull();
  });

  test('points span the full viewport horizontally', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([1, 2, 3, 4, 5], { width: 100, height: 20 });
    const poly = svg.querySelector('polyline');
    const points = poly.getAttribute('points').split(' ').map(p => p.split(',').map(Number));

    expect(points).toHaveLength(5);
    expect(points[0][0]).toBe(0);
    expect(points[points.length - 1][0]).toBe(100);
  });

  test('points span the full viewport vertically (low value at bottom, high at top)', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([1, 5], { width: 100, height: 20 });
    const points = svg.querySelector('polyline').getAttribute('points').split(' ').map(p => p.split(',').map(Number));

    // Low value (1) → bottom of viewport (y = height = 20)
    // High value (5) → top of viewport (y = 0)
    expect(points[0][1]).toBe(20);
    expect(points[1][1]).toBe(0);
  });

  test('single-value series renders a horizontal midpoint line', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([42], { width: 100, height: 20 });
    const points = svg.querySelector('polyline').getAttribute('points').split(' ').map(p => p.split(',').map(Number));

    expect(points).toHaveLength(1);
    expect(points[0][1]).toBe(10); // midpoint of height 20
  });

  test('all-equal series renders at midpoint (no NaN)', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([5, 5, 5, 5], { width: 100, height: 20 });
    const points = svg.querySelector('polyline').getAttribute('points').split(' ').map(p => p.split(',').map(Number));

    for (const [, y] of points) {
      expect(y).toBe(10);
    }
  });

  test('honours custom width / height / stroke', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderSparkline([1, 2, 3], { width: 200, height: 50, stroke: '#f00' });
    expect(svg.getAttribute('width')).toBe('200');
    expect(svg.getAttribute('height')).toBe('50');
    expect(svg.querySelector('polyline').getAttribute('stroke')).toBe('#f00');
  });

  test('non-array / empty / null returns null (caller renders empty cell)', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    expect(t.renderSparkline(null)).toBeNull();
    expect(t.renderSparkline([])).toBeNull();
    expect(t.renderSparkline('not an array')).toBeNull();
    expect(t.renderSparkline([NaN, 'bad', null])).toBeNull();
  });
});

describe('Sparkline: cellType integration', () => {
  test('column with cellType "sparkline" renders an svg in the body cell', () => {
    const t = makeTable({
      columns: [
        { field: 'id' },
        { field: 'history', cellType: 'sparkline' }
      ]
    });
    t.render();

    const cells = document.querySelectorAll('td[data-field="history"]');
    expect(cells[0].querySelector('svg')).not.toBeNull();        // [1, 3, 2, 4, 5]
    expect(cells[1].querySelector('svg')).not.toBeNull();        // all-equal
    expect(cells[2].querySelector('svg')).toBeNull();            // empty array
    expect(cells[3].querySelector('svg')).toBeNull();            // null
  });

  test('column.sparkline options reach the renderer', () => {
    const t = makeTable({
      columns: [
        { field: 'history', cellType: 'sparkline', sparkline: { width: 120, height: 32 } }
      ]
    });
    t.render();

    const svg = document.querySelector('td[data-field="history"] svg');
    expect(svg.getAttribute('width')).toBe('120');
    expect(svg.getAttribute('height')).toBe('32');
  });
});
