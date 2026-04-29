/**
 * Developer tools — getStats() introspection (slice 1 of #61).
 *
 * Lands a lightweight introspection helper. Browser extension UI,
 * DevTools panel injection, memory profiling, and event tracing
 * remain queued under #61.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'Alice', team: 'core' },
  { id: 2, name: 'Bob',   team: 'core' },
  { id: 3, name: 'Carol', team: 'mobile' },
  { id: 4, name: 'Dana',  team: 'mobile' }
];
const columns = [
  { field: 'id' },
  { field: 'name' },
  { field: 'team' }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  const cfg = { data, columns, ...extra };
  cfg.columns = (cfg.columns || []).map(c => ({ ...c }));
  return new TableCrafter('#t', cfg);
}

describe('getStats: row counts', () => {
  test('totalRows / visibleRows / renderedRows defaults', () => {
    const table = makeTable();
    table.render();
    const stats = table.getStats();
    expect(stats.totalRows).toBe(4);
    expect(stats.visibleRows).toBe(4);
    expect(stats.renderedRows).toBeLessThanOrEqual(4);
  });

  test('visibleRows reflects an active search', () => {
    const table = makeTable();
    table.searchTerm = 'mobile';
    expect(table.getStats().visibleRows).toBe(2);
  });
});

describe('getStats: column counts', () => {
  test('columnCount and hiddenColumnCount track column.hidden', () => {
    const table = makeTable({
      columns: [
        { field: 'id' },
        { field: 'name', hidden: true },
        { field: 'team' }
      ]
    });
    const stats = table.getStats();
    expect(stats.columnCount).toBe(3);
    expect(stats.hiddenColumnCount).toBe(1);
  });

  test('pinnedColumns counts left + right separately', () => {
    const table = makeTable({
      columns: [
        { field: 'id', pinned: 'left' },
        { field: 'name' },
        { field: 'team', pinned: 'right' }
      ]
    });
    expect(table.getStats().pinnedColumns).toEqual({ left: 1, right: 1 });
  });
});

describe('getStats: plugins', () => {
  test('pluginCount reflects the internal _plugins registry', () => {
    const table = makeTable();
    expect(table.getStats().pluginCount).toBe(0);

    // Direct registry manipulation — the plugin registry public API
    // (use / unuse) lives on a stacked PR, but the stats helper should
    // already report whatever the registry contains.
    table._plugins = [
      { plugin: { name: 'p1' }, options: undefined },
      { plugin: { name: 'p2' }, options: undefined }
    ];
    expect(table.getStats().pluginCount).toBe(2);

    table._plugins.pop();
    expect(table.getStats().pluginCount).toBe(1);
  });
});

describe('getStats: timing', () => {
  test('lastRenderMs is a non-negative number after render()', () => {
    const table = makeTable();
    table.render();
    const stats = table.getStats();
    expect(typeof stats.lastRenderMs).toBe('number');
    expect(stats.lastRenderMs).toBeGreaterThanOrEqual(0);
  });

  test('lastFilterMs is a non-negative number after getFilteredData()', () => {
    const table = makeTable();
    table.getFilteredData();
    const stats = table.getStats();
    expect(typeof stats.lastFilterMs).toBe('number');
    expect(stats.lastFilterMs).toBeGreaterThanOrEqual(0);
  });
});

describe('getStats: snapshot independence', () => {
  test('mutating the snapshot does not affect internal state', () => {
    const table = makeTable();
    const snap = table.getStats();
    snap.totalRows = 999;
    expect(table.getStats().totalRows).toBe(4);
  });

  test('calling getStats() does not trigger render or filter', () => {
    const table = makeTable();
    const renderSpy = jest.spyOn(table, 'render');
    const filterSpy = jest.spyOn(table, 'getFilteredData');
    table.getStats();
    expect(renderSpy).not.toHaveBeenCalled();
    expect(filterSpy).not.toHaveBeenCalled();
  });
});
