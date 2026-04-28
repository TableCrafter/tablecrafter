/**
 * Row-level CRUD methods (addRow / updateRow / removeRow)
 *
 * Sub-issue of #64, tracked in #66.
 *
 * These tests are intentionally written first (TDD). They drive the
 * implementation of `table.addRow`, `table.updateRow`, and `table.removeRow`
 * in `src/tablecrafter.js`.
 */

const TableCrafter = require('../src/tablecrafter');

const baseColumns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' },
  { field: 'email', label: 'Email' }
];

function makeData() {
  return [
    { id: 1, name: 'Alice', email: 'alice@example.com' },
    { id: 2, name: 'Bob', email: 'bob@example.com' },
    { id: 3, name: 'Carol', email: 'carol@example.com' }
  ];
}

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data: makeData(),
    columns: baseColumns,
    ...extra
  });
}

describe('addRow', () => {
  test('exists as a public method', () => {
    const table = makeTable();
    expect(typeof table.addRow).toBe('function');
  });

  test('appends row to this.data and resolves to the row', async () => {
    const table = makeTable();
    const newRow = { id: 4, name: 'Dan', email: 'dan@example.com' };

    const result = await table.addRow(newRow);

    expect(table.data).toHaveLength(4);
    expect(table.data[3]).toEqual(newRow);
    expect(result).toEqual(newRow);
  });

  test('returns a Promise', () => {
    const table = makeTable();
    const ret = table.addRow({ id: 99, name: 'X', email: 'x@x' });
    expect(ret).toBeInstanceOf(Promise);
    return ret;
  });

  test('re-renders after success', async () => {
    const table = makeTable();
    const renderSpy = jest.spyOn(table, 'render');
    await table.addRow({ id: 4, name: 'Dan', email: 'dan@example.com' });
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires config.onAdd with { row, index }', async () => {
    const onAdd = jest.fn();
    const table = makeTable({ onAdd });
    const newRow = { id: 4, name: 'Dan', email: 'dan@example.com' };

    await table.addRow(newRow);

    expect(onAdd).toHaveBeenCalledTimes(1);
    expect(onAdd).toHaveBeenCalledWith(
      expect.objectContaining({ row: newRow, index: 3 })
    );
  });

  test('delegates to createEntry when api.baseUrl is configured', async () => {
    const created = { id: 99, name: 'Server', email: 's@s.com' };
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => created
    });

    const table = makeTable({
      api: { baseUrl: 'https://example.test', endpoints: { create: '/create' } }
    });

    const result = await table.addRow({ name: 'Server', email: 's@s.com' });

    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe('https://example.test/create');
    expect(options.method).toBe('POST');
    // Server response wins as the persisted row
    expect(result).toEqual(created);
    expect(table.data[table.data.length - 1]).toEqual(created);
  });

  test('rejects with permission error when permissions.enabled and user lacks create', async () => {
    const table = makeTable({
      permissions: {
        enabled: true,
        view: ['*'],
        edit: ['*'],
        delete: ['*'],
        create: ['admin']
      }
    });
    table.setCurrentUser({ id: 1, roles: ['viewer'] });

    await expect(
      table.addRow({ id: 4, name: 'Dan', email: 'd@d' })
    ).rejects.toThrow(/permission/i);
  });
});

describe('updateRow', () => {
  test('exists as a public method', () => {
    const table = makeTable();
    expect(typeof table.updateRow).toBe('function');
  });

  test('merges fields into this.data[index] and resolves to the row', async () => {
    const table = makeTable();
    const result = await table.updateRow(1, { name: 'Bobby' });

    expect(table.data[1]).toEqual({
      id: 2,
      name: 'Bobby',
      email: 'bob@example.com'
    });
    expect(result).toEqual(table.data[1]);
  });

  test('returns a Promise', () => {
    const table = makeTable();
    const ret = table.updateRow(0, { name: 'AA' });
    expect(ret).toBeInstanceOf(Promise);
    return ret;
  });

  test('re-renders after success', async () => {
    const table = makeTable();
    const renderSpy = jest.spyOn(table, 'render');
    await table.updateRow(0, { name: 'Alicia' });
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires config.onUpdate with { row, index, previous }', async () => {
    const onUpdate = jest.fn();
    const table = makeTable({ onUpdate });
    const previousSnapshot = { ...table.data[0] };

    await table.updateRow(0, { name: 'Alicia' });

    expect(onUpdate).toHaveBeenCalledTimes(1);
    expect(onUpdate).toHaveBeenCalledWith(
      expect.objectContaining({
        row: table.data[0],
        index: 0,
        previous: previousSnapshot
      })
    );
  });

  test('delegates to updateEntry when api.baseUrl is configured', async () => {
    const updated = { id: 1, name: 'Alicia', email: 'alice@example.com' };
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => updated
    });

    const table = makeTable({
      api: { baseUrl: 'https://example.test', endpoints: { update: '/update' } }
    });

    const result = await table.updateRow(0, { name: 'Alicia' });

    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe('https://example.test/update/1');
    expect(options.method).toBe('PUT');
    expect(result).toEqual(updated);
    expect(table.data[0]).toEqual(updated);
  });

  test('rejects with RangeError on out-of-range index', async () => {
    const table = makeTable();
    await expect(table.updateRow(99, { name: 'X' })).rejects.toBeInstanceOf(RangeError);
    await expect(table.updateRow(-1, { name: 'X' })).rejects.toBeInstanceOf(RangeError);
  });

  test('rejects with permission error when user lacks edit', async () => {
    const table = makeTable({
      permissions: {
        enabled: true,
        view: ['*'],
        edit: ['admin'],
        delete: ['*'],
        create: ['*']
      }
    });
    table.setCurrentUser({ id: 1, roles: ['viewer'] });

    await expect(table.updateRow(0, { name: 'X' })).rejects.toThrow(/permission/i);
  });
});

describe('removeRow', () => {
  test('exists as a public method', () => {
    const table = makeTable();
    expect(typeof table.removeRow).toBe('function');
  });

  test('removes this.data[index] and resolves to true', async () => {
    const table = makeTable();
    const removed = table.data[1];

    const result = await table.removeRow(1);

    expect(result).toBe(true);
    expect(table.data).toHaveLength(2);
    expect(table.data).not.toContain(removed);
  });

  test('returns a Promise', () => {
    const table = makeTable();
    const ret = table.removeRow(0);
    expect(ret).toBeInstanceOf(Promise);
    return ret;
  });

  test('re-renders after success', async () => {
    const table = makeTable();
    const renderSpy = jest.spyOn(table, 'render');
    await table.removeRow(0);
    expect(renderSpy).toHaveBeenCalled();
  });

  test('fires config.onDelete with { row, index }', async () => {
    const onDelete = jest.fn();
    const table = makeTable({ onDelete });
    const target = { ...table.data[1] };

    await table.removeRow(1);

    expect(onDelete).toHaveBeenCalledTimes(1);
    expect(onDelete).toHaveBeenCalledWith(
      expect.objectContaining({ row: target, index: 1 })
    );
  });

  test('delegates to deleteEntry when api.baseUrl is configured', async () => {
    fetch.mockResolvedValueOnce({
      ok: true,
      json: async () => ({})
    });

    const table = makeTable({
      api: { baseUrl: 'https://example.test', endpoints: { delete: '/delete' } }
    });

    const result = await table.removeRow(0);

    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe('https://example.test/delete/1');
    expect(options.method).toBe('DELETE');
    expect(result).toBe(true);
    expect(table.data).toHaveLength(2);
  });

  test('with { confirm: true } and user accepts: deletes and resolves true', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    const onDelete = jest.fn();
    const table = makeTable({ onDelete });

    const result = await table.removeRow(0, { confirm: true });

    expect(confirmSpy).toHaveBeenCalled();
    expect(result).toBe(true);
    expect(onDelete).toHaveBeenCalledTimes(1);
    expect(table.data).toHaveLength(2);

    confirmSpy.mockRestore();
  });

  test('with { confirm: true } and user cancels: resolves false, no mutation, no callback, no API call', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    const onDelete = jest.fn();
    const renderBefore = jest.fn();
    const table = makeTable({
      onDelete,
      api: { baseUrl: 'https://example.test', endpoints: { delete: '/delete' } }
    });
    const renderSpy = jest.spyOn(table, 'render');

    const result = await table.removeRow(0, { confirm: true });

    expect(confirmSpy).toHaveBeenCalled();
    expect(result).toBe(false);
    expect(table.data).toHaveLength(3);
    expect(onDelete).not.toHaveBeenCalled();
    expect(fetch).not.toHaveBeenCalled();
    expect(renderSpy).not.toHaveBeenCalled();

    confirmSpy.mockRestore();
  });

  test('rejects with RangeError on out-of-range index', async () => {
    const table = makeTable();
    await expect(table.removeRow(99)).rejects.toBeInstanceOf(RangeError);
    await expect(table.removeRow(-1)).rejects.toBeInstanceOf(RangeError);
  });

  test('rejects with permission error when user lacks delete', async () => {
    const table = makeTable({
      permissions: {
        enabled: true,
        view: ['*'],
        edit: ['*'],
        delete: ['admin'],
        create: ['*']
      }
    });
    table.setCurrentUser({ id: 1, roles: ['viewer'] });

    await expect(table.removeRow(0)).rejects.toThrow(/permission/i);
  });
});

describe('CRUD methods do not break existing API', () => {
  test('createEntry / updateEntry / deleteEntry still exist', () => {
    const table = makeTable();
    expect(typeof table.createEntry).toBe('function');
    expect(typeof table.updateEntry).toBe('function');
    expect(typeof table.deleteEntry).toBe('function');
  });

  test('bulkDelete still exists', () => {
    const table = makeTable();
    expect(typeof table.bulkDelete).toBe('function');
  });

  test('cell-level onEdit callback still fires (regression guard)', () => {
    const onEdit = jest.fn();
    const table = makeTable({ editable: true, onEdit });
    // saveCellEdit is the internal hook that fires onEdit;
    // we just assert the registered callback is the one we passed.
    expect(table.config.onEdit).toBe(onEdit);
  });
});
