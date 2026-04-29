/**
 * Visual snapshot foundation (slice 1 of #57).
 *
 * Lands a deterministic snapshotHTML() helper that consumers can pair
 * with Jest's toMatchSnapshot() to detect unintended visual regressions
 * in CI without spinning up a real browser.
 *
 * Pixel-perfect screenshot diffing via Puppeteer / Playwright remains a
 * separate ticket; this PR ships the lightweight DOM-string flavour.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [{ id: 1, name: 'A' }, { id: 2, name: 'B' }];
const columns = [{ field: 'id' }, { field: 'name' }];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns, ...extra });
}

describe('snapshotHTML: determinism', () => {
  test('two consecutive calls on the same state return identical strings', () => {
    const t = makeTable();
    t.render();
    expect(t.snapshotHTML()).toBe(t.snapshotHTML());
  });

  test('changing search term + re-rendering changes the snapshot', () => {
    const t = makeTable();
    t.render();
    const before = t.snapshotHTML();

    t.searchTerm = 'A';
    t.render();
    const after = t.snapshotHTML();

    expect(after).not.toBe(before);
  });
});

describe('snapshotHTML: normalisation', () => {
  test('attributes are alphabetically sorted per element', () => {
    const t = makeTable();
    t.render();
    // Construct an element with attributes in a "wrong" order to confirm
    // the normaliser sorts them. Insert into the wrapper so the snapshot
    // includes it.
    const wrapper = document.querySelector('.tc-wrapper');
    const probe = document.createElement('span');
    probe.setAttribute('z-attr', '1');
    probe.setAttribute('a-attr', '2');
    probe.setAttribute('m-attr', '3');
    wrapper.appendChild(probe);

    const html = t.snapshotHTML({ scope: 'wrapper' });
    // The substring should have a- before m- before z-
    const aIdx = html.indexOf('a-attr');
    const mIdx = html.indexOf('m-attr');
    const zIdx = html.indexOf('z-attr');
    expect(aIdx).toBeGreaterThanOrEqual(0);
    expect(mIdx).toBeGreaterThan(aIdx);
    expect(zIdx).toBeGreaterThan(mIdx);
  });

  test('inline width percentages are rounded to 1 decimal place', () => {
    const t = makeTable();
    t.render();
    const wrapper = document.querySelector('.tc-wrapper');
    const probe = document.createElement('div');
    probe.setAttribute('style', 'width: 33.33333333%; color: red');
    wrapper.appendChild(probe);

    const html = t.snapshotHTML({ scope: 'wrapper' });
    expect(html).toContain('width: 33.3%');
    expect(html).not.toContain('33.33333333');
  });
});

describe('snapshotHTML: scope', () => {
  test('default scope returns the table-only markup', () => {
    const t = makeTable();
    t.render();
    const html = t.snapshotHTML();
    expect(html.startsWith('<table')).toBe(true);
    expect(html).not.toContain('tc-wrapper');
  });

  test('scope: "wrapper" returns the full .tc-wrapper markup', () => {
    const t = makeTable();
    t.render();
    const html = t.snapshotHTML({ scope: 'wrapper' });
    expect(html.startsWith('<div')).toBe(true);
    expect(html).toContain('class="tc-wrapper"');
  });
});

describe('snapshotHTML: pure read', () => {
  test('does not trigger a render', () => {
    const t = makeTable();
    t.render();
    const renderSpy = jest.spyOn(t, 'render');
    t.snapshotHTML();
    expect(renderSpy).not.toHaveBeenCalled();
  });
});
