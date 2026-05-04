/**
 * TableCrafter.bootstrap helper (slice 1 of #54).
 *
 * Scans the document for `[data-tc-bootstrap]` elements, parses their
 * `data-tc-config` JSON, and instantiates a TableCrafter on each. The
 * surrounding WordPress plugin (PHP shortcode + REST endpoint + asset
 * enqueue) lives in a separate repo and is out of scope here.
 */

const TableCrafter = require('../src/tablecrafter');

beforeEach(() => {
  document.body.innerHTML = '';
});

function mount(html) {
  document.body.innerHTML = html;
}

describe('TableCrafter.bootstrap()', () => {
  test('instantiates a table for each [data-tc-bootstrap] element', () => {
    mount(`
      <div id="t1" data-tc-bootstrap data-tc-config='{"columns":[{"field":"id"}],"data":[{"id":1}]}'></div>
      <div id="t2" data-tc-bootstrap data-tc-config='{"columns":[{"field":"id"}],"data":[{"id":2}]}'></div>
      <div id="other"></div>
    `);

    const map = TableCrafter.bootstrap();
    expect(map.size).toBe(2);
    for (const instance of map.values()) {
      expect(instance).toBeInstanceOf(TableCrafter);
    }
    // The non-bootstrap element is left alone.
    expect(document.getElementById('other').children.length).toBe(0);
  });

  test('returns an empty Map when no [data-tc-bootstrap] elements exist', () => {
    mount('<div id="other"></div>');
    expect(TableCrafter.bootstrap().size).toBe(0);
  });

  test('re-running bootstrap is idempotent (no double instantiation)', () => {
    mount('<div id="t" data-tc-bootstrap data-tc-config=\'{"columns":[{"field":"id"}],"data":[]}\'></div>');
    const first = TableCrafter.bootstrap();
    const firstInstance = first.get(document.getElementById('t'));

    const second = TableCrafter.bootstrap();
    const secondInstance = second.get(document.getElementById('t'));

    expect(secondInstance).toBe(firstInstance);
  });

  test('malformed data-tc-config warns once and skips the element', () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    mount(`
      <div id="t1" data-tc-bootstrap data-tc-config='not valid json'></div>
      <div id="t2" data-tc-bootstrap data-tc-config='{"columns":[{"field":"id"}]}'></div>
    `);

    const map = TableCrafter.bootstrap();
    expect(map.size).toBe(1);
    expect(map.has(document.getElementById('t1'))).toBe(false);
    expect(map.has(document.getElementById('t2'))).toBe(true);
    expect(warnSpy).toHaveBeenCalledTimes(1);
    warnSpy.mockRestore();
  });

  test('scoped selector restricts the scan', () => {
    mount(`
      <section id="region">
        <div id="t1" data-tc-bootstrap data-tc-config='{"columns":[{"field":"id"}]}'></div>
      </section>
      <div id="t2" data-tc-bootstrap data-tc-config='{"columns":[{"field":"id"}]}'></div>
    `);

    const map = TableCrafter.bootstrap('#region');
    expect(map.size).toBe(1);
    expect(map.has(document.getElementById('t1'))).toBe(true);
    expect(map.has(document.getElementById('t2'))).toBe(false);
  });

  test('empty data-tc-config is valid (uses defaults)', () => {
    mount('<div id="t" data-tc-bootstrap></div>');
    const map = TableCrafter.bootstrap();
    expect(map.size).toBe(1);
  });
});
