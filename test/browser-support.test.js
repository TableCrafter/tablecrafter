/**
 * Browser support detection (slice 1 of #56).
 *
 * Read-only capability probe consumers can use to surface a graceful
 * "your browser is too old" banner. Cross-browser CI matrix and
 * polyfill bundling remain queued elsewhere.
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter.getBrowserSupport()', () => {
  test('returns an object with the documented keys', () => {
    const support = TableCrafter.getBrowserSupport();

    expect(support).toEqual(expect.objectContaining({
      intl: expect.any(Boolean),
      intlPluralRules: expect.any(Boolean),
      resizeObserver: expect.any(Boolean),
      performanceNow: expect.any(Boolean),
      svgInHtml: expect.any(Boolean),
      abortController: expect.any(Boolean),
      cssCustomProperties: expect.any(Boolean),
      requiredFeaturesAvailable: expect.any(Boolean)
    }));
  });

  test('jsdom test environment satisfies the required features', () => {
    expect(TableCrafter.getBrowserSupport().requiredFeaturesAvailable).toBe(true);
  });

  test('removing Intl flips intl + requiredFeaturesAvailable to false', () => {
    const originalIntl = global.Intl;
    delete global.Intl;
    try {
      const support = TableCrafter.getBrowserSupport();
      expect(support.intl).toBe(false);
      expect(support.requiredFeaturesAvailable).toBe(false);
    } finally {
      global.Intl = originalIntl;
    }
  });

  test('does not throw when CSS / ResizeObserver / etc. are absent', () => {
    const originalCSS = global.CSS;
    const originalRO = global.ResizeObserver;
    delete global.CSS;
    delete global.ResizeObserver;
    try {
      expect(() => TableCrafter.getBrowserSupport()).not.toThrow();
    } finally {
      global.CSS = originalCSS;
      global.ResizeObserver = originalRO;
    }
  });
});

describe('TableCrafter.minimumBrowserSupportNotice()', () => {
  test('returns a non-empty string', () => {
    const notice = TableCrafter.minimumBrowserSupportNotice();
    expect(typeof notice).toBe('string');
    expect(notice.length).toBeGreaterThan(0);
  });
});
