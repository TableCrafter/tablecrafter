/**
 * Context menu event wiring (slice 2 of #44).
 * Stacked on PR #105 (programmatic openContextMenu / closeContextMenu).
 *
 * - contextmenu event on <tr> / <th> / <td> opens the menu, calls
 *   preventDefault, infers scope from the target, and supplies context
 *   ({ rowIndex, field, row, value }).
 * - Outside click and Escape close the menu.
 * - Native context menu is not replaced when contextMenu.enabled is false.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }],
    contextMenu: {
      enabled: true,
      items: [{ id: 'a', label: 'Action', onClick: () => {} }]
    },
    ...extra
  });
}

afterEach(() => {
  document.querySelectorAll('.tc-context-menu').forEach(el => el.remove());
});

function dispatchContextMenu(target) {
  const ev = new Event('contextmenu', { bubbles: true, cancelable: true });
  ev.preventDefault = jest.fn();
  ev.clientX = 100;
  ev.clientY = 200;
  target.dispatchEvent(ev);
  return ev;
}

describe('contextmenu event wiring', () => {
  test('right-click on a <td> opens the menu with cell scope and context', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'cell', label: 'Cell action', onClick, scope: 'cell' }]
      }
    });
    table.render();

    const td = document.querySelector('td[data-field="name"]');
    const ev = dispatchContextMenu(td);

    expect(ev.preventDefault).toHaveBeenCalled();
    expect(document.querySelector('.tc-context-menu')).not.toBeNull();
    document.querySelector('.tc-context-menu li[role="menuitem"]').click();
    expect(onClick).toHaveBeenCalledTimes(1);
    expect(onClick.mock.calls[0][0].context).toEqual(
      expect.objectContaining({ scope: 'cell', field: 'name', rowIndex: 0, value: 'A' })
    );
  });

  test('right-click on a <th> opens the menu with header scope', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'h', label: 'Header action', onClick, scope: 'header' }]
      }
    });
    table.render();

    const th = document.querySelector('thead th');
    dispatchContextMenu(th);

    expect(document.querySelector('.tc-context-menu')).not.toBeNull();
    document.querySelector('.tc-context-menu li[role="menuitem"]').click();
    expect(onClick.mock.calls[0][0].context.scope).toBe('header');
  });

  test('right-click on a <tr> (not on a <td>) opens with row scope', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'r', label: 'Row action', onClick, scope: 'row' }]
      }
    });
    table.render();

    const tr = document.querySelectorAll('tbody tr')[1];
    // Dispatch on tr itself rather than a child td. The handler should
    // fall back to row scope.
    const ev = new Event('contextmenu', { bubbles: true, cancelable: true });
    ev.preventDefault = jest.fn();
    Object.defineProperty(ev, 'target', { value: tr });
    tr.dispatchEvent(ev);

    document.querySelector('.tc-context-menu li[role="menuitem"]').click();
    expect(onClick.mock.calls[0][0].context).toEqual(
      expect.objectContaining({ scope: 'row', rowIndex: 1 })
    );
  });

  test('contextmenu does NOT preventDefault when contextMenu.enabled is false', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      columns: [{ field: 'name' }],
      data: [{ name: 'A' }]
    });
    table.render();

    const td = document.querySelector('td[data-field="name"]');
    const ev = dispatchContextMenu(td);

    expect(ev.preventDefault).not.toHaveBeenCalled();
    expect(document.querySelector('.tc-context-menu')).toBeNull();
  });
});

describe('Context menu dismissal', () => {
  test('Escape closes the menu', () => {
    const table = makeTable();
    table.openContextMenu('row', { rowIndex: 0 });
    expect(document.querySelector('.tc-context-menu')).not.toBeNull();

    const ev = new KeyboardEvent('keydown', { key: 'Escape' });
    document.dispatchEvent(ev);

    expect(document.querySelector('.tc-context-menu')).toBeNull();
  });

  test('outside click closes the menu', () => {
    const table = makeTable();
    table.openContextMenu('row', { rowIndex: 0 });
    expect(document.querySelector('.tc-context-menu')).not.toBeNull();

    const outside = document.createElement('div');
    document.body.appendChild(outside);
    outside.click();

    expect(document.querySelector('.tc-context-menu')).toBeNull();
  });

  test('click inside the menu does not close it before the item handler runs', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'a', label: 'Action', onClick }]
      }
    });
    table.openContextMenu('row', { rowIndex: 0 });

    document.querySelector('.tc-context-menu li[role="menuitem"]').click();
    expect(onClick).toHaveBeenCalledTimes(1);
  });
});
