/**
 * i18n RTL handling (slice of #40).
 * Stacked on PR #96 (formatNumber / formatDate).
 *
 * When the active locale resolves to an RTL language, the rendered wrapper
 * is marked with dir="rtl" and a tc-rtl class so consumer CSS can mirror
 * sort indicators, pagination chevrons, and dropdowns.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(i18n) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }],
    data: [{ id: 1 }, { id: 2 }],
    i18n
  });
}

describe('i18n: isRTL helper', () => {
  test('returns true for the standard RTL languages', () => {
    expect(makeTable({ locale: 'ar' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'he' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'fa' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'ur' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'yi' }).isRTL()).toBe(true);
  });

  test('returns true for region-tagged RTL locales', () => {
    expect(makeTable({ locale: 'ar-EG' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'he-IL' }).isRTL()).toBe(true);
    expect(makeTable({ locale: 'ur-PK' }).isRTL()).toBe(true);
  });

  test('returns false for LTR locales', () => {
    expect(makeTable({ locale: 'en' }).isRTL()).toBe(false);
    expect(makeTable({ locale: 'fr-FR' }).isRTL()).toBe(false);
    expect(makeTable({ locale: 'de-DE' }).isRTL()).toBe(false);
  });
});

describe('i18n: render output reflects RTL', () => {
  test('RTL locale → wrapper carries dir="rtl" and tc-rtl class', () => {
    const table = makeTable({ locale: 'ar' });
    table.render();

    const wrapper = document.querySelector('#t .tc-wrapper');
    expect(wrapper).not.toBeNull();
    expect(wrapper.getAttribute('dir')).toBe('rtl');
    expect(wrapper.classList.contains('tc-rtl')).toBe(true);
  });

  test('LTR locale → wrapper has no dir attribute and no tc-rtl class', () => {
    const table = makeTable({ locale: 'en' });
    table.render();

    const wrapper = document.querySelector('#t .tc-wrapper');
    expect(wrapper).not.toBeNull();
    expect(wrapper.getAttribute('dir')).toBeNull();
    expect(wrapper.classList.contains('tc-rtl')).toBe(false);
  });

  test('setLocale switching from LTR to RTL re-renders with dir="rtl"', () => {
    const table = makeTable({
      locale: 'en',
      messages: { ar: { hello: 'مرحبا' } }
    });
    table.render();
    expect(document.querySelector('#t .tc-wrapper').getAttribute('dir')).toBeNull();

    table.setLocale('ar');
    expect(document.querySelector('#t .tc-wrapper').getAttribute('dir')).toBe('rtl');
    expect(document.querySelector('#t .tc-wrapper').classList.contains('tc-rtl')).toBe(true);
  });
});
