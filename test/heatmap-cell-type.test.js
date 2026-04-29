/**
 * Heatmap cell type (slice 3 of #58).
 * Stacked on PR #128 (bars cell type).
 *
 * Renders an array-of-numbers as an inline grid of fixed-size <rect>s
 * coloured by intensity (low → minColor, high → maxColor). Hover
 * tooltip and animation remain queued under #58.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, signal: [0.1, 0.5, 0.9, 0.2] },
  { id: 2, signal: [1, 1, 1, 1] },
  { id: 3, signal: [] },
  { id: 4, signal: null }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, ...extra });
}

describe('renderHeatmap', () => {
  test('returns an <svg> with one <rect> per value', () => {
    const t = makeTable({ columns: [{ field: 'signal' }] });
    const svg = t.renderHeatmap([0.1, 0.5, 0.9]);
    expect(svg.tagName.toLowerCase()).toBe('svg');
    expect(svg.querySelectorAll('rect')).toHaveLength(3);
  });

  test('cells span the viewport horizontally with no overlap', () => {
    const t = makeTable({ columns: [{ field: 'signal' }] });
    const svg = t.renderHeatmap([0.1, 0.5, 0.9, 0.2], { width: 80, height: 16 });
    const rects = Array.from(svg.querySelectorAll('rect'));
    expect(rects).toHaveLength(4);

    const widths = rects.map(r => parseFloat(r.getAttribute('width')));
    const xs = rects.map(r => parseFloat(r.getAttribute('x')));
    expect(widths.every(w => Math.abs(w - 20) < 0.01)).toBe(true);
    expect(xs).toEqual([0, 20, 40, 60]);
  });

  test('low value is the minColor, high value is the maxColor', () => {
    const t = makeTable({ columns: [{ field: 'signal' }] });
    const svg = t.renderHeatmap([0, 1], {
      width: 40, height: 16,
      minColor: '#ff0000', maxColor: '#00ff00'
    });
    const rects = svg.querySelectorAll('rect');
    expect(rects[0].getAttribute('fill')).toBe('rgb(255, 0, 0)');
    expect(rects[1].getAttribute('fill')).toBe('rgb(0, 255, 0)');
  });

  test('all-equal series renders cells at maxColor (full intensity)', () => {
    const t = makeTable({ columns: [{ field: 'signal' }] });
    const svg = t.renderHeatmap([5, 5, 5], {
      minColor: '#ff0000', maxColor: '#00ff00'
    });
    const rects = svg.querySelectorAll('rect');
    for (const rect of rects) {
      expect(rect.getAttribute('fill')).toBe('rgb(0, 255, 0)');
    }
  });

  test('non-array / empty / all-NaN returns null', () => {
    const t = makeTable({ columns: [{ field: 'signal' }] });
    expect(t.renderHeatmap(null)).toBeNull();
    expect(t.renderHeatmap([])).toBeNull();
    expect(t.renderHeatmap('nope')).toBeNull();
    expect(t.renderHeatmap([NaN, 'bad'])).toBeNull();
  });
});

describe('Heatmap: cellType integration', () => {
  test('column with cellType: "heatmap" renders an svg in the body cell', () => {
    const t = makeTable({
      columns: [
        { field: 'id' },
        { field: 'signal', cellType: 'heatmap' }
      ]
    });
    t.render();

    const cells = document.querySelectorAll('td[data-field="signal"]');
    expect(cells[0].querySelector('svg')).not.toBeNull();
    expect(cells[1].querySelector('svg')).not.toBeNull();
    expect(cells[2].querySelector('svg')).toBeNull();
    expect(cells[3].querySelector('svg')).toBeNull();
  });

  test('column.heatmap options pass through to the renderer', () => {
    const t = makeTable({
      columns: [
        { field: 'signal', cellType: 'heatmap', heatmap: { width: 120, height: 24, minColor: '#000', maxColor: '#fff' } }
      ]
    });
    t.render();
    const svg = document.querySelector('td[data-field="signal"] svg');
    expect(svg.getAttribute('width')).toBe('120');
    expect(svg.getAttribute('height')).toBe('24');
  });
});
