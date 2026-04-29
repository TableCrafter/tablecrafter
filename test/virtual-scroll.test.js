/**
 * Virtual scrolling foundation (slice 1 of #37).
 *
 * Lands the pure viewport math helper + state-toggle API. Render-loop
 * wiring (clip rows + emit spacer rows) and scroll-listener installation
 * remain queued under #37.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'id' }],
    data: Array.from({ length: 1000 }, (_, i) => ({ id: i + 1 }))
  });
}

describe('computeVirtualWindow: top of list', () => {
  test('scrollTop=0 starts at index 0 and covers viewport + overscan', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: 0,
      viewportHeight: 400,
      rowHeight: 40,
      totalRows: 1000,
      overscan: 5
    });
    expect(win.startIndex).toBe(0);
    // 400 / 40 = 10 visible, +5 overscan after = 15 (and overscan before clamps to 0)
    expect(win.endIndex).toBe(15);
    expect(win.topPadding).toBe(0);
    expect(win.bottomPadding).toBe((1000 - 15) * 40);
  });
});

describe('computeVirtualWindow: mid scroll', () => {
  test('scrollTop=500 with rowHeight=40 starts around index 12', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: 500,
      viewportHeight: 400,
      rowHeight: 40,
      totalRows: 1000,
      overscan: 5
    });
    // floor(500/40) = 12, minus overscan 5 = 7
    expect(win.startIndex).toBe(7);
    // visibleRows = 10, end = 12 + 10 + 5 overscan = 27
    expect(win.endIndex).toBe(27);
    expect(win.topPadding).toBe(7 * 40);
    expect(win.bottomPadding).toBe((1000 - 27) * 40);
  });
});

describe('computeVirtualWindow: clamping', () => {
  test('overscan beyond start clamps to 0', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: 0,
      viewportHeight: 400,
      rowHeight: 40,
      totalRows: 1000,
      overscan: 100
    });
    expect(win.startIndex).toBe(0);
    expect(win.topPadding).toBe(0);
  });

  test('past-end scrollTop clamps endIndex to totalRows', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: 1000000,
      viewportHeight: 400,
      rowHeight: 40,
      totalRows: 1000,
      overscan: 5
    });
    expect(win.endIndex).toBe(1000);
    expect(win.bottomPadding).toBe(0);
  });

  test('negative scrollTop clamps to 0', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: -50,
      viewportHeight: 400,
      rowHeight: 40,
      totalRows: 100,
      overscan: 0
    });
    expect(win.startIndex).toBe(0);
    expect(win.topPadding).toBe(0);
  });

  test('zero / non-numeric viewportHeight clamps to a safe default', () => {
    const t = makeTable();
    const win = t.computeVirtualWindow({
      scrollTop: 0,
      viewportHeight: 0,
      rowHeight: 40,
      totalRows: 100,
      overscan: 0
    });
    expect(win.startIndex).toBe(0);
    expect(win.endIndex).toBeGreaterThanOrEqual(0);
    expect(win.endIndex).toBeLessThanOrEqual(100);
  });
});

describe('enableVirtualScroll / disableVirtualScroll / isVirtualScrolling', () => {
  test('disabled by default', () => {
    expect(makeTable().isVirtualScrolling()).toBe(false);
  });

  test('enable / disable round-trip', () => {
    const t = makeTable();
    t.enableVirtualScroll({ rowHeight: 40, viewportHeight: 400 });
    expect(t.isVirtualScrolling()).toBe(true);

    t.disableVirtualScroll();
    expect(t.isVirtualScrolling()).toBe(false);
  });

  test('enable stores config for the eventual render integration', () => {
    const t = makeTable();
    t.enableVirtualScroll({ rowHeight: 40, viewportHeight: 400, overscan: 8 });
    expect(t._virtualScroll).toEqual({ rowHeight: 40, viewportHeight: 400, overscan: 8 });
  });
});
