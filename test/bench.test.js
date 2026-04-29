/**
 * Performance benchmarking foundation (slice 1 of #55).
 *
 * Lands a small bench() helper plus benchRender / benchFilter wrappers.
 * Persisted history, devtools-panel integration, and headless cross-browser
 * runs remain queued under #55 / #56 / #57 / #61.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: Array.from({ length: 50 }, (_, i) => ({ id: i + 1, name: 'row-' + (i + 1) }))
  });
}

describe('bench(label, fn, options?)', () => {
  test('returns the documented shape', async () => {
    const t = makeTable();
    const result = await t.bench('noop', () => 1, { runs: 10, warmup: 0 });

    expect(result.label).toBe('noop');
    expect(result.runs).toBe(10);
    expect(typeof result.min).toBe('number');
    expect(typeof result.max).toBe('number');
    expect(typeof result.mean).toBe('number');
    expect(typeof result.median).toBe('number');
    expect(typeof result.p95).toBe('number');
    expect(typeof result.totalMs).toBe('number');
    expect(result.max).toBeGreaterThanOrEqual(result.min);
    expect(result.median).toBeGreaterThanOrEqual(result.min);
    expect(result.median).toBeLessThanOrEqual(result.max);
    expect(result.p95).toBeGreaterThanOrEqual(result.median);
  });

  test('runs option overrides the default count', async () => {
    const t = makeTable();
    let calls = 0;
    const result = await t.bench('count', () => { calls++; }, { runs: 7, warmup: 0 });
    expect(result.runs).toBe(7);
    expect(calls).toBe(7);
  });

  test('warmup iterations run but are excluded from the timed pool', async () => {
    const t = makeTable();
    let calls = 0;
    const result = await t.bench('warmup', () => { calls++; }, { runs: 5, warmup: 3 });
    expect(result.runs).toBe(5);
    expect(calls).toBe(5 + 3); // warmup + timed
  });

  test('awaits async functions', async () => {
    const t = makeTable();
    const slow = () => new Promise(resolve => setTimeout(resolve, 5));
    const result = await t.bench('async', slow, { runs: 3, warmup: 0 });

    expect(result.min).toBeGreaterThanOrEqual(0); // performance.now resolution
    expect(result.totalMs).toBeGreaterThanOrEqual(0);
  });

  test('p95 with single run equals the only sample', async () => {
    const t = makeTable();
    const result = await t.bench('one', () => {}, { runs: 1, warmup: 0 });
    expect(result.p95).toBe(result.min);
    expect(result.median).toBe(result.min);
  });
});

describe('benchRender / benchFilter', () => {
  test('benchRender invokes render the right number of times', async () => {
    const t = makeTable();
    const renderSpy = jest.spyOn(t, 'render');
    await t.benchRender({ runs: 4, warmup: 1 });
    expect(renderSpy).toHaveBeenCalledTimes(5);
  });

  test('benchFilter restores searchTerm to its pre-call value', async () => {
    const t = makeTable();
    t.searchTerm = 'before';
    await t.benchFilter('row-3', { runs: 2, warmup: 0 });
    expect(t.searchTerm).toBe('before');
  });
});
