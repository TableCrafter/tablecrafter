/**
 * Plugin lifecycle hooks: beforeEdit / afterEdit (slice 3 of #38).
 * Stacked on PR #84 (beforeRender / afterRender + _fireHook helper).
 *
 * Wires the cell-edit code path so plugins can observe and gate single-cell
 * edits. beforeLoad / afterLoad / beforeSort / afterSort / destroy remain
 * tracked for follow-up PRs.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(plugins) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name', editable: true }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }],
    editable: true,
    plugins
  });
}

function fakeEditElement(rowIndex, field, oldValue, newValue) {
  const el = document.createElement('input');
  el.dataset.rowIndex = String(rowIndex);
  el.dataset.field = field;
  el.dataset.originalValue = oldValue;
  el.value = newValue;
  return el;
}

describe('Plugin hooks: beforeEdit / afterEdit', () => {
  test('beforeEdit fires with { rowIndex, field, value } before the mutation', async () => {
    const beforeEdit = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { beforeEdit } }]);

    const el = fakeEditElement(0, 'name', 'Alice', 'AliceX');
    document.body.appendChild(el);
    await table.saveEdit(el);

    expect(beforeEdit).toHaveBeenCalledTimes(1);
    expect(beforeEdit).toHaveBeenCalledWith(
      expect.objectContaining({ rowIndex: 0, field: 'name', value: 'AliceX' }),
      table
    );
  });

  test('afterEdit fires with { rowIndex, field, oldValue, newValue } after a successful edit', async () => {
    const afterEdit = jest.fn();
    const table = makeTable([{ name: 'p', hooks: { afterEdit } }]);

    const el = fakeEditElement(0, 'name', 'Alice', 'AliceY');
    document.body.appendChild(el);
    await table.saveEdit(el);

    expect(afterEdit).toHaveBeenCalledWith(
      expect.objectContaining({
        rowIndex: 0,
        field: 'name',
        oldValue: 'Alice',
        newValue: 'AliceY'
      }),
      table
    );
    expect(table.getData()[0].name).toBe('AliceY');
  });

  test('beforeEdit returning false cancels — data unchanged, afterEdit not called', async () => {
    const afterEdit = jest.fn();
    const table = makeTable([{
      name: 'guard',
      hooks: {
        beforeEdit: () => false,
        afterEdit
      }
    }]);

    const el = fakeEditElement(0, 'name', 'Alice', 'BLOCKED');
    document.body.appendChild(el);
    await table.saveEdit(el);

    expect(table.getData()[0].name).toBe('Alice');
    expect(afterEdit).not.toHaveBeenCalled();
  });

  test('multiple plugins on the same hook fire in registration order', async () => {
    const calls = [];
    const make = name => ({
      name,
      hooks: { beforeEdit: () => { calls.push(name); } }
    });
    const table = makeTable([make('alpha'), make('beta'), make('gamma')]);

    const el = fakeEditElement(0, 'name', 'Alice', 'X');
    document.body.appendChild(el);
    await table.saveEdit(el);

    expect(calls).toEqual(['alpha', 'beta', 'gamma']);
  });

  test('afterEdit throwing is caught and does not break the edit', async () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const table = makeTable([{
      name: 'noisy',
      hooks: { afterEdit: () => { throw new Error('boom'); } }
    }]);

    const el = fakeEditElement(0, 'name', 'Alice', 'AliceZ');
    document.body.appendChild(el);
    await expect(table.saveEdit(el)).resolves.not.toThrow();
    expect(table.getData()[0].name).toBe('AliceZ');
    expect(warnSpy).toHaveBeenCalled();

    warnSpy.mockRestore();
  });
});
