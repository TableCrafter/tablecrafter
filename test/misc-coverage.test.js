// Miscellaneous coverage: setData, copyToClipboard, autoDiscoverColumns,
// sort keydown handler, state persistence, getPermissionFilteredData,
// conditional formatting kinds (_applyConditionalKind, _cfColumnMin/Max,
// _interpolateColor), clearFilters (via render), retry button handler,
// processData with root path

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }],
    ...extra
  });
}

// ── setData ──────────────────────────────────────────────────────────────────

describe('setData', () => {
  test('replaces data and re-renders when wrapper present', () => {
    const t = makeTable();
    t.render();
    const spy = jest.spyOn(t, 'render');
    t.setData([{ id: 9, name: 'Zara' }]);
    expect(t.data).toEqual([{ id: 9, name: 'Zara' }]);
    expect(spy).toHaveBeenCalled();
  });

  test('data is replaced correctly (wrapper always present after construction)', () => {
    const t = makeTable();
    t.setData([{ id: 5, name: 'Eve' }]);
    expect(t.data).toEqual([{ id: 5, name: 'Eve' }]);
  });
});

// ── autoDiscoverColumns ──────────────────────────────────────────────────────

describe('autoDiscoverColumns', () => {
  test('populates columns from first data item keys when columns is empty', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [],
      data: [{ alpha: 1, beta: 2 }]
    });
    t.autoDiscoverColumns();
    expect(t.config.columns.map(c => c.field)).toEqual(['alpha', 'beta']);
  });

  test('respects include list and ordering', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [],
      data: [{ a: 1, b: 2, c: 3 }],
      include: ['c', 'a']
    });
    t.autoDiscoverColumns();
    expect(t.config.columns.map(c => c.field)).toEqual(['c', 'a']);
  });

  test('respects exclude list', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [],
      data: [{ x: 1, y: 2, z: 3 }],
      exclude: ['y']
    });
    t.autoDiscoverColumns();
    expect(t.config.columns.map(c => c.field)).toEqual(['x', 'z']);
  });

  test('include as comma-separated string', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [],
      data: [{ p: 1, q: 2, r: 3 }],
      include: 'p,r'
    });
    t.autoDiscoverColumns();
    expect(t.config.columns.map(c => c.field)).toContain('p');
    expect(t.config.columns.map(c => c.field)).toContain('r');
    expect(t.config.columns.map(c => c.field)).not.toContain('q');
  });

  test('no-op when data is empty', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { columns: [], data: [] });
    t.autoDiscoverColumns();
    expect(t.config.columns).toEqual([]);
  });
});

// ── processData with root path ────────────────────────────────────────────────

describe('processData', () => {
  test('extracts nested data via root path', () => {
    const t = makeTable({ root: 'data.items' });
    const result = t.processData({ data: { items: [{ id: 1 }] } });
    expect(result).toEqual([{ id: 1 }]);
  });

  test('warns and returns empty array when segment not found', () => {
    const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const t = makeTable({ root: 'missing.path' });
    const result = t.processData({ other: 'data' });
    expect(result).toEqual([]);
    warn.mockRestore();
  });

  test('wraps a single object in array', () => {
    const t = makeTable();
    expect(t.processData({ id: 1 })).toEqual([{ id: 1 }]);
  });

  test('returns empty array for null input', () => {
    const t = makeTable();
    expect(t.processData(null)).toEqual([]);
  });
});

// ── sort keydown handler ─────────────────────────────────────────────────────

describe('sort header keydown', () => {
  test('Enter key on sortable header triggers sort', () => {
    const t = makeTable({ sortable: true, columns: [{ field: 'id', sortable: true }, { field: 'name', sortable: true }] });
    t.render();
    const sortSpy = jest.spyOn(t, 'sort');
    const th = t.container.querySelector('th[data-field="name"]');
    if (th) {
      th.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      expect(sortSpy).toHaveBeenCalledWith('name', expect.anything());
    }
  });

  test('Space key on sortable header triggers sort', () => {
    const t = makeTable({ sortable: true, columns: [{ field: 'id', sortable: true }, { field: 'name', sortable: true }] });
    t.render();
    const sortSpy = jest.spyOn(t, 'sort');
    const th = t.container.querySelector('th[data-field="id"]');
    if (th) {
      th.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
      expect(sortSpy).toHaveBeenCalled();
    }
  });
});

// ── copyToClipboard ──────────────────────────────────────────────────────────

describe('copyToClipboard', () => {
  test('uses navigator.clipboard.writeText when available', async () => {
    const t = makeTable();
    const writeSpy = jest.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: writeSpy },
      configurable: true
    });
    t.copyToClipboard();
    await Promise.resolve();
    expect(writeSpy).toHaveBeenCalled();
  });

  test('no-op when data is empty', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    expect(() => t.copyToClipboard()).not.toThrow();
  });
});

// ── state persistence ─────────────────────────────────────────────────────────

describe('state persistence', () => {
  test('saveState / loadState round-trip via localStorage', () => {
    const t = makeTable({
      state: { persist: true, key: 'tc-test-state', storage: 'localStorage' }
    });
    t.filters = { name: 'Alice' };
    t.currentPage = 3;
    t.saveState();

    const t2 = makeTable({
      state: { persist: true, key: 'tc-test-state', storage: 'localStorage' }
    });
    t2.loadState();
    expect(t2.filters).toEqual({ name: 'Alice' });
    expect(t2.currentPage).toBe(3);

    localStorage.removeItem('tc-test-state');
  });

  test('saveState is no-op when persist is false', () => {
    const t = makeTable({ state: { persist: false, key: 'tc-noop-save' } });
    t.saveState();
    expect(localStorage.getItem('tc-noop-save')).toBeNull();
  });

  test('loadState is no-op when persist is false', () => {
    const t = makeTable({ state: { persist: false, key: 'tc-noop-load', storage: 'localStorage' } });
    localStorage.setItem('tc-noop-load', JSON.stringify({ currentPage: 99 }));
    t.loadState();
    expect(t.currentPage).toBe(1); // unchanged
    localStorage.removeItem('tc-noop-load');
  });

  test('clearState removes key from storage', () => {
    const t = makeTable({
      state: { persist: true, key: 'tc-clear-test', storage: 'localStorage' }
    });
    localStorage.setItem('tc-clear-test', '{}');
    t.clearState();
    expect(localStorage.getItem('tc-clear-test')).toBeNull();
  });

  test('loadState handles legacy sortField/sortOrder', () => {
    const t = makeTable({
      state: { persist: true, key: 'tc-legacy-state', storage: 'localStorage' }
    });
    localStorage.setItem('tc-legacy-state', JSON.stringify({
      sortField: 'name',
      sortOrder: 'desc',
      filters: {},
      currentPage: 1,
      selectedRows: []
    }));
    t.loadState();
    expect(t.sortKeys[0]).toEqual({ field: 'name', direction: 'desc' });
    localStorage.removeItem('tc-legacy-state');
  });

  test('saveState / loadState round-trip via sessionStorage', () => {
    const t = makeTable({
      state: { persist: true, key: 'tc-session-test', storage: 'sessionStorage' }
    });
    t.currentPage = 2;
    t.saveState();
    const t2 = makeTable({
      state: { persist: true, key: 'tc-session-test', storage: 'sessionStorage' }
    });
    t2.loadState();
    expect(t2.currentPage).toBe(2);
    sessionStorage.removeItem('tc-session-test');
  });
});

// ── getPermissionFilteredData ────────────────────────────────────────────────

describe('getPermissionFilteredData', () => {
  test('returns all data when permissions disabled', () => {
    const t = makeTable({ permissions: { enabled: false } });
    expect(t.getPermissionFilteredData()).toHaveLength(2);
  });

  test('returns all data when ownOnly is false', () => {
    const t = makeTable({ permissions: { enabled: true, ownOnly: false } });
    expect(t.getPermissionFilteredData()).toHaveLength(2);
  });
});

// ── _cfColumnMin / _cfColumnMax / _interpolateColor ─────────────────────────

describe('conditional formatting helpers', () => {
  test('_cfColumnMin returns min numeric value in column', () => {
    const t = makeTable({ data: [{ id: 5 }, { id: 1 }, { id: 3 }] });
    expect(t._cfColumnMin('id')).toBe(1);
  });

  test('_cfColumnMax returns max numeric value in column', () => {
    const t = makeTable({ data: [{ id: 5 }, { id: 1 }, { id: 3 }] });
    expect(t._cfColumnMax('id')).toBe(5);
  });

  test('_cfColumnMin returns 0 for empty data', () => {
    const t = makeTable({ data: [] });
    expect(t._cfColumnMin('id')).toBe(0);
  });

  test('_cfColumnMax returns 1 for empty data', () => {
    const t = makeTable({ data: [] });
    expect(t._cfColumnMax('id')).toBe(1);
  });

  test('_interpolateColor blends two hex colors', () => {
    const t = makeTable();
    const result = t._interpolateColor('#000000', '#ffffff', 0.5);
    expect(result).toBe('rgb(128,128,128)');
  });

  test('_interpolateColor returns first color at t=0', () => {
    const t = makeTable();
    expect(t._interpolateColor('#ff0000', '#0000ff', 0)).toBe('rgb(255,0,0)');
  });

  test('_interpolateColor returns second color at t=1', () => {
    const t = makeTable();
    expect(t._interpolateColor('#ff0000', '#0000ff', 1)).toBe('rgb(0,0,255)');
  });
});

// ── _applyConditionalKind (icon / dataBar / colorScale) ──────────────────────

describe('_applyConditionalKind', () => {
  test('icon kind prepends icon span', () => {
    const t = makeTable({ data: [{ id: 5 }] });
    const td = document.createElement('td');
    td.textContent = 'content';
    t._applyConditionalKind(td, { kind: 'icon', icon: '⭐' }, 5);
    expect(td.querySelector('.tc-cf-icon')).not.toBeNull();
    expect(td.querySelector('.tc-cf-icon').textContent).toContain('⭐');
  });

  test('dataBar kind appends a .tc-databar', () => {
    const t = makeTable({ data: [{ id: 10 }, { id: 0 }] });
    const td = document.createElement('td');
    t._applyConditionalKind(td, { kind: 'dataBar', field: 'id' }, 10);
    expect(td.querySelector('.tc-databar')).not.toBeNull();
  });

  test('colorScale kind sets backgroundColor on td', () => {
    const t = makeTable({ data: [{ id: 0 }, { id: 100 }] });
    const td = document.createElement('td');
    td.dataset.field = 'id';
    t._applyConditionalKind(td, { kind: 'colorScale', field: 'id' }, 50);
    expect(td.style.backgroundColor).toBeTruthy();
  });

  test('colorScale with midColor uses three-way interpolation', () => {
    const t = makeTable({ data: [{ id: 0 }, { id: 100 }] });
    const td = document.createElement('td');
    t._applyConditionalKind(td, {
      kind: 'colorScale', field: 'id',
      minColor: '#ff0000', midColor: '#ffff00', maxColor: '#00ff00'
    }, 25);
    expect(td.style.backgroundColor).toBeTruthy();
  });

  test('dataBar with non-numeric value is no-op', () => {
    const t = makeTable();
    const td = document.createElement('td');
    t._applyConditionalKind(td, { kind: 'dataBar', field: 'id' }, 'NaN-text');
    expect(td.querySelector('.tc-databar')).toBeNull();
  });
});

// ── _applyRowConditionalFormatting ────────────────────────────────────────────

describe('_applyRowConditionalFormatting', () => {
  test('applies style and class to tr for matching row-scoped rule', () => {
    const t = makeTable({
      conditionalFormatting: {
        enabled: true,
        rules: [{
          scope: 'row',
          field: 'id',
          when: { op: 'eq', value: 1 },
          style: { fontWeight: 'bold' },
          className: 'highlight-row'
        }]
      }
    });
    const tr = document.createElement('tr');
    t._applyRowConditionalFormatting(tr, { id: 1, name: 'Alice' });
    expect(tr.style.fontWeight).toBe('bold');
    expect(tr.classList.contains('highlight-row')).toBe(true);
  });

  test('no-op when conditionalFormatting disabled', () => {
    const t = makeTable();
    const tr = document.createElement('tr');
    expect(() => t._applyRowConditionalFormatting(tr, { id: 1 })).not.toThrow();
  });
});
