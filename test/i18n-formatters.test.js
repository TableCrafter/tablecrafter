/**
 * i18n formatNumber / formatDate helpers (slice of #40).
 * Stacked on PR #82 (setLocale / addMessages / pluralisation).
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable(i18n) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { columns: [{ field: 'id' }], i18n });
}

describe('formatNumber', () => {
  test('uses the active locale', () => {
    const t = makeTable({ locale: 'de-DE' });
    // German uses ',' as decimal separator and '.' as thousands separator.
    expect(t.formatNumber(1234.5)).toBe('1.234,5');
  });

  test('honours per-call options (currency)', () => {
    const t = makeTable({ locale: 'de-DE' });
    const out = t.formatNumber(1234.5, { style: 'currency', currency: 'EUR' });
    // Don't assert the exact non-breaking-space placement, just substance.
    expect(out).toMatch(/1\.234,50/);
    expect(out).toContain('€');
  });

  test('falls back to the configured i18n.formats.number defaults', () => {
    const t = makeTable({
      locale: 'en-US',
      formats: { number: { minimumFractionDigits: 2 } }
    });
    expect(t.formatNumber(1)).toBe('1.00');
  });

  test('per-call options override the configured defaults', () => {
    const t = makeTable({
      locale: 'en-US',
      formats: { number: { minimumFractionDigits: 2 } }
    });
    expect(t.formatNumber(1, { minimumFractionDigits: 0 })).toBe('1');
  });

  test('returns empty string for null / undefined', () => {
    const t = makeTable({ locale: 'en-US' });
    expect(t.formatNumber(null)).toBe('');
    expect(t.formatNumber(undefined)).toBe('');
  });

  test('returns the input string when value is not numeric', () => {
    const t = makeTable({ locale: 'en-US' });
    expect(t.formatNumber('not a number')).toBe('not a number');
  });
});

describe('formatDate', () => {
  test('formats a Date in the active locale', () => {
    const t = makeTable({ locale: 'fr-FR' });
    const out = t.formatDate(new Date('2026-01-15T12:00:00Z'), { dateStyle: 'long' });
    // French long-style includes the month name. Don't pin the exact day
    // because tz can shift "15" to "14" or "16" — assert structure instead.
    expect(out).toMatch(/janvier 2026/);
  });

  test('accepts ISO strings and numeric epoch milliseconds', () => {
    const t = makeTable({ locale: 'en-US' });
    const fromString = t.formatDate('2026-04-28', { dateStyle: 'short', timeZone: 'UTC' });
    const fromEpoch = t.formatDate(Date.UTC(2026, 3, 28), { dateStyle: 'short', timeZone: 'UTC' });
    expect(fromString).toBe(fromEpoch);
    expect(fromString).toMatch(/4\/28\/26/);
  });

  test('returns empty string for null / undefined / invalid date', () => {
    const t = makeTable({ locale: 'en-US' });
    expect(t.formatDate(null)).toBe('');
    expect(t.formatDate(undefined)).toBe('');
    expect(t.formatDate('not a date')).toBe('');
  });
});
