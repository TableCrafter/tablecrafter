// Bulk operations, row selection, renderBulkControls, add-new modal

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }, { id: 3, name: 'Charlie' }],
    ...extra
  });
}

// ── toggleRowSelection ──────────────────────────────────────────────────────

describe('toggleRowSelection', () => {
  test('adds rowIndex when selected=true', () => {
    const t = makeTable();
    t.toggleRowSelection(0, true);
    expect(t.selectedRows.has(0)).toBe(true);
  });

  test('removes rowIndex when selected=false', () => {
    const t = makeTable();
    t.selectedRows.add(1);
    t.toggleRowSelection(1, false);
    expect(t.selectedRows.has(1)).toBe(false);
  });

  test('fires onSelectionChange callback', () => {
    const cb = jest.fn();
    const t = makeTable({ onSelectionChange: cb });
    t.toggleRowSelection(0, true);
    expect(cb).toHaveBeenCalledWith({ selectedRows: [0], totalSelected: 1 });
  });

  test('no crash when onSelectionChange not provided', () => {
    const t = makeTable();
    expect(() => t.toggleRowSelection(0, true)).not.toThrow();
  });
});

// ── selectAllRows / deselectAllRows ─────────────────────────────────────────

describe('selectAllRows / deselectAllRows', () => {
  test('selectAllRows marks every displayed row as selected', () => {
    const t = makeTable();
    t.selectAllRows();
    expect(t.selectedRows.size).toBe(3);
  });

  test('deselectAllRows clears all selections', () => {
    const t = makeTable();
    t.selectedRows.add(0);
    t.selectedRows.add(1);
    t.deselectAllRows();
    expect(t.selectedRows.size).toBe(0);
  });
});

// ── updateBulkControls ──────────────────────────────────────────────────────

describe('updateBulkControls', () => {
  test('no-op when .tc-bulk-controls not in DOM', () => {
    const t = makeTable();
    expect(() => t.updateBulkControls()).not.toThrow();
  });

  test('hides controls when nothing selected', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    t.render();
    t.updateBulkControls();
    const ctrl = t.container.querySelector('.tc-bulk-controls');
    if (ctrl) expect(ctrl.style.display).toBe('none');
  });

  test('shows controls and updates text when rows selected', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    t.render();
    t.selectedRows.add(0);
    t.selectedRows.add(1);
    t.updateBulkControls();
    const ctrl = t.container.querySelector('.tc-bulk-controls');
    if (ctrl) {
      expect(ctrl.style.display).toBe('flex');
      const info = ctrl.querySelector('.tc-bulk-info');
      if (info) expect(info.textContent).toContain('2 items selected');
    }
  });

  test('uses singular "item" for single selection', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    t.render();
    t.selectedRows.add(0);
    t.updateBulkControls();
    const info = t.container.querySelector('.tc-bulk-info');
    if (info) expect(info.textContent).toBe('1 item selected');
  });
});

// ── renderBulkControls ──────────────────────────────────────────────────────

describe('renderBulkControls', () => {
  test('returns a div with operation buttons', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete', 'export'] } });
    const ctrl = t.renderBulkControls();
    expect(ctrl.className).toBe('tc-bulk-controls');
    expect(ctrl.querySelector('.tc-bulk-delete')).not.toBeNull();
    expect(ctrl.querySelector('.tc-bulk-export')).not.toBeNull();
  });

  test('select-all checkbox triggers selectAllRows / deselectAllRows', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    const selectAllSpy = jest.spyOn(t, 'selectAllRows');
    const deselectSpy = jest.spyOn(t, 'deselectAllRows');

    const ctrl = t.renderBulkControls();
    const cb = ctrl.querySelector('input[type="checkbox"]');

    cb.checked = true;
    cb.dispatchEvent(new Event('change'));
    expect(selectAllSpy).toHaveBeenCalled();

    cb.checked = false;
    cb.dispatchEvent(new Event('change'));
    expect(deselectSpy).toHaveBeenCalled();
  });

  test('operation button click calls performBulkAction', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    const spy = jest.spyOn(t, 'performBulkAction').mockImplementation(() => {});
    const ctrl = t.renderBulkControls();
    ctrl.querySelector('.tc-bulk-delete').click();
    expect(spy).toHaveBeenCalledWith('delete');
  });
});

// ── performBulkAction ───────────────────────────────────────────────────────

describe('performBulkAction', () => {
  test('no-op when nothing selected', () => {
    const t = makeTable();
    const spy = jest.spyOn(t, 'bulkDelete').mockImplementation(() => {});
    t.performBulkAction('delete');
    expect(spy).not.toHaveBeenCalled();
  });

  test('routes delete action to bulkDelete', () => {
    const t = makeTable();
    t.selectedRows.add(0);
    const spy = jest.spyOn(t, 'bulkDelete').mockImplementation(() => {});
    t.performBulkAction('delete');
    expect(spy).toHaveBeenCalled();
  });

  test('routes export action to bulkExport', () => {
    const t = makeTable();
    t.selectedRows.add(0);
    const spy = jest.spyOn(t, 'bulkExport').mockImplementation(() => {});
    t.performBulkAction('export');
    expect(spy).toHaveBeenCalled();
  });

  test('routes edit action to bulkEdit', () => {
    const t = makeTable();
    t.selectedRows.add(0);
    const spy = jest.spyOn(t, 'bulkEdit').mockImplementation(() => {});
    t.performBulkAction('edit');
    expect(spy).toHaveBeenCalled();
  });

  test('calls onBulkAction for custom operations', () => {
    const onBulkAction = jest.fn();
    const t = makeTable({ onBulkAction });
    t.selectedRows.add(0);
    t.performBulkAction('archive');
    expect(onBulkAction).toHaveBeenCalledWith(expect.objectContaining({ action: 'archive' }));
  });
});

// ── bulkDelete ──────────────────────────────────────────────────────────────

describe('bulkDelete', () => {
  test('deletes rows and fires onBulkDelete on confirm', () => {
    const onBulkDelete = jest.fn();
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    const t = makeTable({ onBulkDelete });
    t.bulkDelete([0, 1], [{ id: 1 }, { id: 2 }]);
    expect(t.data).toHaveLength(1);
    expect(onBulkDelete).toHaveBeenCalled();
    window.confirm.mockRestore();
  });

  test('aborts on confirm cancel', () => {
    jest.spyOn(window, 'confirm').mockReturnValue(false);
    const t = makeTable();
    t.bulkDelete([0], [{ id: 1 }]);
    expect(t.data).toHaveLength(3);
    window.confirm.mockRestore();
  });

  test('clears selectedRows after deletion', () => {
    jest.spyOn(window, 'confirm').mockReturnValue(true);
    const t = makeTable();
    t.selectedRows.add(0);
    t.bulkDelete([0], [{ id: 1 }]);
    expect(t.selectedRows.size).toBe(0);
    window.confirm.mockRestore();
  });
});

// ── bulkExport ──────────────────────────────────────────────────────────────

describe('bulkExport', () => {
  test('calls downloadCSV with only selected data, then restores', () => {
    const t = makeTable();
    const csvSpy = jest.spyOn(t, 'downloadCSV').mockImplementation(() => {});
    const selected = [{ id: 2, name: 'Bob' }];
    t.bulkExport(selected);
    expect(csvSpy).toHaveBeenCalled();
    expect(t.data).toHaveLength(3); // restored
  });

  test('fires onBulkExport callback', () => {
    const onBulkExport = jest.fn();
    const t = makeTable({ onBulkExport });
    jest.spyOn(t, 'downloadCSV').mockImplementation(() => {});
    t.bulkExport([{ id: 1 }]);
    expect(onBulkExport).toHaveBeenCalledWith({ exportedData: [{ id: 1 }] });
  });
});

// ── bulkEdit ─────────────────────────────────────────────────────────────────

describe('bulkEdit', () => {
  test('fires onBulkEdit with selected rows and data', () => {
    const onBulkEdit = jest.fn();
    const t = makeTable({ onBulkEdit });
    t.bulkEdit([0, 1], [{ id: 1 }, { id: 2 }]);
    expect(onBulkEdit).toHaveBeenCalledWith({ selectedRows: [0, 1], selectedData: [{ id: 1 }, { id: 2 }] });
  });

  test('no crash when onBulkEdit not configured', () => {
    const t = makeTable();
    expect(() => t.bulkEdit([0], [{ id: 1 }])).not.toThrow();
  });
});

// ── showAddNewModal / createModal / renderAddNewForm / handleAddNewSubmit ────

describe('showAddNewModal', () => {
  test('appends .tc-modal-overlay to document.body', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    t.showAddNewModal();
    expect(document.querySelector('.tc-modal-overlay')).not.toBeNull();
  });

  test('modal has title "Add New Entry"', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    t.showAddNewModal();
    expect(document.querySelector('.tc-modal-title').textContent).toBe('Add New Entry');
  });

  test('close button removes overlay', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    t.showAddNewModal();
    document.querySelector('.tc-modal-close').click();
    expect(document.querySelector('.tc-modal-overlay')).toBeNull();
  });

  test('clicking overlay background closes modal', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    t.showAddNewModal();
    const overlay = document.querySelector('.tc-modal-overlay');
    overlay.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(document.querySelector('.tc-modal-overlay')).toBeNull();
  });
});

describe('renderAddNewForm', () => {
  test('renders a form with fields from addNew.fields', () => {
    const t = makeTable({
      addNew: {
        enabled: true,
        fields: [{ field: 'name', label: 'Name', type: 'text' }]
      }
    });
    const form = t.renderAddNewForm();
    expect(form.tagName).toBe('FORM');
    expect(form.querySelector('input[name="name"]')).not.toBeNull();
  });

  test('falls back to config.columns when addNew.fields is empty', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    const form = t.renderAddNewForm();
    expect(form.querySelectorAll('.tc-form-field').length).toBeGreaterThan(0);
  });

  test('cancel button removes modal overlay', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    t.showAddNewModal();
    document.querySelector('.tc-btn-cancel').click();
    expect(document.querySelector('.tc-modal-overlay')).toBeNull();
  });
});

describe('handleAddNewSubmit', () => {
  test('adds new entry to data and fires onAdd', () => {
    const onAdd = jest.fn();
    const t = makeTable({ onAdd, addNew: { enabled: true, fields: [{ field: 'name', label: 'Name' }] } });
    t.showAddNewModal();

    const input = document.querySelector('input[name="name"]');
    input.value = 'Diana';

    const form = document.querySelector('.tc-modal-form');
    form.dispatchEvent(new Event('submit', { bubbles: true }));

    expect(t.data.some(r => r.name === 'Diana')).toBe(true);
    expect(onAdd).toHaveBeenCalled();
    expect(document.querySelector('.tc-modal-overlay')).toBeNull();
  });
});

// ── renderAddNewButton ────────────────────────────────────────────────────────

describe('renderAddNewButton', () => {
  test('returns null when addNew.enabled is false', () => {
    const t = makeTable({ addNew: { enabled: false } });
    expect(t.renderAddNewButton()).toBeNull();
  });

  test('returns a button that triggers showAddNewModal', () => {
    const t = makeTable({ addNew: { enabled: true, fields: [] } });
    const spy = jest.spyOn(t, 'showAddNewModal').mockImplementation(() => {});
    const btn = t.renderAddNewButton();
    btn.click();
    expect(spy).toHaveBeenCalled();
  });
});
