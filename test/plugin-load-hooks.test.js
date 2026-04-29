/**
 * Plugin lifecycle hooks: beforeLoad / afterLoad (slice 5 of #38).
 * Stacked on PR #93 (beforeSort / afterSort).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(plugins) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: 'https://api.example.com/data',
    plugins
  });
}

beforeEach(() => {
  global.fetch = jest.fn();
});

describe('Plugin hooks: beforeLoad / afterLoad', () => {
  test('beforeLoad fires with { source } before the fetch', async () => {
    const beforeLoad = jest.fn();
    const order = [];
    fetch.mockImplementation(() => {
      order.push('fetch');
      return Promise.resolve({ ok: true, json: async () => [{ id: 1, name: 'A' }] });
    });

    const table = makeTable([{
      name: 'p',
      hooks: { beforeLoad: payload => { beforeLoad(payload); order.push('beforeLoad'); } }
    }]);
    await table.loadData();

    expect(order[0]).toBe('beforeLoad');
    expect(beforeLoad).toHaveBeenCalledWith(
      expect.objectContaining({ source: 'https://api.example.com/data' })
    );
  });

  test('afterLoad fires with { data } after the data has been processed', async () => {
    const payload = [{ id: 1, name: 'X' }, { id: 2, name: 'Y' }];
    fetch.mockResolvedValue({ ok: true, json: async () => payload });

    const afterLoad = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { afterLoad } }]);
    await table.loadData();

    expect(afterLoad).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.any(Array) }),
      table
    );
    expect(afterLoad.mock.calls[0][0].data).toEqual(payload);
    expect(table.getData()).toEqual(payload);
  });

  test('beforeLoad returning false cancels — fetch is not called, afterLoad not fired', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => [] });
    const afterLoad = jest.fn();

    const table = makeTable([{
      name: 'guard',
      hooks: {
        beforeLoad: () => false,
        afterLoad
      }
    }]);
    await table.loadData();

    expect(fetch).not.toHaveBeenCalled();
    expect(afterLoad).not.toHaveBeenCalled();
  });

  test('afterLoad throwing is caught and does not break the load', async () => {
    fetch.mockResolvedValue({ ok: true, json: async () => [{ id: 1 }] });
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

    const table = makeTable([{
      name: 'noisy',
      hooks: { afterLoad: () => { throw new Error('boom'); } }
    }]);
    await expect(table.loadData()).resolves.not.toThrow();
    expect(table.getData()).toEqual([{ id: 1 }]);
    expect(warnSpy).toHaveBeenCalled();

    warnSpy.mockRestore();
  });
});
