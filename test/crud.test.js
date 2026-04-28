/**
 * Row-level CRUD tests for issue #66 / parent #64
 *
 * Covers public methods:
 *   - table.addRow(rowData)
 *   - table.updateRow(index, rowData)
 *   - table.removeRow(index, options?)
 *
 * Lifecycle callbacks: onAdd, onUpdate, onDelete
 * API delegation, permission gating, and bounds checking.
 */

const TableCrafter = require('../src/tablecrafter');

const baseColumns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' },
  { field: 'email', label: 'Email' }
];

function seed() {
  return [
    { id: 1, name: 'Alice', email: 'alice@example.com' },
    { id: 2, name: 'Bob', email: 'bob@example.com' },
    { id: 3, name: 'Carol', email: 'carol@example.com' }
  ];
}

function setup(extraConfig = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data: seed(),
    columns: baseColumns,
    ...extraConfig
  });
}

describe('Row-level CRUD: addRow', () => {
  test('exposes addRow as a function', () => {
    const table = setup();
    expect(typeof table.addRow).toBe('function');
  });

  test('appends row to data and resolves to the added entry', async () => {
    const table = setup();
    const before = table.data.length;
    const newRow = { id: 4, name: 'Dave', email: 'dave@example.com' };

    const result = await table.addRow(newRow);

    expect(table.data.length).toBe(before + 1);
    expect(table.data[table.data.length - 1]).toMatchObject(newRow);
    expect(result).toMatchObject(newRow);
  });

  test('re-renders after adding', async () => {
    const table = setup();
    const renderSpy = jest.spyOn(table, 'render');
    await table.addRow({ id: 4, name: 'Dave' });
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires onAdd lifecycle callback with { row, index }', async () => {
    const onAdd = jest.fn();
    const table = setup({ onAdd });
    const newRow = { id: 4, name: 'Dave' };

    await table.addRow(newRow);

    expect(onAdd).toHaveBeenCalledTimes(1);
    const payload = onAdd.mock.calls[0][0];
    expect(payload).toEqual(expect.objectContaining({
      row: expect.objectContaining({ id: 4, name: 'Dave' }),
      index: 3
    }));
  });

  test('delegates to createEntry when api.baseUrl is configured', async () => {
    const table = setup({
      api: { baseUrl: 'https://api.example.com', endpoints: { create: '/create' } }
    });
    const apiResponse = { id: 99, name: 'Server' };
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => apiResponse
    });

    const result = await table.addRow({ name: 'Server' });

    expect(fetch).toHaveBeenCalledWith(
      'https://api.example.com/create',
      expect.objectContaining({ method: 'POST' })
    );
    expect(result).toMatchObject(apiResponse);
    expect(table.data[table.data.length - 1]).toMatchObject(apiResponse);
  });

  test('rejects when permissions enabled and user lacks create role', async () => {
    const table = setup({
      permissions: { enabled: true, view: ['*'], create: ['admin'], edit: ['*'], delete: ['*'] }
    });
    table.setCurrentUser({ id: 7, roles: ['viewer'] });

    await expect(table.addRow({ id: 4, name: 'Dave' })).rejects.toThrow(/permission/i);
  });
});

describe('Row-level CRUD: updateRow', () => {
  test('exposes updateRow as a function', () => {
    const table = setup();
    expect(typeof table.updateRow).toBe('function');
  });

  test('merges fields into entry and resolves to updated row', async () => {
    const table = setup();
    const result = await table.updateRow(1, { name: 'Bobby' });

    expect(table.data[1]).toEqual({ id: 2, name: 'Bobby', email: 'bob@example.com' });
    expect(result).toEqual({ id: 2, name: 'Bobby', email: 'bob@example.com' });
  });

  test('re-renders after update', async () => {
    const table = setup();
    const renderSpy = jest.spyOn(table, 'render');
    await table.updateRow(0, { name: 'Alicia' });
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires onUpdate with { row, index, previous }', async () => {
    const onUpdate = jest.fn();
    const table = setup({ onUpdate });
    const previousSnapshot = { ...table.data[1] };

    await table.updateRow(1, { name: 'Bobby' });

    expect(onUpdate).toHaveBeenCalledTimes(1);
    const payload = onUpdate.mock.calls[0][0];
    expect(payload.index).toBe(1);
    expect(payload.row).toEqual(expect.objectContaining({ name: 'Bobby' }));
    expect(payload.previous).toEqual(previousSnapshot);
  });

  test('delegates to updateEntry when api.baseUrl is configured', async () => {
    const table = setup({
      api: { baseUrl: 'https://api.example.com', endpoints: { update: '/update' } }
    });
    const apiResponse = { id: 2, name: 'Bobby', email: 'bob@example.com' };
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => apiResponse
    });

    const result = await table.updateRow(1, { name: 'Bobby' });

    expect(fetch).toHaveBeenCalledWith(
      'https://api.example.com/update/2',
      expect.objectContaining({ method: 'PUT' })
    );
    expect(result).toMatchObject(apiResponse);
  });

  test('rejects with RangeError on out-of-range index', async () => {
    const table = setup();
    await expect(table.updateRow(99, { name: 'X' })).rejects.toBeInstanceOf(RangeError);
    await expect(table.updateRow(-1, { name: 'X' })).rejects.toBeInstanceOf(RangeError);
  });

  test('rejects when permissions enabled and user lacks edit role', async () => {
    const table = setup({
      permissions: { enabled: true, view: ['*'], edit: ['admin'], create: ['*'], delete: ['*'] }
    });
    table.setCurrentUser({ id: 7, roles: ['viewer'] });

    await expect(table.updateRow(0, { name: 'X' })).rejects.toThrow(/permission/i);
  });
});

describe('Row-level CRUD: removeRow', () => {
  test('exposes removeRow as a function', () => {
    const table = setup();
    expect(typeof table.removeRow).toBe('function');
  });

  test('removes row at index and resolves to true', async () => {
    const table = setup();
    const result = await table.removeRow(1);

    expect(result).toBe(true);
    expect(table.data.length).toBe(2);
    expect(table.data.find(r => r.id === 2)).toBeUndefined();
  });

  test('re-renders after removal', async () => {
    const table = setup();
    const renderSpy = jest.spyOn(table, 'render');
    await table.removeRow(0);
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires onDelete with { row, index }', async () => {
    const onDelete = jest.fn();
    const table = setup({ onDelete });
    const removedSnapshot = { ...table.data[2] };

    await table.removeRow(2);

    expect(onDelete).toHaveBeenCalledTimes(1);
    const payload = onDelete.mock.calls[0][0];
    expect(payload.index).toBe(2);
    expect(payload.row).toEqual(removedSnapshot);
  });

  test('delegates to deleteEntry when api.baseUrl is configured', async () => {
    const table = setup({
      api: { baseUrl: 'https://api.example.com', endpoints: { delete: '/delete' } }
    });
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({})
    });

    const result = await table.removeRow(0);

    expect(fetch).toHaveBeenCalledWith(
      'https://api.example.com/delete/1',
      expect.objectContaining({ method: 'DELETE' })
    );
    expect(result).toBe(true);
  });

  test('honours { confirm: true } - cancel path resolves false without mutation', async () => {
    const onDelete = jest.fn();
    const table = setup({ onDelete });
    const renderSpy = jest.spyOn(table, 'render');
    const beforeLen = table.data.length;
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);

    const result = await table.removeRow(0, { confirm: true });

    expect(confirmSpy).toHaveBeenCalled();
    expect(result).toBe(false);
    expect(table.data.length).toBe(beforeLen);
    expect(onDelete).not.toHaveBeenCalled();
    expect(renderSpy).not.toHaveBeenCalled();

    confirmSpy.mockRestore();
  });

  test('honours { confirm: true } - accept path proceeds normally', async () => {
    const onDelete = jest.fn();
    const table = setup({ onDelete });
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);

    const result = await table.removeRow(0, { confirm: true });

    expect(result).toBe(true);
    expect(table.data.length).toBe(2);
    expect(onDelete).toHaveBeenCalledTimes(1);

    confirmSpy.mockRestore();
  });

  test('rejects with RangeError on out-of-range index', async () => {
    const table = setup();
    await expect(table.removeRow(99)).rejects.toBeInstanceOf(RangeError);
    await expect(table.removeRow(-1)).rejects.toBeInstanceOf(RangeError);
  });

  test('rejects when permissions enabled and user lacks delete role', async () => {
    const table = setup({
      permissions: { enabled: true, view: ['*'], edit: ['*'], create: ['*'], delete: ['admin'] }
    });
    table.setCurrentUser({ id: 7, roles: ['viewer'] });

    await expect(table.removeRow(0)).rejects.toThrow(/permission/i);
  });
});

describe('Row-level CRUD: existing API preserved', () => {
  test('createEntry/updateEntry/deleteEntry/bulkDelete still exist', () => {
    const table = setup();
    expect(typeof table.createEntry).toBe('function');
    expect(typeof table.updateEntry).toBe('function');
    expect(typeof table.deleteEntry).toBe('function');
    expect(typeof table.bulkDelete).toBe('function');
  });
});
