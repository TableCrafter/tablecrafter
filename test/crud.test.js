/**
 * Row-level CRUD method tests (issue #66)
 * Covers addRow / updateRow / removeRow with lifecycle callbacks,
 * API delegation, render side-effects, and permission gating.
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Row CRUD', () => {
  let container;
  let table;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
  });

  afterEach(() => {
    if (table && typeof table.destroy === 'function') {
      table.destroy();
    }
    table = null;
  });

  // ------------------------------------------------------------------
  // addRow
  // ------------------------------------------------------------------
  describe('addRow', () => {
    test('returns a promise resolving to the appended row, mutates data, fires onAdd, re-renders', async () => {
      const onAdd = jest.fn();
      table = new TableCrafter('#table-container', {
        data: [{ id: 1, name: 'A' }],
        columns: [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }],
        onAdd
      });
      const renderSpy = jest.spyOn(table, 'render');

      const result = await table.addRow({ id: 2, name: 'B' });

      expect(result).toEqual({ id: 2, name: 'B' });
      expect(table.getData()).toHaveLength(2);
      expect(table.getData()[1]).toEqual({ id: 2, name: 'B' });
      expect(renderSpy).toHaveBeenCalledTimes(1);
      expect(onAdd).toHaveBeenCalledTimes(1);
      expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({
        row: { id: 2, name: 'B' },
        index: 1
      }));
    });

    test('delegates to createEntry when api.baseUrl is configured', async () => {
      table = new TableCrafter('#table-container', {
        data: [],
        columns: [{ field: 'id', label: 'ID' }],
        api: { baseUrl: 'https://api.example.com' }
      });
      const createSpy = jest
        .spyOn(table, 'createEntry')
        .mockResolvedValue({ id: 99, name: 'srv' });

      const result = await table.addRow({ name: 'srv' });

      expect(createSpy).toHaveBeenCalledWith({ name: 'srv' });
      expect(result).toEqual({ id: 99, name: 'srv' });
      expect(table.getData()).toEqual([{ id: 99, name: 'srv' }]);
    });

    test('rejects with permission error when create is disallowed', async () => {
      table = new TableCrafter('#table-container', {
        data: [],
        columns: [{ field: 'id', label: 'ID' }],
        permissions: { enabled: true, create: ['admin'], view: ['*'], edit: ['*'], delete: ['*'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.addRow({ id: 1 })).rejects.toThrow(/permission/i);
      expect(table.getData()).toHaveLength(0);
    });
  });

  // ------------------------------------------------------------------
  // updateRow
  // ------------------------------------------------------------------
  describe('updateRow', () => {
    test('merges fields, fires onUpdate with previous, re-renders', async () => {
      const onUpdate = jest.fn();
      table = new TableCrafter('#table-container', {
        data: [{ id: 1, name: 'A', email: 'a@x' }],
        columns: [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }],
        onUpdate
      });
      const renderSpy = jest.spyOn(table, 'render');

      const result = await table.updateRow(0, { name: 'AA' });

      expect(result).toEqual({ id: 1, name: 'AA', email: 'a@x' });
      expect(table.getData()[0]).toEqual({ id: 1, name: 'AA', email: 'a@x' });
      expect(renderSpy).toHaveBeenCalledTimes(1);
      expect(onUpdate).toHaveBeenCalledWith(expect.objectContaining({
        row: { id: 1, name: 'AA', email: 'a@x' },
        index: 0,
        previous: { id: 1, name: 'A', email: 'a@x' }
      }));
    });

    test('delegates to updateEntry when api.baseUrl is configured', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1, name: 'A' }],
        columns: [{ field: 'id', label: 'ID' }],
        api: { baseUrl: 'https://api.example.com' }
      });
      const updateSpy = jest
        .spyOn(table, 'updateEntry')
        .mockResolvedValue({ id: 1, name: 'B' });

      await table.updateRow(0, { name: 'B' });

      expect(updateSpy).toHaveBeenCalledWith(0, { name: 'B' });
    });

    test('rejects with RangeError on out-of-range index', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }]
      });

      await expect(table.updateRow(5, { id: 2 })).rejects.toBeInstanceOf(RangeError);
      await expect(table.updateRow(-1, { id: 2 })).rejects.toBeInstanceOf(RangeError);
    });

    test('rejects with permission error when edit is disallowed', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1, name: 'A' }],
        columns: [{ field: 'id', label: 'ID' }],
        permissions: { enabled: true, edit: ['admin'], view: ['*'], create: ['*'], delete: ['*'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.updateRow(0, { name: 'B' })).rejects.toThrow(/permission/i);
      expect(table.getData()[0]).toEqual({ id: 1, name: 'A' });
    });
  });

  // ------------------------------------------------------------------
  // removeRow
  // ------------------------------------------------------------------
  describe('removeRow', () => {
    test('removes entry, fires onDelete, re-renders, resolves true', async () => {
      const onDelete = jest.fn();
      table = new TableCrafter('#table-container', {
        data: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }],
        columns: [{ field: 'id', label: 'ID' }],
        onDelete
      });
      const renderSpy = jest.spyOn(table, 'render');

      const result = await table.removeRow(0);

      expect(result).toBe(true);
      expect(table.getData()).toEqual([{ id: 2, name: 'B' }]);
      expect(renderSpy).toHaveBeenCalledTimes(1);
      expect(onDelete).toHaveBeenCalledWith(expect.objectContaining({
        row: { id: 1, name: 'A' },
        index: 0
      }));
    });

    test('honours { confirm: true } accept path', async () => {
      const onDelete = jest.fn();
      const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }],
        onDelete
      });

      const result = await table.removeRow(0, { confirm: true });

      expect(confirmSpy).toHaveBeenCalled();
      expect(result).toBe(true);
      expect(table.getData()).toHaveLength(0);
      expect(onDelete).toHaveBeenCalledTimes(1);

      confirmSpy.mockRestore();
    });

    test('honours { confirm: true } cancel path — no callback, no API, no render, resolves false', async () => {
      const onDelete = jest.fn();
      const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }],
        onDelete,
        api: { baseUrl: 'https://api.example.com' }
      });
      const deleteSpy = jest.spyOn(table, 'deleteEntry');
      const renderSpy = jest.spyOn(table, 'render');

      const result = await table.removeRow(0, { confirm: true });

      expect(result).toBe(false);
      expect(table.getData()).toEqual([{ id: 1 }]);
      expect(onDelete).not.toHaveBeenCalled();
      expect(deleteSpy).not.toHaveBeenCalled();
      expect(renderSpy).not.toHaveBeenCalled();

      confirmSpy.mockRestore();
    });

    test('delegates to deleteEntry when api.baseUrl is configured', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }],
        api: { baseUrl: 'https://api.example.com' }
      });
      const deleteSpy = jest.spyOn(table, 'deleteEntry').mockResolvedValue(true);

      await table.removeRow(0);

      expect(deleteSpy).toHaveBeenCalledWith(0);
    });

    test('rejects with RangeError on out-of-range index', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }]
      });

      await expect(table.removeRow(5)).rejects.toBeInstanceOf(RangeError);
      await expect(table.removeRow(-1)).rejects.toBeInstanceOf(RangeError);
    });

    test('rejects with permission error when delete is disallowed', async () => {
      table = new TableCrafter('#table-container', {
        data: [{ id: 1 }],
        columns: [{ field: 'id', label: 'ID' }],
        permissions: { enabled: true, delete: ['admin'], view: ['*'], edit: ['*'], create: ['*'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.removeRow(0)).rejects.toThrow(/permission/i);
      expect(table.getData()).toEqual([{ id: 1 }]);
    });
  });
});
