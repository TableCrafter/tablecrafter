/**
 * Plugin lifecycle hooks: destroy (slice 6 of #38, completes the lifecycle).
 * Stacked on PR #94 (beforeLoad / afterLoad).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(plugins) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }],
    data: [{ id: 1 }, { id: 2 }],
    plugins
  });
}

describe('Plugin hooks: destroy', () => {
  test('destroy hook fires once when table.destroy() runs', () => {
    const destroy = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { destroy } }]);

    table.destroy();

    expect(destroy).toHaveBeenCalledTimes(1);
    expect(destroy).toHaveBeenCalledWith(expect.any(Object), table);
  });

  test('destroy fires for every registered plugin in registration order', () => {
    const calls = [];
    const make = name => ({ name, hooks: { destroy: () => calls.push(name) } });
    const table = makeTable([make('alpha'), make('beta'), make('gamma')]);

    table.destroy();

    expect(calls).toEqual(['alpha', 'beta', 'gamma']);
  });

  test('plugin throwing in destroy does not prevent later hooks or teardown', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const lateDestroy = jest.fn();

    const table = makeTable([
      { name: 'noisy', hooks: { destroy: () => { throw new Error('boom'); } } },
      { name: 'late',  hooks: { destroy: lateDestroy } }
    ]);

    expect(() => table.destroy()).not.toThrow();
    expect(lateDestroy).toHaveBeenCalled();
    expect(warnSpy).toHaveBeenCalled();
    // Container teardown still happened.
    expect(document.getElementById('t').innerHTML).toBe('');

    warnSpy.mockRestore();
  });
});
