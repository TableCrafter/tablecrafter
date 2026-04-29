/**
 * Bars cell type (slice 2 of #58).
 * Stacked on PR #116 (sparkline cell type).
 *
 * Renders an array-of-numbers as an inline column chart of <rect> bars
 * inside an <svg>. Same dependency-free SVG path as sparklines; bars
 * compose alongside sparklines for richer mini-dashboards.
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

describe('renderBars', () => {
  test('returns an <svg> with one <rect> per value', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderBars([1, 2, 3, 4]);
    expect(svg.tagName.toLowerCase()).toBe('svg');
    const rects = svg.querySelectorAll('rect');
    expect(rects).toHaveLength(4);
  });

  test('bar widths divide the viewport evenly with a small gap', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderBars([1, 2, 3, 4], { width: 80, height: 24, gap: 2 });
    const rects = Array.from(svg.querySelectorAll('rect'));
    const widths = rects.map(r => parseFloat(r.getAttribute('width')));

    // 4 bars in 80px with 2px gaps = (80 - 3*2) / 4 = 18.5
    expect(widths.every(w => Math.abs(w - 18.5) < 0.01)).toBe(true);
  });

  test('bar heights scale to the value vs max', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderBars([1, 2, 4], { width: 60, height: 20, gap: 0 });
    const rects = Array.from(svg.querySelectorAll('rect'));
    const heights = rects.map(r => parseFloat(r.getAttribute('height')));

    // values 1, 2, 4 normalised against max 4 → 25%, 50%, 100% of 20 → 5, 10, 20
    expect(heights[0]).toBeCloseTo(5);
    expect(heights[1]).toBeCloseTo(10);
    expect(heights[2]).toBeCloseTo(20);
  });

  test('all-equal series renders bars at full height (no NaN)', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderBars([5, 5, 5], { width: 60, height: 20 });
    const heights = Array.from(svg.querySelectorAll('rect')).map(r => parseFloat(r.getAttribute('height')));
    for (const h of heights) {
      expect(h).toBe(20);
    }
  });

  test('non-array / empty / all-NaN returns null', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    expect(t.renderBars(null)).toBeNull();
    expect(t.renderBars([])).toBeNull();
    expect(t.renderBars('nope')).toBeNull();
    expect(t.renderBars([NaN, 'bad'])).toBeNull();
  });

  test('honours custom fill colour', () => {
    const t = makeTable({ columns: [{ field: 'history' }] });
    const svg = t.renderBars([1, 2, 3], { fill: '#0aa' });
    const rect = svg.querySelector('rect');
    expect(rect.getAttribute('fill')).toBe('#0aa');
  });
});

describe('Bars: cellType integration', () => {
  test('column with cellType: "bars" renders an svg in the body cell', () => {
    const t = makeTable({
      columns: [
        { field: 'id' },
        { field: 'history', cellType: 'bars' }
      ]
    });
    t.render();

    const cells = document.querySelectorAll('td[data-field="history"]');
    expect(cells[0].querySelector('svg')).not.toBeNull();
    expect(cells[1].querySelector('svg')).not.toBeNull();
    expect(cells[2].querySelector('svg')).toBeNull();
    expect(cells[3].querySelector('svg')).toBeNull();
  });
});
