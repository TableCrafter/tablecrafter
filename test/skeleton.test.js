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

  test('should render skeleton rows when loading standard table', () => {
    global.fetch.mockReturnValue(new Promise(() => {}));
    const table = new TableCrafter('#tc-table', {
      data: 'https://api.example.com/data'
    });
    table.renderLoading();
    
    const skeletonRows = container.querySelectorAll('.tc-skeleton-row');
    expect(skeletonRows.length).toBe(5);
  });

  test('should NOT render skeleton if SSR content is present', () => {
    container.dataset.ssr = "true";
    container.innerHTML = '<table><thead><tr><th>Static Content</th></tr></thead></table>';
    
    const table = new TableCrafter('#tc-table', {
      data: 'https://api.example.com/data'
    });
    
    table.renderLoading();
    
    // Should still have the table, not the skeleton
    expect(container.querySelector('table')).not.toBeNull();
    expect(container.querySelectorAll('.tc-skeleton-row').length).toBe(0);
  });
});
