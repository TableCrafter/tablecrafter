/**
 * Stacked on PR #78 (i18n t() helper foundation).
 * Adds: setLocale(), addMessages(), and {one, other} plural forms.
 */

const TC = require('../src/tablecrafter');

function makeTable(i18n) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', { columns: [{ field: 'id' }], i18n });
}

describe('i18n: setLocale()', () => {
  test('switches active locale and re-renders', () => {
    const table = makeTable({
      locale: 'en',
      messages: {
        en: { hello: 'Hello' },
        es: { hello: 'Hola' }
      }
    });
    const renderSpy = jest.spyOn(table, 'render');

    expect(table.t('hello')).toBe('Hello');
    table.setLocale('es');
    expect(table.t('hello')).toBe('Hola');
    expect(renderSpy).toHaveBeenCalled();
  });

  test('setLocale to the same locale is a no-op (no extra render)', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { hello: 'Hello' } }
    });
    const renderSpy = jest.spyOn(table, 'render');
    table.setLocale('en');
    expect(renderSpy).not.toHaveBeenCalled();
  });
});

describe('i18n: addMessages()', () => {
  test('merges new keys into the active locale catalogue', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { hello: 'Hello' } }
    });
    table.addMessages('en', { goodbye: 'Goodbye' });
    expect(table.t('hello')).toBe('Hello');
    expect(table.t('goodbye')).toBe('Goodbye');
  });

  test('overrides existing keys when re-added', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { greet: 'Hi' } }
    });
    table.addMessages('en', { greet: 'Hello there' });
    expect(table.t('greet')).toBe('Hello there');
  });

  test('creates a new locale bucket if it did not exist', () => {
    const table = makeTable({ locale: 'en', messages: { en: {} } });
    table.addMessages('fr', { hello: 'Bonjour' });
    table.setLocale('fr');
    expect(table.t('hello')).toBe('Bonjour');
  });
});

describe('i18n: pluralisation', () => {
  test('selects the {one, other} form based on vars.count', () => {
    const table = makeTable({
      locale: 'en',
      messages: {
        en: {
          'rows.selected': {
            one: '1 row selected',
            other: '{count} rows selected'
          }
        }
      }
    });
    expect(table.t('rows.selected', { count: 1 })).toBe('1 row selected');
    expect(table.t('rows.selected', { count: 5 })).toBe('5 rows selected');
    expect(table.t('rows.selected', { count: 0 })).toBe('0 rows selected');
  });

  test('falls back to "other" when "one" is missing', () => {
    const table = makeTable({
      locale: 'en',
      messages: {
        en: { items: { other: '{count} items' } }
      }
    });
    expect(table.t('items', { count: 1 })).toBe('1 items');
    expect(table.t('items', { count: 3 })).toBe('3 items');
  });

  test('returns the object stringified key when no plural forms match', () => {
    const table = makeTable({
      locale: 'en',
      messages: { en: { weird: { few: 'few' } } }
    });
    // No 'one' or 'other' — fall back to the raw key for visibility.
    expect(table.t('weird', { count: 1 })).toBe('weird');
  });
});

// ── Built-in locale packs (#190) ──────────────────────────────────────────────

describe('TC.locales', () => {
  const PACKS = ['en', 'es', 'fr', 'de', 'ar', 'ur'];
  const REQUIRED_KEYS = [
    'toolbar.search', 'pagination.previous', 'pagination.next',
    'table.noResults', 'table.loading', 'validation.required'
  ];

  test.each(PACKS)('locale pack "%s" exists and has required keys', (locale) => {
    expect(TC.locales[locale]).toBeDefined();
    REQUIRED_KEYS.forEach(key => {
      expect(TC.locales[locale][key]).toBeTruthy();
    });
  });

  test('addMessages with a built-in pack makes translations available', () => {
    const table = makeTable({ locale: 'fr', messages: {} });
    table.addMessages('fr', TC.locales.fr);
    expect(table.t('toolbar.search')).toBe('Rechercher...');
    expect(table.t('pagination.previous')).toBe('Précédent');
  });

  test('ar and ur packs include _dir: rtl', () => {
    expect(TC.locales.ar._dir).toBe('rtl');
    expect(TC.locales.ur._dir).toBe('rtl');
  });

  test('en and es packs do not include _dir', () => {
    expect(TC.locales.en._dir).toBeUndefined();
    expect(TC.locales.es._dir).toBeUndefined();
  });
});

// ── RTL support (#189) ────────────────────────────────────────────────────────

describe('RTL: dir attribute on wrapper', () => {
  test('wrapper gets dir="rtl" when active locale pack has _dir:rtl', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      i18n: {
        locale: 'ar',
        messages: { ar: { ...TC.locales.ar } }
      }
    });
    t.render();
    expect(document.querySelector('.tc-wrapper').getAttribute('dir')).toBe('rtl');
  });

  test('wrapper has no dir attribute for LTR locales', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      i18n: { locale: 'en', messages: { en: TC.locales.en } }
    });
    t.render();
    expect(document.querySelector('.tc-wrapper').getAttribute('dir')).toBeNull();
  });

  test('config.dir:"rtl" forces RTL regardless of locale', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      dir: 'rtl'
    });
    t.render();
    expect(document.querySelector('.tc-wrapper').getAttribute('dir')).toBe('rtl');
  });
});
