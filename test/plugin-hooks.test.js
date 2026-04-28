/**
 * Plugin lifecycle hooks (slice 2 of #38, stacked on PR #83 registry).
 *
 * Wires beforeRender / afterRender into the render path. Other documented
 * hooks (beforeLoad / afterLoad / beforeEdit / afterEdit / beforeSort /
 * afterSort / destroy) are exercisable via _fireHook(name, payload) but
 * not yet wired into their respective code paths — those land in follow-ups.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id', label: 'ID' }],
    data: [{ id: 1 }, { id: 2 }],
    ...extra
  });
}

describe('Plugin hooks: render path', () => {
  test('beforeRender fires when render() is called', () => {
    const beforeRender = jest.fn();
    const table = makeTable({ plugins: [{ name: 'p', hooks: { beforeRender } }] });

    beforeRender.mockClear();
    table.render();

    expect(beforeRender).toHaveBeenCalledTimes(1);
  });

  test('afterRender fires after render() finishes', () => {
    const calls = [];
    const table = makeTable({
      plugins: [{
        name: 'p',
        hooks: {
          beforeRender: () => calls.push('before'),
          afterRender: () => calls.push('after')
        }
      }]
    });

    calls.length = 0;
    table.render();

    expect(calls).toEqual(['before', 'after']);
  });

  test('multiple plugins on the same hook fire in registration order', () => {
    const seen = [];
    const make = name => ({ name, hooks: { beforeRender: () => seen.push(name) } });

    const table = makeTable({ plugins: [make('alpha'), make('beta'), make('gamma')] });

    seen.length = 0;
    table.render();

    expect(seen).toEqual(['alpha', 'beta', 'gamma']);
  });

  test('beforeRender returning false cancels render — afterRender does not fire', () => {
    const afterRender = jest.fn();
    const table = makeTable({
      plugins: [{
        name: 'cancel',
        hooks: {
          beforeRender: () => false,
          afterRender
        }
      }]
    });

    afterRender.mockClear();
    document.getElementById('t').innerHTML = '';
    table.render();

    expect(afterRender).not.toHaveBeenCalled();
    expect(document.getElementById('t').innerHTML).toBe('');
  });

  test('afterRender throwing is caught and does not break the table', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const table = makeTable({
      plugins: [{
        name: 'noisy',
        hooks: { afterRender: () => { throw new Error('boom'); } }
      }]
    });

    expect(() => table.render()).not.toThrow();
    expect(warnSpy).toHaveBeenCalled();

    warnSpy.mockRestore();
  });

  test('beforeRender throwing is treated as cancellation', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const afterRender = jest.fn();
    const table = makeTable({
      plugins: [{
        name: 'bad',
        hooks: {
          beforeRender: () => { throw new Error('nope'); },
          afterRender
        }
      }]
    });

    afterRender.mockClear();
    expect(() => table.render()).not.toThrow();
    expect(afterRender).not.toHaveBeenCalled();

    warnSpy.mockRestore();
  });
});

describe('Plugin hooks: _fireHook contract', () => {
  test('_fireHook returns true when no handler exists', () => {
    const table = makeTable();
    expect(table._fireHook('arbitraryName', {})).not.toBe(false);
  });

  test('_fireHook returns false if any handler returns false', () => {
    const table = makeTable({
      plugins: [
        { name: 'a', hooks: { custom: () => true } },
        { name: 'b', hooks: { custom: () => false } },
        { name: 'c', hooks: { custom: () => true } }
      ]
    });
    expect(table._fireHook('custom', {})).toBe(false);
  });

  test('_fireHook passes the payload to each handler', () => {
    const seen = [];
    const table = makeTable({
      plugins: [{ name: 'p', hooks: { custom: payload => seen.push(payload) } }]
    });

    table._fireHook('custom', { x: 1 });

    expect(seen).toEqual([{ x: 1 }]);
  });
});

describe('Plugin hooks: unuse cleans up', () => {
  test('unuse(name) removes the plugin from hook firing', () => {
    const beforeRender = jest.fn();
    const table = makeTable({ plugins: [{ name: 'p', hooks: { beforeRender } }] });

    table.unuse('p');
    beforeRender.mockClear();
    table.render();

    expect(beforeRender).not.toHaveBeenCalled();
  });
});
