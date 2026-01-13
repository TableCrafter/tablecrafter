const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;
const { JSDOM } = require('jsdom');
const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Skeleton Loading', () => {
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
    document.body.innerHTML = '<div id="tc-table"></div>';
    container = document.getElementById('tc-table');
    
    global.fetch = jest.fn();
  });

  afterEach(() => {
    jest.resetAllMocks();
  });

  test('should render skeleton rows when loading', () => {
    // Mock fetch to hold pending so we can check loading state
    global.fetch.mockReturnValue(new Promise(() => {}));

    const table = new TableCrafter('#tc-table', {
      data: 'https://api.example.com/data'
    });

    // Manually trigger renderLoading (it's called in loadData but we want to verify the output)
    table.renderLoading();

    const skeletonRows = container.querySelectorAll('.tc-skeleton-row');
    const skeletonCells = container.querySelectorAll('.tc-skeleton-cell');

    expect(skeletonRows.length).toBe(5); // We expect 5 rows
    expect(skeletonCells.length).toBeGreaterThan(0);
    expect(skeletonCells[0].classList.contains('tc-skeleton')).toBe(true);
  });
});
