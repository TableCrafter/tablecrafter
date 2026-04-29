/**
 * i18n foundation: t(key, vars?) helper, locale resolution, fallback, warn-once.
 * Slice of issue #40 (translation function only — pluralisation, formatNumber,
 * formatDate, RTL handling, setLocale and built-in locale packs are tracked
 * for follow-up PRs).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(i18n) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id', label: 'ID' }],
    i18n
  });
}

describe('i18n: t(key, vars?) helper', () => {
  let warnSpy;

  beforeEach(() => {
    warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    warnSpy.mockRestore();
  });

  test('returns the active-locale string when the key exists', () => {
    const table = makeTable({
      locale: 'es',
      messages: { es: { 'toolbar.search': 'Buscar' } }
    });
    expect(table.t('toolbar.search')).toBe('Buscar');
  });

  test('falls through to fallbackLocale when the key is missing in active locale', () => {
    const table = makeTable({
      locale: 'es',
      fallbackLocale: 'en',
      messages: {
        es: { 'toolbar.search': 'Buscar' },
        en: { 'toolbar.export': 'Export' }
      }
    });
    expect(table.t('toolbar.export')).toBe('Export');
  });

  test('falls back to the key itself when no locale has it, and warns once', () => {
    const table = makeTable({ locale: 'es', messages: { es: {} } });

    expect(table.t('totally.unknown')).toBe('totally.unknown');
    expect(table.t('totally.unknown')).toBe('totally.unknown');
    expect(table.t('totally.unknown')).toBe('totally.unknown');

    const missingWarns = warnSpy.mock.calls.filter(c => /totally\.unknown/.test(String(c[0])));
    expect(missingWarns).toHaveLength(1);
  });

  test('substitutes {var} placeholders from the vars object', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { 'pagination.pageOf': 'Page {current} of {total}' } }
    });
    expect(table.t('pagination.pageOf', { current: 2, total: 10 })).toBe('Page 2 of 10');
  });

  test('handles repeated and missing placeholders gracefully', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { greet: 'Hi {name}, hi again {name}!' } }
    });
    expect(table.t('greet', { name: 'A' })).toBe('Hi A, hi again A!');

    // Missing var leaves the placeholder intact (predictable, debuggable).
    expect(table.t('greet')).toBe('Hi {name}, hi again {name}!');
  });

  test('defaults locale to document.documentElement.lang when not set, else "en"', () => {
    document.documentElement.lang = 'fr';
    const t1 = makeTable({
      messages: { fr: { hello: 'Bonjour' } }
    });
    expect(t1.t('hello')).toBe('Bonjour');

    document.documentElement.lang = '';
    const t2 = makeTable({
      messages: { en: { hello: 'Hello' } }
    });
    expect(t2.t('hello')).toBe('Hello');
  });

  test('returns key with no warning and no error when config.i18n is absent', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', { columns: [{ field: 'id' }] });
    expect(table.t('toolbar.search')).toBe('toolbar.search');
  });
});
