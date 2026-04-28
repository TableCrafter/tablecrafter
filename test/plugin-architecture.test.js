/**
 * Plugin architecture foundation (slice of #38).
 *
 * This PR lands only:
 *   - the public registry: use() / unuse() / getPlugins()
 *   - config.plugins: [...] auto-registration during construction
 *
 * Hook firing (beforeRender / afterRender / beforeEdit / etc.), cancel-on-false
 * semantics, and error-isolation are intentionally deferred to follow-up PRs
 * and remain tracked in the AC posted on #38.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { columns: [{ field: 'id' }], ...extra });
}

describe('Plugin registry: use()', () => {
  test('calls install(table, options) exactly once and registers the plugin', () => {
    const table = makeTable();
    const install = jest.fn();
    const plugin = { name: 'audit', version: '1.0.0', install };

    table.use(plugin, { mode: 'silent' });

    expect(install).toHaveBeenCalledTimes(1);
    expect(install).toHaveBeenCalledWith(table, { mode: 'silent' });
    expect(table.getPlugins().map(p => p.name)).toContain('audit');
  });

  test('throws when registering a duplicate name', () => {
    const table = makeTable();
    const plugin = { name: 'dup', install: () => {} };
    table.use(plugin);
    expect(() => table.use(plugin)).toThrow(/already registered|duplicate/i);
  });

  test('rejects plugins missing a name', () => {
    const table = makeTable();
    expect(() => table.use({ install: () => {} })).toThrow(/name/i);
  });

  test('install is optional — a hook-only plugin still registers', () => {
    const table = makeTable();
    const plugin = { name: 'hooks-only', hooks: {} };
    expect(() => table.use(plugin)).not.toThrow();
    expect(table.getPlugins().map(p => p.name)).toContain('hooks-only');
  });
});

describe('Plugin registry: unuse()', () => {
  test('calls uninstall(table) and removes the plugin from the registry', () => {
    const table = makeTable();
    const uninstall = jest.fn();
    table.use({ name: 'p1', install: () => {}, uninstall });

    const removed = table.unuse('p1');

    expect(removed).toBe(true);
    expect(uninstall).toHaveBeenCalledWith(table);
    expect(table.getPlugins().map(p => p.name)).not.toContain('p1');
  });

  test('returns false when no such plugin exists', () => {
    const table = makeTable();
    expect(table.unuse('ghost')).toBe(false);
  });

  test('uninstall is optional — unuse still succeeds and removes it', () => {
    const table = makeTable();
    table.use({ name: 'p2', install: () => {} });
    expect(table.unuse('p2')).toBe(true);
    expect(table.getPlugins().map(p => p.name)).not.toContain('p2');
  });

  test('unuse then use of the same name succeeds', () => {
    const table = makeTable();
    const plugin = { name: 'cycle', install: () => {} };
    table.use(plugin);
    table.unuse('cycle');
    expect(() => table.use(plugin)).not.toThrow();
    expect(table.getPlugins().map(p => p.name)).toContain('cycle');
  });
});

describe('Plugin registry: getPlugins()', () => {
  test('returns a defensive copy that does not affect internal state', () => {
    const table = makeTable();
    table.use({ name: 'a', install: () => {} });
    table.use({ name: 'b', install: () => {} });

    const list = table.getPlugins();
    list.length = 0;

    expect(table.getPlugins().map(p => p.name)).toEqual(['a', 'b']);
  });

  test('reports name, version, and resolved options', () => {
    const table = makeTable();
    table.use({ name: 'p', version: '2.1.0', install: () => {} }, { foo: 1 });

    const [record] = table.getPlugins();
    expect(record.name).toBe('p');
    expect(record.version).toBe('2.1.0');
    expect(record.options).toEqual({ foo: 1 });
  });
});

describe('Plugin registry: config.plugins auto-registration', () => {
  test('plugins listed in config.plugins are registered before first render', () => {
    const installA = jest.fn();
    const installB = jest.fn();

    const table = makeTable({
      plugins: [
        { name: 'a', install: installA },
        [{ name: 'b', install: installB }, { count: 5 }]
      ]
    });

    expect(installA).toHaveBeenCalled();
    expect(installB).toHaveBeenCalledWith(table, { count: 5 });
    expect(table.getPlugins().map(p => p.name)).toEqual(['a', 'b']);
  });

  test('registration preserves declaration order', () => {
    const calls = [];
    const make = name => ({ name, install: () => calls.push(name) });

    makeTable({ plugins: [make('first'), make('second'), make('third')] });

    expect(calls).toEqual(['first', 'second', 'third']);
  });
});
