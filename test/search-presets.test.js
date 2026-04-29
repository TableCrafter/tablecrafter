/**
 * Search presets API: savePreset / removePreset / config.search.presets.
 * Slice 6 of #59. Stacked on PR #91 (regex literals).
 *
 * Lands the public API and the underlying state. UI rendering of preset
 * chips and persistence integration with state-saving remain queued.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }],
    ...extra
  });
}

describe('config.search.presets', () => {
  test('getPresets() returns a defensive copy of configured presets', () => {
    const table = makeTable({
      search: { presets: [{ id: 'mine', label: 'My open items', query: 'status:open' }] }
    });

    const list = table.getPresets();
    expect(list).toEqual([{ id: 'mine', label: 'My open items', query: 'status:open' }]);

    list.length = 0;
    expect(table.getPresets()).toHaveLength(1);
  });

  test('getPresets() returns [] when no presets configured', () => {
    const table = makeTable();
    expect(table.getPresets()).toEqual([]);
  });
});

describe('savePreset(label, query?)', () => {
  test('persists the current query as a new preset and returns it', () => {
    const table = makeTable();
    table.setQuery('status:open');

    const saved = table.savePreset('Open');

    expect(saved.label).toBe('Open');
    expect(saved.query).toBe('status:open');
    expect(saved.id).toBeTruthy();
    expect(table.getPresets().map(p => p.label)).toEqual(['Open']);
  });

  test('an explicit query argument overrides the current query', () => {
    const table = makeTable();
    table.setQuery('something else');
    table.savePreset('Specific', 'role:=admin');
    expect(table.getPresets()[0].query).toBe('role:=admin');
  });

  test('save with the same id replaces the existing preset', () => {
    const table = makeTable({
      search: { presets: [{ id: 'fixed', label: 'Old', query: 'old' }] }
    });

    table.savePreset({ id: 'fixed', label: 'New', query: 'new' });

    const list = table.getPresets();
    expect(list).toHaveLength(1);
    expect(list[0]).toEqual({ id: 'fixed', label: 'New', query: 'new' });
  });

  test('rejects empty label', () => {
    const table = makeTable();
    table.setQuery('foo');
    expect(() => table.savePreset('')).toThrow(/label/i);
    expect(() => table.savePreset(null)).toThrow(/label/i);
  });
});

describe('removePreset(id)', () => {
  test('removes the matching preset and returns true', () => {
    const table = makeTable({
      search: {
        presets: [
          { id: 'a', label: 'Alpha', query: 'x' },
          { id: 'b', label: 'Beta',  query: 'y' }
        ]
      }
    });

    expect(table.removePreset('a')).toBe(true);
    expect(table.getPresets().map(p => p.id)).toEqual(['b']);
  });

  test('returns false when no preset matches', () => {
    const table = makeTable({
      search: { presets: [{ id: 'a', label: 'Alpha', query: 'x' }] }
    });
    expect(table.removePreset('ghost')).toBe(false);
    expect(table.getPresets()).toHaveLength(1);
  });
});

describe('applyPreset(id)', () => {
  test('applies the preset query via setQuery and returns true', () => {
    const table = makeTable({
      search: { presets: [{ id: 'mobile', label: 'Mobile', query: 'team:mobile' }] },
      data: [
        { id: 1, name: 'Alice', team: 'core' },
        { id: 2, name: 'Bob',   team: 'mobile' }
      ]
    });

    expect(table.applyPreset('mobile')).toBe(true);
    expect(table.getFilteredData().map(r => r.id)).toEqual([2]);
  });

  test('returns false for an unknown id', () => {
    const table = makeTable();
    expect(table.applyPreset('ghost')).toBe(false);
  });
});
