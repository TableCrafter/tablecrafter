/**
 * Context menu — programmatic foundation (slice 1 of #44).
 *
 * Lands the config surface, `openContextMenu(scope, context)`,
 * `closeContextMenu()`, and the public `menuItems` resolution. The actual
 * `contextmenu` event listener wiring, keyboard navigation, touch long-press,
 * and viewport-flip positioning are deferred to follow-up PRs and remain
 * tracked on #44.
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

afterEach(() => {
  document.querySelectorAll('.tc-context-menu').forEach(el => el.remove());
});

describe('Context menu: disabled by default', () => {
  test('openContextMenu does nothing when contextMenu.enabled is false (default)', () => {
    const table = makeTable();
    table.openContextMenu('row', { rowIndex: 0 });
    expect(document.querySelector('.tc-context-menu')).toBeNull();
  });
});

describe('Context menu: custom items', () => {
  test('renders <ul role="menu"> with one <li role="menuitem"> per item', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [
          { id: 'a', label: 'Alpha', onClick },
          { id: 'b', label: 'Beta',  onClick }
        ]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });

    const menu = document.querySelector('.tc-context-menu');
    expect(menu).not.toBeNull();
    expect(menu.getAttribute('role')).toBe('menu');

    const items = menu.querySelectorAll('li[role="menuitem"]');
    expect(items).toHaveLength(2);
    expect(items[0].textContent).toBe('Alpha');
    expect(items[1].textContent).toBe('Beta');
  });

  test('clicking an item invokes onClick with { context }', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'a', label: 'Alpha', onClick }]
      }
    });

    table.openContextMenu('row', { rowIndex: 1, row: { id: 2, name: 'B' } });
    document.querySelector('.tc-context-menu li[role="menuitem"]').click();

    expect(onClick).toHaveBeenCalledTimes(1);
    expect(onClick.mock.calls[0][0].context).toEqual(
      expect.objectContaining({ scope: 'row', rowIndex: 1, row: { id: 2, name: 'B' } })
    );
  });

  test('visible: false omits the item', () => {
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [
          { id: 'a', label: 'Alpha', onClick: () => {} },
          { id: 'b', label: 'Beta',  onClick: () => {}, visible: () => false }
        ]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });
    const items = document.querySelectorAll('.tc-context-menu li[role="menuitem"]');
    expect(items).toHaveLength(1);
    expect(items[0].textContent).toBe('Alpha');
  });

  test('disabled: true marks the item aria-disabled and skips onClick', () => {
    const onClick = jest.fn();
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'a', label: 'Alpha', onClick, disabled: () => true }]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });
    const item = document.querySelector('.tc-context-menu li[role="menuitem"]');
    expect(item.getAttribute('aria-disabled')).toBe('true');

    item.click();
    expect(onClick).not.toHaveBeenCalled();
  });

  test('item.scope filters which scopes show the item', () => {
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [
          { id: 'r', label: 'Row only',    onClick: () => {}, scope: 'row' },
          { id: 'h', label: 'Header only', onClick: () => {}, scope: 'header' },
          { id: 'a', label: 'Anywhere',    onClick: () => {} }
        ]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });
    let labels = Array.from(document.querySelectorAll('.tc-context-menu li')).map(li => li.textContent);
    expect(labels).toEqual(['Row only', 'Anywhere']);

    table.closeContextMenu();
    table.openContextMenu('header', { field: 'name' });
    labels = Array.from(document.querySelectorAll('.tc-context-menu li')).map(li => li.textContent);
    expect(labels).toEqual(['Header only', 'Anywhere']);
  });
});

describe('Context menu: separator', () => {
  test('"separator" entries render an <li role="separator"> without text', () => {
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [
          { id: 'a', label: 'Alpha', onClick: () => {} },
          'separator',
          { id: 'b', label: 'Beta',  onClick: () => {} }
        ]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });
    const seps = document.querySelectorAll('.tc-context-menu li[role="separator"]');
    expect(seps).toHaveLength(1);
    expect(seps[0].textContent.trim()).toBe('');
  });
});

describe('Context menu: closeContextMenu()', () => {
  test('removes the menu from the DOM', () => {
    const table = makeTable({
      contextMenu: {
        enabled: true,
        items: [{ id: 'a', label: 'Alpha', onClick: () => {} }]
      }
    });

    table.openContextMenu('row', { rowIndex: 0 });
    expect(document.querySelector('.tc-context-menu')).not.toBeNull();

    table.closeContextMenu();
    expect(document.querySelector('.tc-context-menu')).toBeNull();
  });

  test('closeContextMenu when no menu is open is a no-op', () => {
    const table = makeTable({
      contextMenu: { enabled: true, items: [{ id: 'a', label: 'Alpha', onClick: () => {} }] }
    });
    expect(() => table.closeContextMenu()).not.toThrow();
  });
});
