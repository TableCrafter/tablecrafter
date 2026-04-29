/**
 * Theming foundation (slice 1 of #49).
 *
 * Lands the JS surface: `config.theme`, `config.themeVariables`,
 * `setTheme()`, `getTheme()`. The actual CSS definitions for
 * --tc-bg / --tc-text / etc. and the dark / high-contrast overrides
 * ship separately so this PR can stay small.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }],
    data: [{ id: 1 }],
    ...extra
  });
}

describe('Theming: config.theme', () => {
  test('config.theme: "dark" puts data-tc-theme="dark" on the wrapper', () => {
    const table = makeTable({ theme: 'dark' });
    table.render();
    const wrapper = document.querySelector('.tc-wrapper');
    expect(wrapper.getAttribute('data-tc-theme')).toBe('dark');
  });

  test('no theme config → no data-tc-theme attribute', () => {
    const table = makeTable();
    table.render();
    const wrapper = document.querySelector('.tc-wrapper');
    expect(wrapper.getAttribute('data-tc-theme')).toBeNull();
  });

  test('arbitrary string is honoured (custom themes are first-class)', () => {
    const table = makeTable({ theme: 'company-blue' });
    table.render();
    const wrapper = document.querySelector('.tc-wrapper');
    expect(wrapper.getAttribute('data-tc-theme')).toBe('company-blue');
  });
});

describe('Theming: config.themeVariables', () => {
  test('CSS custom properties land as inline style on the wrapper', () => {
    const table = makeTable({
      themeVariables: { '--tc-bg': '#111', '--tc-text': '#eee' }
    });
    table.render();
    const wrapper = document.querySelector('.tc-wrapper');
    expect(wrapper.style.getPropertyValue('--tc-bg').trim()).toBe('#111');
    expect(wrapper.style.getPropertyValue('--tc-text').trim()).toBe('#eee');
  });

  test('omitted themeVariables → no inline custom properties', () => {
    const table = makeTable();
    table.render();
    const wrapper = document.querySelector('.tc-wrapper');
    expect(wrapper.style.getPropertyValue('--tc-bg')).toBe('');
  });
});

describe('Theming: setTheme / getTheme', () => {
  test('getTheme defaults to "light"', () => {
    const table = makeTable();
    expect(table.getTheme()).toBe('light');
  });

  test('getTheme returns the configured theme', () => {
    const table = makeTable({ theme: 'dark' });
    expect(table.getTheme()).toBe('dark');
  });

  test('setTheme swaps the attribute and triggers a re-render', () => {
    const table = makeTable({ theme: 'light' });
    table.render();
    const renderSpy = jest.spyOn(table, 'render');

    table.setTheme('dark');

    expect(table.getTheme()).toBe('dark');
    expect(renderSpy).toHaveBeenCalled();
    expect(document.querySelector('.tc-wrapper').getAttribute('data-tc-theme')).toBe('dark');
  });
});
