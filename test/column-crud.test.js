/**
 * Column CRUD foundation (slice 1 of #50).
 *
 * Lands the programmatic addColumn / removeColumn / updateColumn / getColumn
 * surface. Drag-and-drop column-creation UI, schema design tools, and the
 * template gallery remain queued under #50.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'Alice', email: 'a@x' },
  { id: 2, name: 'Bob',   email: 'b@x' }
];
const columns = [
  { field: 'id',    label: 'ID' },
  { field: 'name',  label: 'Name' },
  { field: 'email', label: 'Email' }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  const cfg = { data, columns, ...extra };
  cfg.columns = (cfg.columns || []).map(c => ({ ...c }));
  return new TableCrafter('#t', cfg);
}

function headerLabels() {
  return Array.from(document.querySelectorAll('thead th')).map(th => th.textContent);
}

describe('addColumn', () => {
  test('appends a new column to the end of the rendered headers', () => {
    const table = makeTable();
    table.render();
    table.addColumn({ field: 'team', label: 'Team' });
    expect(headerLabels()).toEqual(['ID', 'Name', 'Email', 'Team']);
  });

  test('options.before inserts the new column before the named one', () => {
    const table = makeTable();
    table.render();
    table.addColumn({ field: 'team', label: 'Team' }, { before: 'name' });
    expect(headerLabels()).toEqual(['ID', 'Team', 'Name', 'Email']);
  });

  test('rejects columns missing a field name', () => {
    const table = makeTable();
    expect(() => table.addColumn({ label: 'Nope' })).toThrow(/field/i);
  });

  test('rejects duplicate field names', () => {
    const table = makeTable();
    expect(() => table.addColumn({ field: 'name', label: 'Dup' })).toThrow(/duplicate|already/i);
  });
});

describe('removeColumn', () => {
  test('removes the column from headers and body, returns true', () => {
    const table = makeTable();
    table.render();
    expect(table.removeColumn('name')).toBe(true);
    expect(headerLabels()).toEqual(['ID', 'Email']);
    expect(document.querySelector('td[data-field="name"]')).toBeNull();
  });

  test('returns false on miss; DOM unchanged', () => {
    const table = makeTable();
    table.render();
    const before = headerLabels();
    expect(table.removeColumn('ghost')).toBe(false);
    expect(headerLabels()).toEqual(before);
  });
});

describe('updateColumn', () => {
  test('shallow-merges the patch and re-renders', () => {
    const table = makeTable();
    table.render();
    expect(headerLabels()).toEqual(['ID', 'Name', 'Email']);

    table.updateColumn('name', { label: 'Renamed' });
    expect(headerLabels()).toEqual(['ID', 'Renamed', 'Email']);
  });

  test('preserves existing keys not in the patch', () => {
    const table = makeTable({
      columns: [{ field: 'name', label: 'Name', sortable: false, hidden: false }]
    });
    table.updateColumn('name', { label: 'Renamed' });
    const col = table.getColumn('name');
    expect(col.label).toBe('Renamed');
    expect(col.sortable).toBe(false);
    expect(col.hidden).toBe(false);
  });

  test('throws on unknown field', () => {
    const table = makeTable();
    expect(() => table.updateColumn('ghost', { label: 'X' })).toThrow(/ghost|unknown/i);
  });
});

describe('getColumn', () => {
  test('returns a defensive copy of the column object', () => {
    const table = makeTable();
    const snap = table.getColumn('name');
    expect(snap).toEqual({ field: 'name', label: 'Name' });
    snap.label = 'TAMPER';
    expect(table.getColumn('name').label).toBe('Name');
  });

  test('returns null for unknown field', () => {
    const table = makeTable();
    expect(table.getColumn('ghost')).toBeNull();
  });
});
