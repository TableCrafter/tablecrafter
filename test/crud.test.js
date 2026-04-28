/**
 * Row-level CRUD methods tests (issue #66, sub-issue of #64)
 *
 * Covers public addRow / updateRow / removeRow API:
 *   - mutation of internal data
 *   - re-render
 *   - lifecycle callbacks (onAdd / onUpdate / onDelete)
 *   - API delegation when config.api.baseUrl is set
 *   - permission gating
 *   - bounds checking
 *   - removeRow confirm flow (accept + cancel)
 */

const TableCrafter = require('../src/tablecrafter');

const baseColumns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' },
  { field: 'email', label: 'Email' }
];

const seedData = () => [
  { id: 1, name: 'Alice', email: 'alice@example.com' },
  { id: 2, name: 'Bob', email: 'bob@example.com' }
];

const makeTable = (overrides = {}) => {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data: seedData(),
    columns: baseColumns,
    ...overrides
  });
};

describe('TableCrafter row-level CRUD', () => {
  describe('addRow', () => {
    test('exposes addRow as a function', () => {
      const table = makeTable();
      expect(typeof table.addRow).toBe('function');
    });

    test('appends row, fires onAdd, and re-renders', async () => {
      const onAdd = jest.fn();
      const table = makeTable({ onAdd });
      const renderSpy = jest.spyOn(table, 'render');

      const newRow = { id: 3, name: 'Carol', email: 'carol@example.com' };
      const result = await table.addRow(newRow);

      expect(table.getData()).toHaveLength(3);
      expect(table.getData()[2]).toEqual(newRow);
      expect(result).toEqual(newRow);
      expect(onAdd).toHaveBeenCalledTimes(1);
      expect(onAdd).toHaveBeenCalledWith(expect.objectContaining({
        row: newRow,
        index: 2
      }));
      expect(renderSpy).toHaveBeenCalled();
    });

    test('delegates to API createEntry when api.baseUrl is set', async () => {
      const table = makeTable({
        api: {
          baseUrl: 'https://api.example.com',
          endpoints: { data: '/data', create: '/create', update: '/update', delete: '/delete', lookup: '/lookup' }
        }
      });
      const newRow = { name: 'Dave', email: 'dave@example.com' };
      const apiResponse = { id: 99, ...newRow };
      const createSpy = jest.spyOn(table, 'createEntry').mockResolvedValue(apiResponse);

      const result = await table.addRow(newRow);

      expect(createSpy).toHaveBeenCalledWith(newRow);
      expect(result).toEqual(apiResponse);
    });

    test('rejects when permissions deny create', async () => {
      const table = makeTable({
        permissions: { enabled: true, view: ['*'], edit: ['*'], delete: ['*'], create: ['admin'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.addRow({ id: 9, name: 'X', email: 'x@x.com' }))
        .rejects.toThrow(/permission/i);
      expect(table.getData()).toHaveLength(2);
    });
  });

  describe('updateRow', () => {
    test('exposes updateRow as a function', () => {
      const table = makeTable();
      expect(typeof table.updateRow).toBe('function');
    });

    test('merges fields, fires onUpdate with previous, re-renders', async () => {
      const onUpdate = jest.fn();
      const table = makeTable({ onUpdate });
      const renderSpy = jest.spyOn(table, 'render');
      const previous = { ...table.getData()[0] };

      const result = await table.updateRow(0, { name: 'Alicia' });

      expect(table.getData()[0]).toEqual({ ...previous, name: 'Alicia' });
      expect(result).toEqual({ ...previous, name: 'Alicia' });
      expect(onUpdate).toHaveBeenCalledWith(expect.objectContaining({
        row: { ...previous, name: 'Alicia' },
        index: 0,
        previous
      }));
      expect(renderSpy).toHaveBeenCalled();
    });

    test('delegates to API updateEntry when api.baseUrl is set', async () => {
      const table = makeTable({
        api: {
          baseUrl: 'https://api.example.com',
          endpoints: { data: '/data', create: '/create', update: '/update', delete: '/delete', lookup: '/lookup' }
        }
      });
      const apiResponse = { id: 1, name: 'Alicia', email: 'alice@example.com' };
      const updateSpy = jest.spyOn(table, 'updateEntry').mockResolvedValue(apiResponse);

      const result = await table.updateRow(0, { name: 'Alicia' });

      expect(updateSpy).toHaveBeenCalledWith(0, { name: 'Alicia' });
      expect(result).toEqual(apiResponse);
    });

    test('throws RangeError on out-of-range index', async () => {
      const table = makeTable();
      await expect(table.updateRow(99, { name: 'Z' }))
        .rejects.toThrow(RangeError);
      await expect(table.updateRow(-1, { name: 'Z' }))
        .rejects.toThrow(RangeError);
    });

    test('rejects when permissions deny edit', async () => {
      const table = makeTable({
        permissions: { enabled: true, view: ['*'], edit: ['admin'], delete: ['*'], create: ['*'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.updateRow(0, { name: 'X' }))
        .rejects.toThrow(/permission/i);
      expect(table.getData()[0].name).toBe('Alice');
    });
  });

  describe('removeRow', () => {
    test('exposes removeRow as a function', () => {
      const table = makeTable();
      expect(typeof table.removeRow).toBe('function');
    });

    test('removes entry, fires onDelete, re-renders, resolves true', async () => {
      const onDelete = jest.fn();
      const table = makeTable({ onDelete });
      const renderSpy = jest.spyOn(table, 'render');
      const target = { ...table.getData()[0] };

      const result = await table.removeRow(0);

      expect(result).toBe(true);
      expect(table.getData()).toHaveLength(1);
      expect(table.getData()[0].id).toBe(2);
      expect(onDelete).toHaveBeenCalledWith(expect.objectContaining({
        row: target,
        index: 0
      }));
      expect(renderSpy).toHaveBeenCalled();
    });

    test('honours { confirm: true } and resolves false on cancel', async () => {
      const onDelete = jest.fn();
      const table = makeTable({ onDelete });
      const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);

      const result = await table.removeRow(0, { confirm: true });

      expect(result).toBe(false);
      expect(table.getData()).toHaveLength(2);
      expect(onDelete).not.toHaveBeenCalled();
      confirmSpy.mockRestore();
    });

    test('honours { confirm: true } and proceeds on accept', async () => {
      const onDelete = jest.fn();
      const table = makeTable({ onDelete });
      const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);

      const result = await table.removeRow(0, { confirm: true });

      expect(result).toBe(true);
      expect(table.getData()).toHaveLength(1);
      expect(onDelete).toHaveBeenCalledTimes(1);
      confirmSpy.mockRestore();
    });

    test('delegates to API deleteEntry when api.baseUrl is set', async () => {
      const table = makeTable({
        api: {
          baseUrl: 'https://api.example.com',
          endpoints: { data: '/data', create: '/create', update: '/update', delete: '/delete', lookup: '/lookup' }
        }
      });
      const deleteSpy = jest.spyOn(table, 'deleteEntry').mockResolvedValue(true);

      const result = await table.removeRow(0);

      expect(deleteSpy).toHaveBeenCalledWith(0);
      expect(result).toBe(true);
    });

    test('throws RangeError on out-of-range index', async () => {
      const table = makeTable();
      await expect(table.removeRow(99)).rejects.toThrow(RangeError);
      await expect(table.removeRow(-1)).rejects.toThrow(RangeError);
    });

    test('rejects when permissions deny delete', async () => {
      const table = makeTable({
        permissions: { enabled: true, view: ['*'], edit: ['*'], delete: ['admin'], create: ['*'] }
      });
      table.setCurrentUser({ id: 1, roles: ['viewer'] });

      await expect(table.removeRow(0)).rejects.toThrow(/permission/i);
      expect(table.getData()).toHaveLength(2);
    });
  });
});
