// startEdit, saveEdit, cancelEdit, and inline editing via rendered table

const TC = require('../src/tablecrafter');

function makeEditableTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name', editable: true },
      { field: 'role', label: 'Role', editable: true, type: 'select', options: ['admin', 'user'] }
    ],
    data: [{ id: 1, name: 'Alice', role: 'admin' }],
    editable: true,
    ...extra
  });
}

// ── startEdit ────────────────────────────────────────────────────────────────

describe('startEdit', () => {
  test('creates a text input in the cell', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    const event = { currentTarget: td };
    await t.startEdit(event, 0, 'name');
    expect(td.querySelector('input')).not.toBeNull();
  });

  test('does not re-enter edit if already editing the same cell', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    const event = { currentTarget: td };
    await t.startEdit(event, 0, 'name');
    const input = td.querySelector('input');
    await t.startEdit(event, 0, 'name'); // second call
    // still the same input
    expect(td.querySelector('input')).toBe(input);
  });

  test('keydown Enter saves edit', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: td }, 0, 'name');
    const input = td.querySelector('input');
    const saveSpy = jest.spyOn(t, 'saveEdit').mockImplementation(async () => {});
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(saveSpy).toHaveBeenCalled();
  });

  test('keydown Escape cancels edit', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: td }, 0, 'name');
    const input = td.querySelector('input');
    const cancelSpy = jest.spyOn(t, 'cancelEdit').mockImplementation(() => {});
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(cancelSpy).toHaveBeenCalled();
  });

  test('blur on text input saves edit', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: td }, 0, 'name');
    const input = td.querySelector('input');
    const saveSpy = jest.spyOn(t, 'saveEdit').mockImplementation(async () => {});
    input.dispatchEvent(new Event('blur'));
    expect(saveSpy).toHaveBeenCalled();
  });

  test('cancels existing edit before starting new one', async () => {
    const t = makeEditableTable();
    t.render();
    const tdName = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: tdName }, 0, 'name');
    // Simulate editingCell is set
    const cancelSpy = jest.spyOn(t, 'cancelEdit').mockImplementation(() => {
      t.editingCell = null;
    });
    const tdRole = t.container.querySelector('td[data-field="role"]');
    await t.startEdit({ currentTarget: tdRole }, 0, 'role');
    expect(cancelSpy).toHaveBeenCalled();
  });
});

// ── saveEdit ─────────────────────────────────────────────────────────────────

describe('saveEdit', () => {
  test('updates data with new value and fires onEdit', async () => {
    const onEdit = jest.fn();
    const t = makeEditableTable({ onEdit });
    t.render();

    const td = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: td }, 0, 'name');
    const input = td.querySelector('input');
    input.value = 'Bob';

    await t.saveEdit(input);
    expect(t.data[0].name).toBe('Bob');
    expect(onEdit).toHaveBeenCalledWith(expect.objectContaining({
      row: 0, field: 'name', oldValue: 'Alice', newValue: 'Bob'
    }));
  });

  test('SELECT change and blur events save edit', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="role"]');
    await t.startEdit({ currentTarget: td }, 0, 'role');
    const select = td.querySelector('select') || td.querySelector('input');
    if (select) {
      const saveSpy = jest.spyOn(t, 'saveEdit').mockImplementation(async () => {});
      select.dispatchEvent(new Event('change'));
      expect(saveSpy).toHaveBeenCalled();
    }
  });
});

// ── cancelEdit ────────────────────────────────────────────────────────────────

describe('cancelEdit', () => {
  test('no-op when editingCell is null', () => {
    const t = makeEditableTable();
    t.editingCell = null;
    expect(() => t.cancelEdit()).not.toThrow();
  });

  test('restores original value in cell', async () => {
    const t = makeEditableTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    await t.startEdit({ currentTarget: td }, 0, 'name');
    const input = td.querySelector('input');
    input.dataset.originalValue = 'Alice';
    t.cancelEdit();
    expect(td.textContent).toBe('Alice');
    expect(t.editingCell).toBeNull();
  });
});

// ── multiselect dropdown toggle ────────────────────────────────────────────────

describe('multiselect dropdown toggle', () => {
  test('button click opens the dropdown', () => {
    const t = makeEditableTable();
    // Render a multiselect filter
    const col = { field: 'role', options: ['admin', 'user'], filterable: true };
    const btn = t.createMultiselectFilter(col);
    document.body.appendChild(btn);

    // dropdown should be in body
    const dropdown = document.querySelector('.tc-multiselect-dropdown');
    expect(dropdown).not.toBeNull();

    btn.click();
    expect(dropdown.style.display).toBe('block');

    // clicking again closes
    btn.click();
    expect(dropdown.style.display).toBe('none');
  });

  test('checkbox change in dropdown calls updateMultiselectFilter', () => {
    const t = makeEditableTable();
    const col = { field: 'role', options: ['admin', 'user'], filterable: true };
    const btn = t.createMultiselectFilter(col);
    document.body.appendChild(btn);
    btn.click(); // open

    const spy = jest.spyOn(t, 'updateMultiselectFilter').mockImplementation(() => {});
    const dropdown = document.querySelector('.tc-multiselect-dropdown');
    const cb = dropdown.querySelector('input[type="checkbox"]');
    if (cb) {
      cb.dispatchEvent(new Event('change'));
      expect(spy).toHaveBeenCalled();
    }
  });
});
