/**
 * Context menu keyboard navigation (slice 3 of #44).
 * Stacked on PR #106 (event wiring + Escape / outside-click dismissal).
 *
 * - First menuitem receives focus when the menu opens.
 * - ArrowDown / ArrowUp cycle focus among menuitems, skipping separators
 *   and aria-disabled items.
 * - Enter and Space activate the focused item (calls onClick).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(items) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }],
    data: [{ id: 1 }],
    contextMenu: { enabled: true, items }
  });
}

afterEach(() => {
  document.querySelectorAll('.tc-context-menu').forEach(el => el.remove());
});

function press(key) {
  const ev = new KeyboardEvent('keydown', { key, bubbles: true });
  document.activeElement.dispatchEvent(ev);
  return ev;
}

describe('Context menu: keyboard navigation', () => {
  test('first menuitem is focused on open', () => {
    const table = makeTable([
      { id: 'a', label: 'Alpha', onClick: () => {} },
      { id: 'b', label: 'Beta',  onClick: () => {} }
    ]);
    table.openContextMenu('row', { rowIndex: 0 });

    const items = document.querySelectorAll('.tc-context-menu li[role="menuitem"]');
    expect(document.activeElement).toBe(items[0]);
  });

  test('ArrowDown moves focus to the next item, ArrowUp to previous', () => {
    const table = makeTable([
      { id: 'a', label: 'Alpha', onClick: () => {} },
      { id: 'b', label: 'Beta',  onClick: () => {} },
      { id: 'c', label: 'Gamma', onClick: () => {} }
    ]);
    table.openContextMenu('row', { rowIndex: 0 });

    const items = document.querySelectorAll('.tc-context-menu li[role="menuitem"]');
    press('ArrowDown');
    expect(document.activeElement).toBe(items[1]);
    press('ArrowDown');
    expect(document.activeElement).toBe(items[2]);
    press('ArrowUp');
    expect(document.activeElement).toBe(items[1]);
  });

  test('ArrowDown wraps to the first item past the end; ArrowUp wraps to the last', () => {
    const table = makeTable([
      { id: 'a', label: 'Alpha', onClick: () => {} },
      { id: 'b', label: 'Beta',  onClick: () => {} }
    ]);
    table.openContextMenu('row', { rowIndex: 0 });
    const items = document.querySelectorAll('.tc-context-menu li[role="menuitem"]');

    // Wrap forward.
    press('ArrowDown'); // → 1
    press('ArrowDown'); // wraps → 0
    expect(document.activeElement).toBe(items[0]);

    // Wrap backward.
    press('ArrowUp'); // wraps → last
    expect(document.activeElement).toBe(items[1]);
  });

  test('separators and aria-disabled items are skipped during navigation', () => {
    const table = makeTable([
      { id: 'a', label: 'Alpha', onClick: () => {} },
      'separator',
      { id: 'b', label: 'Beta',  onClick: () => {}, disabled: () => true },
      { id: 'c', label: 'Gamma', onClick: () => {} }
    ]);
    table.openContextMenu('row', { rowIndex: 0 });

    const allMenuitems = document.querySelectorAll('.tc-context-menu li[role="menuitem"]');
    const enabled = Array.from(allMenuitems).filter(li => li.getAttribute('aria-disabled') !== 'true');

    expect(document.activeElement).toBe(enabled[0]);
    press('ArrowDown');
    expect(document.activeElement).toBe(enabled[1]); // skipped the disabled one
  });

  test('Enter activates the focused item', () => {
    const onClick = jest.fn();
    const table = makeTable([
      { id: 'a', label: 'Alpha', onClick: () => {} },
      { id: 'b', label: 'Beta',  onClick }
    ]);
    table.openContextMenu('row', { rowIndex: 0 });

    press('ArrowDown'); // focus on Beta
    press('Enter');

    expect(onClick).toHaveBeenCalledTimes(1);
  });

  test('Space activates the focused item', () => {
    const onClick = jest.fn();
    const table = makeTable([{ id: 'a', label: 'Alpha', onClick }]);
    table.openContextMenu('row', { rowIndex: 0 });

    press(' ');

    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
