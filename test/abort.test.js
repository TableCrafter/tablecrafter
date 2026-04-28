/**
 * Tests for AbortSignal handling in loadData() — see issue #71.
 *
 * Acceptance criteria covered here:
 *   1. Each call to loadData() passes an AbortSignal to fetch.
 *   2. A second loadData() call aborts the in-flight request from the first.
 *   4. AbortError from the cancelled fetch does not surface as renderError.
 *   5. SSR-hydration branch is also covered.
 *
 * AC #3 (existing "should load data from URL" test goes green) lives in
 * test/tablecrafter.test.js and is verified by running the full suite.
 */

const TableCrafter = require('../src/tablecrafter');

/**
 * Build a TableCrafter without triggering the constructor's auto-load,
 * then point it at a URL so loadData() can be invoked explicitly. This
 * removes timing races between constructor-fired fetches and the test body.
 */
function makeTable(selector, dataUrl) {
  const table = new TableCrafter(selector, { data: [] });
  table.dataUrl = dataUrl;
  return table;
}

describe('TableCrafter loadData() AbortSignal handling', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
  });

  test('passes an AbortSignal on each fetch call', async () => {
    fetch.mockResolvedValue({
      ok: true,
      json: async () => [{ id: 1 }]
    });

    const table = makeTable('#table-container', 'https://api.example.com/data');
    await table.loadData();

    expect(fetch).toHaveBeenCalled();
    const [, options] = fetch.mock.calls[fetch.mock.calls.length - 1];
    expect(options).toBeDefined();
    expect(options.signal).toBeDefined();
    expect(typeof options.signal.aborted).toBe('boolean');
  });

  test('aborts the in-flight signal when loadData() is called twice in quick succession', async () => {
    const signals = [];

    // First fetch: hangs forever unless aborted, then rejects with AbortError.
    fetch.mockImplementationOnce((url, options) => {
      signals.push(options.signal);
      return new Promise((_, reject) => {
        options.signal.addEventListener('abort', () => {
          const err = new Error('aborted');
          err.name = 'AbortError';
          reject(err);
        });
      });
    });
    // Second fetch: captures signal, resolves cleanly.
    fetch.mockImplementationOnce((url, options) => {
      signals.push(options.signal);
      return Promise.resolve({
        ok: true,
        json: async () => [{ id: 2 }]
      });
    });

    const table = makeTable('#table-container', 'https://api.example.com/data');

    // Kick off the first load (do not await — it will only resolve once aborted).
    const firstLoad = table.loadData();
    // Allow a microtask so fetch is invoked and signal is captured.
    await Promise.resolve();

    // Second load — should abort the first.
    await table.loadData();

    expect(signals.length).toBe(2);
    expect(signals[0].aborted).toBe(true);
    expect(signals[1].aborted).toBe(false);

    // First load resolves (silently) once aborted.
    await firstLoad;
  });

  test('AbortError from a cancelled fetch does not call renderError', async () => {
    fetch.mockImplementationOnce((url, options) => {
      return new Promise((_, reject) => {
        options.signal.addEventListener('abort', () => {
          const err = new Error('The operation was aborted');
          err.name = 'AbortError';
          reject(err);
        });
      });
    });
    fetch.mockImplementationOnce(() => Promise.resolve({
      ok: true,
      json: async () => [{ id: 1 }]
    }));

    const table = makeTable('#table-container', 'https://api.example.com/data');
    const renderErrorSpy = jest.spyOn(table, 'renderError');

    const firstLoad = table.loadData();
    await Promise.resolve();
    await table.loadData();
    await firstLoad;

    expect(renderErrorSpy).not.toHaveBeenCalled();
  });

  test('SSR hydration branch also passes an AbortSignal to fetch', async () => {
    fetch.mockResolvedValue({
      ok: true,
      json: async () => [{ id: 99 }]
    });

    const container = document.getElementById('table-container');
    container.dataset.ssr = 'true';

    // Build with empty data + SSR flag so loadData() falls into the SSR
    // branch's fetch path (no embedded data => fetch fallback).
    const table = makeTable('#table-container', 'https://api.example.com/data');
    container.dataset.ssr = 'true'; // re-assert in case constructor cleared it
    table.data = [];

    await table.loadData();

    expect(fetch).toHaveBeenCalled();
    const [, options] = fetch.mock.calls[fetch.mock.calls.length - 1];
    expect(options).toBeDefined();
    expect(options.signal).toBeDefined();
    expect(typeof options.signal.aborted).toBe('boolean');
  });
});
