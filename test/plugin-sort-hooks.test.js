/**
 * Plugin lifecycle hooks: beforeSort / afterSort (slice 4 of #38).
 * Stacked on PR #92 (beforeEdit / afterEdit).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(plugins) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 3, name: 'C' }, { id: 1, name: 'A' }, { id: 2, name: 'B' }],
    sortable: true,
    plugins
  });
}

describe('Plugin hooks: beforeSort / afterSort', () => {
  test('beforeSort fires with { field, order } before sorting', () => {
    const beforeSort = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { beforeSort } }]);

    table.sort('id');

    expect(beforeSort).toHaveBeenCalledTimes(1);
    expect(beforeSort).toHaveBeenCalledWith(
      expect.objectContaining({ field: 'id', order: 'asc' }),
      table
    );
  });

  test('afterSort fires with { field, order } after sorting', () => {
    const afterSort = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { afterSort } }]);

    table.sort('id');

    expect(afterSort).toHaveBeenCalledWith(
      expect.objectContaining({ field: 'id', order: 'asc' }),
      table
    );
    expect(table.getData().map(r => r.id)).toEqual([1, 2, 3]);
  });

  test('beforeSort returning false cancels — data order unchanged, afterSort not called', () => {
    const afterSort = jest.fn();
    const table = makeTable([{
      name: 'guard',
      hooks: {
        beforeSort: () => false,
        afterSort
      }
    }]);

    const before = table.getData().map(r => r.id);
    table.sort('id');
    expect(table.getData().map(r => r.id)).toEqual(before);
    expect(afterSort).not.toHaveBeenCalled();
  });

  test('beforeSort sees the toggle order on the second sort of the same field', () => {
    const beforeSort = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { beforeSort } }]);

    table.sort('id');
    table.sort('id');

    expect(beforeSort).toHaveBeenLastCalledWith(
      expect.objectContaining({ field: 'id', order: 'desc' }),
      table
    );
  });

  test('afterSort throwing is caught and does not break the sort', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const table = makeTable([{
      name: 'noisy',
      hooks: { afterSort: () => { throw new Error('boom'); } }
    }]);

    expect(() => table.sort('id')).not.toThrow();
    expect(table.getData().map(r => r.id)).toEqual([1, 2, 3]);
    expect(warnSpy).toHaveBeenCalled();

    warnSpy.mockRestore();
  });
});
