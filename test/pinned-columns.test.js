/**
 * Pinned columns foundation (slice 1 of #53).
 *
 * - column.pinned: 'left' | 'right' | false (default false)
 * - Render order: left-pinned, unpinned, right-pinned (each in declaration order)
 * - tc-pinned-left / tc-pinned-right class on header + body cells
 * - pinColumn(field, side) public API; getPinnedColumns() snapshot
 *
 * Sticky positioning CSS, scroll handling, drag-to-pin UI, and shadow / divider
 * styling are intentionally consumer-side and remain queued under #53.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [{ id: 1, name: 'Alice', email: 'a@x', team: 'core' }];

function makeTable(columns, extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  const cfg = { data, columns, ...extra };
  cfg.columns = (cfg.columns || []).map(c => ({ ...c })); // clone to isolate tests
  return new TableCrafter('#t', cfg);
}

function headerLabels() {
  return Array.from(document.querySelectorAll('thead th')).map(th => th.textContent);
}

describe('Pinned columns: render order', () => {
  test('left-pinned columns render first regardless of declaration position', () => {
    const table = makeTable([
      { field: 'id',    label: 'ID' },
      { field: 'name',  label: 'Name' },
      { field: 'email', label: 'Email', pinned: 'left' },
      { field: 'team',  label: 'Team' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['Email', 'ID', 'Name', 'Team']);
  });

  test('right-pinned columns render last regardless of declaration position', () => {
    const table = makeTable([
      { field: 'id',    label: 'ID' },
      { field: 'name',  label: 'Name', pinned: 'right' },
      { field: 'email', label: 'Email' },
      { field: 'team',  label: 'Team' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['ID', 'Email', 'Team', 'Name']);
  });

  test('left + right pins coexist with unpinned in the middle', () => {
    const table = makeTable([
      { field: 'id',    label: 'ID' },
      { field: 'name',  label: 'Name', pinned: 'left' },
      { field: 'email', label: 'Email' },
      { field: 'team',  label: 'Team', pinned: 'right' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['Name', 'ID', 'Email', 'Team']);
  });
});

describe('Pinned columns: classes', () => {
  test('pinned headers carry tc-pinned-left / tc-pinned-right classes', () => {
    const table = makeTable([
      { field: 'id',    label: 'ID', pinned: 'left' },
      { field: 'name',  label: 'Name' },
      { field: 'email', label: 'Email', pinned: 'right' }
    ]);
    table.render();

    const ths = document.querySelectorAll('thead th');
    expect(ths[0].classList.contains('tc-pinned-left')).toBe(true);
    expect(ths[2].classList.contains('tc-pinned-right')).toBe(true);
    expect(ths[1].classList.contains('tc-pinned-left')).toBe(false);
    expect(ths[1].classList.contains('tc-pinned-right')).toBe(false);
  });

  test('pinned body cells carry the same classes', () => {
    const table = makeTable([
      { field: 'id', label: 'ID', pinned: 'left' },
      { field: 'name', label: 'Name' }
    ]);
    table.render();

    const idTd = document.querySelector('td[data-field="id"]');
    expect(idTd.classList.contains('tc-pinned-left')).toBe(true);
  });
});

describe('pinColumn() / getPinnedColumns()', () => {
  test('pinColumn(field, "left") moves a column to the front and re-renders', () => {
    const table = makeTable([
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['ID', 'Name', 'Email']);

    table.pinColumn('email', 'left');
    expect(headerLabels()).toEqual(['Email', 'ID', 'Name']);
  });

  test('pinColumn(field, false) unpins a column and restores its original spot', () => {
    const table = makeTable([
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name', pinned: 'left' },
      { field: 'email', label: 'Email' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['Name', 'ID', 'Email']);

    table.pinColumn('name', false);
    expect(headerLabels()).toEqual(['ID', 'Name', 'Email']);
  });

  test('pinColumn() throws on unknown field', () => {
    const table = makeTable([{ field: 'id', label: 'ID' }]);
    expect(() => table.pinColumn('ghost', 'left')).toThrow(/ghost|unknown/i);
  });

  test('getPinnedColumns returns { left, right } as defensive copies', () => {
    const table = makeTable([
      { field: 'id', label: 'ID', pinned: 'left' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email', pinned: 'right' }
    ]);
    const snap = table.getPinnedColumns();
    expect(snap.left.map(c => c.field)).toEqual(['id']);
    expect(snap.right.map(c => c.field)).toEqual(['email']);

    snap.left.length = 0;
    expect(table.getPinnedColumns().left).toHaveLength(1);
  });
});

describe('Pinned + hidden compose', () => {
  test('a hidden + pinned column does not render', () => {
    const table = makeTable([
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name', pinned: 'left', hidden: true },
      { field: 'email', label: 'Email' }
    ]);
    table.render();
    expect(headerLabels()).toEqual(['ID', 'Email']);
    expect(document.querySelector('th[data-field="name"]')).toBeNull();
  });
});
