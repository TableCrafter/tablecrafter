const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;
const { JSDOM } = require('jsdom');
const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Error Handling', () => {
  let dom;
  let document;
  let container;

  beforeEach(() => {
    // If we are in jsdom environment, document should exist
    if (!global.document) {
        dom = new JSDOM('<!DOCTYPE html><body></body>');
        global.document = dom.window.document;
        global.window = dom.window;
    }
    document = global.document;
    
    document.body.innerHTML = '<div id="tc-table"></div>';
    container = document.getElementById('tc-table');
    
    // Mock fetch
    global.fetch = jest.fn();
  });

  afterEach(() => {
    jest.resetAllMocks();
  });

  test('should display error message on fetch failure', async () => {
    // Mock fetch failure
    global.fetch.mockRejectedValue(new Error('Network error'));

    const table = new TableCrafter('#tc-table', {
      data: 'https://api.example.com/data'
    });

    // Wait for async operations
    await new Promise(resolve => setTimeout(resolve, 0));

    const errorContainer = container.querySelector('.tc-error-container');
    expect(errorContainer).not.toBeNull();
    expect(errorContainer.textContent).toContain('Unable to load data');
  });

  test('should display retry button on error', async () => {
    global.fetch.mockRejectedValue(new Error('Network error'));

    new TableCrafter('#tc-table', {
      data: 'https://api.example.com/data'
    });

    await new Promise(resolve => setTimeout(resolve, 0));

    const retryButton = container.querySelector('.tc-retry-button');
    expect(retryButton).not.toBeNull();
    expect(retryButton.textContent).toBe('Retry');
  });
});
