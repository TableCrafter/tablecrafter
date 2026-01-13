const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;
const { JSDOM } = require('jsdom');
const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter SSR Hydration', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    if (!global.document) {
        dom = new JSDOM('<!DOCTYPE html><body></body>');
        global.document = dom.window.document;
        global.window = dom.window;
    }
    document = global.document;
    
    document.body.innerHTML = `
      <div id="tc-ssr" data-ssr="true">
        <table><thead><tr><th>Static Server Content</th></tr></thead><tbody><tr><td>Loading...</td></tr></tbody></table>
      </div>
    `;
    container = document.getElementById('tc-ssr');
    
    global.fetch = jest.fn();
  });

  afterEach(() => {
    jest.resetAllMocks();
  });

  test('should NOT clear server content immediately upon initialization', async () => {
    // Mock a slow fetch to simulate network delay
    global.fetch.mockReturnValue(new Promise(resolve => {
        setTimeout(() => {
            resolve({
                ok: true,
                json: () => Promise.resolve([{ id: 1, name: "Fetched Data" }])
            });
        }, 100);
    }));

    const table = new TableCrafter('#tc-ssr', {
      data: 'https://api.example.com/data'
    });
    
    // Call loadData manually as it is async
    const loadPromise = table.loadData();

    // IMMEDIATE CHECK: Content should still be the server content
    // The bug was that it wiped this immediately.
    expect(container.querySelector('th').textContent).toBe('Static Server Content');
    
    // Wait for load to finish
    await loadPromise;
  });
});
