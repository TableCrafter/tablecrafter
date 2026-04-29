/**
 * Column management foundation (slice 1 of #52).
 *
 * Lands the public state API: setColumnVisibility, setColumnOrder,
 * getVisibleColumns, and the column.hidden filter in render. Drag-to-
 * reorder UI, a "Manage columns" overlay, and pinned-columns sticky
 * behaviour remain queued (#52 / #50 / #53 follow-ups).
 */

const TableCrafter = require('../src/tablecrafter');

const data = [{ id: 1, name: 'Alice', email: 'a@x' }, { id: 2, name: 'Bob', email: 'b@x' }];
const columns = [
  { field: 'id',    label: 'ID' },
  { field: 'name',  label: 'Name' },
  { field: 'email', label: 'Email' }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  const cfg = { data, columns, ...extra };
  // Clone columns so per-test mutations (setColumnVisibility / setColumnOrder)
  // do not leak through the module-level constant into the next test.
  cfg.columns = (cfg.columns || []).map(c => ({ ...c }));
  return new TableCrafter('#t', cfg);
}

describe('Column management: hidden flag in render', () => {
  test('a column with hidden: true is omitted from the rendered table', () => {
    const table = makeTable({
      columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name', hidden: true },
        { field: 'email', label: 'Email' }
      ]
    });
    table.render();

    expect(document.querySelectorAll('thead th')).toHaveLength(2);
    expect(document.querySelector('th[data-field="name"]')).toBeNull();
    expect(document.querySelector('td[data-field="name"]')).toBeNull();
  });
});

describe('Column management: setColumnVisibility', () => {
  test('hides a single column and re-renders without it', () => {
    const table = makeTable();
    table.render();
    expect(document.querySelectorAll('thead th')).toHaveLength(3);

    table.setColumnVisibility('name', false);
    expect(document.querySelectorAll('thead th')).toHaveLength(2);
    expect(document.querySelector('th[data-field="name"]')).toBeNull();
  });

  test('shows a previously hidden column', () => {
    const table = makeTable({
      columns: [
        { field: 'id' },
        { field: 'name', hidden: true },
        { field: 'email' }
      ]
    });
    table.render();
    expect(document.querySelector('th[data-field="name"]')).toBeNull();

    table.setColumnVisibility('name', true);
    expect(document.querySelector('th[data-field="name"]')).not.toBeNull();
  });

  test('throws on unknown field', () => {
    const table = makeTable();
    expect(() => table.setColumnVisibility('ghost', false)).toThrow(/ghost|unknown/i);
  });
});

describe('Column management: setColumnOrder', () => {
  test('reorders the columns to match the supplied array', () => {
    const table = makeTable();
    table.render();
    expect(Array.from(document.querySelectorAll('thead th')).map(th => th.textContent))
      .toEqual(['ID', 'Name', 'Email']);

    table.setColumnOrder(['email', 'id', 'name']);
    expect(Array.from(document.querySelectorAll('thead th')).map(th => th.textContent))
      .toEqual(['Email', 'ID', 'Name']);
  });

  test('fields not listed in the new order are appended at the end', () => {
    const table = makeTable();
    table.setColumnOrder(['email']);
    expect(Array.from(document.querySelectorAll('thead th')).map(th => th.textContent))
      .toEqual(['Email', 'ID', 'Name']);
  });

  test('unknown fields in the new order are silently skipped', () => {
    const table = makeTable();
    table.setColumnOrder(['email', 'ghost', 'id']);
    expect(Array.from(document.querySelectorAll('thead th')).map(th => th.textContent))
      .toEqual(['Email', 'ID', 'Name']);
  });
});

describe('Column management: getVisibleColumns', () => {
  test('returns the visible columns in current order', () => {
    const table = makeTable({
      columns: [
        { field: 'id' },
        { field: 'name', hidden: true },
        { field: 'email' }
      ]
    });
    expect(table.getVisibleColumns().map(c => c.field)).toEqual(['id', 'email']);
  });

  test('mutating the returned array does not affect internal state', () => {
    const table = makeTable();
    const list = table.getVisibleColumns();
    list.length = 0;
    expect(table.getVisibleColumns()).toHaveLength(3);
  });
});
