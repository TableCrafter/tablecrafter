/**
 * Cell range selection foundation (slice 1 of #43).
 *
 * Lands the public state API + render decoration. Shift+click event wiring,
 * fill handle gestures, multi-rectangle selections, and clipboard writes
 * remain queued under #43.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'Alice', email: 'alice@x' },
  { id: 2, name: 'Bob',   email: 'bob@x'   },
  { id: 3, name: 'Carol', email: 'carol@x' }
];
const columns = [
  { field: 'id' },
  { field: 'name' },
  { field: 'email' }
];

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('selectRange / getSelection', () => {
  test('selectRange normalises into { startRow, endRow, startCol, endCol }', () => {
    const t = makeTable();
    t.selectRange({ row: 0, field: 'id' }, { row: 1, field: 'name' });
    expect(t.getSelection()).toEqual({
      startRow: 0, endRow: 1, startCol: 'id', endCol: 'name',
      anchor: { row: 0, field: 'id' }, focus: { row: 1, field: 'name' }
    });
  });

  test('backwards anchor / focus normalises to min/max', () => {
    const t = makeTable();
    t.selectRange({ row: 2, field: 'email' }, { row: 0, field: 'id' });
    const sel = t.getSelection();
    expect(sel.startRow).toBe(0);
    expect(sel.endRow).toBe(2);
    expect(sel.startCol).toBe('id');
    expect(sel.endCol).toBe('email');
  });

  test('getSelection returns null before any selection', () => {
    expect(makeTable().getSelection()).toBeNull();
  });

  test('getSelection returns a defensive copy', () => {
    const t = makeTable();
    t.selectRange({ row: 0, field: 'id' }, { row: 1, field: 'name' });
    const snap = t.getSelection();
    snap.startRow = 99;
    expect(t.getSelection().startRow).toBe(0);
  });

  test('clearSelection resets to null', () => {
    const t = makeTable();
    t.selectRange({ row: 0, field: 'id' }, { row: 0, field: 'id' });
    expect(t.getSelection()).not.toBeNull();
    t.clearSelection();
    expect(t.getSelection()).toBeNull();
  });
});

describe('Render decoration', () => {
  test('cells inside the selection carry tc-selected; outside do not', () => {
    const t = makeTable();
    t.selectRange({ row: 0, field: 'id' }, { row: 1, field: 'name' });
    t.render();

    // Inside: id/name on rows 0-1.
    expect(document.querySelector('tr[data-row-index="0"] td[data-field="id"]').classList.contains('tc-selected')).toBe(true);
    expect(document.querySelector('tr[data-row-index="0"] td[data-field="name"]').classList.contains('tc-selected')).toBe(true);
    expect(document.querySelector('tr[data-row-index="1"] td[data-field="id"]').classList.contains('tc-selected')).toBe(true);
    expect(document.querySelector('tr[data-row-index="1"] td[data-field="name"]').classList.contains('tc-selected')).toBe(true);

    // Outside: row 2 / email column.
    expect(document.querySelector('tr[data-row-index="2"] td[data-field="id"]').classList.contains('tc-selected')).toBe(false);
    expect(document.querySelector('tr[data-row-index="0"] td[data-field="email"]').classList.contains('tc-selected')).toBe(false);
  });

  test('anchor cell also carries tc-selected-anchor', () => {
    const t = makeTable();
    t.selectRange({ row: 1, field: 'name' }, { row: 2, field: 'email' });
    t.render();

    const anchor = document.querySelector('tr[data-row-index="1"] td[data-field="name"]');
    expect(anchor.classList.contains('tc-selected')).toBe(true);
    expect(anchor.classList.contains('tc-selected-anchor')).toBe(true);

    const otherSelected = document.querySelector('tr[data-row-index="2"] td[data-field="email"]');
    expect(otherSelected.classList.contains('tc-selected')).toBe(true);
    expect(otherSelected.classList.contains('tc-selected-anchor')).toBe(false);
  });
});

describe('copySelectionAsTSV', () => {
  test('returns rows joined by \\n and columns by \\t', () => {
    const t = makeTable();
    t.selectRange({ row: 0, field: 'id' }, { row: 1, field: 'name' });
    expect(t.copySelectionAsTSV()).toBe('1\tAlice\n2\tBob');
  });

  test('returns empty string when no selection', () => {
    expect(makeTable().copySelectionAsTSV()).toBe('');
  });
});
