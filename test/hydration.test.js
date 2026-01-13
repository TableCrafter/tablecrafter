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

  test('should attach click listeners to server-rendered headers', async () => {
      // Setup DOM with sortable headers (simulating PHP output)
      document.body.innerHTML = `
        <div id="tc-ssr-sort" data-ssr="true">
          <table class="tc-table">
            <thead>
              <tr>
                <th class="tc-sortable" data-field="name" aria-sort="none">Name</th>
              </tr>
            </thead>
            <tbody><tr><td>Test</td></tr></tbody>
          </table>
        </div>
      `;
      
      const container = document.getElementById('tc-ssr-sort');
      const table = new TableCrafter('#tc-ssr-sort', {
          data: [{ name: "Test" }], // Embedded or fetched doesn't matter for this test logic
          columns: [{ field: 'name', label: 'Name', sortable: true }]
      });

      // Spy on the sort method directly
      const sortSpy = jest.spyOn(table, 'sort'); // Note: this might need to be set before hydration if hydrating in constructor?
      // Actually hydration happens in loadData which is async or called after.
      // In constructor, we don't call loadData automatically unless configured? 
      // Looking at constructor: it calls resolveContainer. It DOES NOT call loadData automatically in the snippet I saw?
      // Let's check frontend.js usage: new TableCrafter(...) -> wait, does it auto-load?
      // Creating the instance does not seem to trigger loadData in the constructor snippet I saw earlier (lines 1-100). 
      // Checking tablecrafter.js ... 
      
      // Let's assume we need to call loadData to trigger hydration logic.
      await table.loadData();
      
      // Now simulate click
      const th = container.querySelector('th.tc-sortable');
      th.click();

      expect(sortSpy).toHaveBeenCalledWith('name');
  });
});
