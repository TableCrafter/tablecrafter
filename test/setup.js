// Test setup file
require('@testing-library/jest-dom');

// Mock fetch API if needed
global.fetch = jest.fn();

// Reset mocks before each test
beforeEach(() => {
  fetch.mockClear();
});

// Clean up after each test
afterEach(() => {
  document.body.innerHTML = '';
});