/**
 * Advanced Filtering Tests for TableCrafter
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Advanced Filtering', () => {
  let container;
  let table;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
    jest.useFakeTimers();
  });

  afterEach(() => {
    if (table) {
      table.destroy();
    }
    jest.useRealTimers();
  });

  test('should render filter controls when filterable is enabled', () => {
    const data = [
      { id: 1, name: 'John', department: 'Engineering' },
      { id: 2, name: 'Jane', department: 'Marketing' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'department', label: 'Department' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true
    });

    table.render();

    expect(container.querySelector('.tc-filters')).toBeTruthy();
    // With auto-detection: id->numberrange, name->multiselect, department->multiselect
    // No .tc-filter-input will be present (those are for text)
    expect(container.querySelectorAll('.tc-filter')).toHaveLength(3);
  });

  test('should not render filter controls when filterable is disabled', () => {
    const data = [{ id: 1, name: 'John' }];
    const columns = [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: false
    });

    table.render();

    expect(container.querySelector('.tc-filters')).toBeFalsy();
  });

  test('should filter data by text input', () => {
    const data = [
      { id: 1, name: 'John Doe', email: 'john@example.com' },
      { id: 2, name: 'Jane Smith', email: 'jane@example.com' },
      { id: 3, name: 'Bob Johnson', email: 'bob@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    // Filter by name
    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(2); // John Doe and Bob Johnson
  });

  test('should filter data case insensitively', () => {
    const data = [
      { id: 1, name: 'John Doe' },
      { id: 2, name: 'jane smith' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'JANE';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(1);
    expect(rows[0].querySelector('td[data-field="name"]').textContent).toBe('jane smith');
  });

  test('should apply multiple filters simultaneously', () => {
    const data = [
      { id: 1, name: 'John Doe', department: 'Engineering' },
      { id: 2, name: 'Jane Smith', department: 'Engineering' },
      { id: 3, name: 'Bob Johnson', department: 'Marketing' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'department', label: 'Department' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' },
          department: { type: 'text' }
        }
      }
    });

    table.render();

    // Filter by department
    const deptFilter = container.querySelector('.tc-filter-input[data-field="department"]');
    deptFilter.value = 'Engineering';
    deptFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    // Then filter by name
    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'Jane';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(1);
    expect(rows[0].querySelector('td[data-field="name"]').textContent).toBe('Jane Smith');
  });

  test('should clear filters when input is empty', () => {
    const data = [
      { id: 1, name: 'John' },
      { id: 2, name: 'Jane' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');

    // Apply filter
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    expect(container.querySelectorAll('tbody tr')).toHaveLength(1);

    // Clear filter
    nameFilter.value = '';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);
    expect(container.querySelectorAll('tbody tr')).toHaveLength(2);
  });

  test('should show no results message when no data matches filters', () => {
    const data = [
      { id: 1, name: 'John' },
      { id: 2, name: 'Jane' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'NonExistent';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    expect(container.querySelector('.tc-no-results')).toBeTruthy();
    expect(container.querySelector('.tc-no-results').textContent).toContain('No results found');
  });

  test('should work with pagination when filtering', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: i < 10 ? `John ${i + 1}` : `User ${i + 1}`,
      email: `user${i + 1}@example.com`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pagination: true,
      pageSize: 5,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    // Filter to show only Johns (should be 10 results)
    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    // Should show 5 results on first page
    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(5);

    // Pagination info should reflect filtered data
    const paginationInfo = container.querySelector('.tc-pagination-info');
    expect(paginationInfo.textContent).toContain('1-5 of 10');
  });

  test('should reset to first page when filter is applied', () => {
    const data = Array.from({ length: 20 }, (_, i) => ({
      id: i + 1,
      name: i < 10 ? `John ${i + 1}` : `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pagination: true,
      pageSize: 3,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    // Go to page 2
    const nextBtn = container.querySelector('.tc-next-btn');
    nextBtn.click();

    const currentPageBefore = container.querySelector('.tc-current-page');
    expect(currentPageBefore.textContent).toBe('2');

    // Apply filter
    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    // Should be back on page 1
    const currentPageAfter = container.querySelector('.tc-current-page');
    expect(currentPageAfter.textContent).toBe('1');
  });

  test('should work with mobile card view', () => {
    // Mock mobile viewport
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 480
    });

    const data = [
      { id: 1, name: 'John Doe' },
      { id: 2, name: 'Jane Smith' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      responsive: true,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    const cards = container.querySelectorAll('.tc-card');
    expect(cards).toHaveLength(1);

    // Reset window width
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 1024
    });
  });

  test('should provide programmatic filter API', () => {
    const data = [
      { id: 1, name: 'John', status: 'enabled' },
      { id: 2, name: 'Jane', status: 'disabled' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'status', label: 'Status' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      filters: {
        autoDetect: true,
        types: {
          status: { type: 'text' }
        }
      }
    });

    table.render();

    // Set filter programmatically
    table.setFilter('status', 'enabled');

    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(1);
    expect(rows[0].querySelector('td[data-field="name"]').textContent).toBe('John');

    // Advancing timers to ensure any pending renders from setFilter are processed
    jest.advanceTimersByTime(300);

    // Clear filters programmatically
    table.clearFilters();
    const allRows = container.querySelectorAll('tbody tr');
    expect(allRows).toHaveLength(2);
  });

  test('should call onFilter callback when filters change', () => {
    const onFilterCallback = jest.fn();
    const data = [
      { id: 1, name: 'John' },
      { id: 2, name: 'Jane' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      filterable: true,
      onFilter: onFilterCallback,
      filters: {
        autoDetect: true,
        types: {
          name: { type: 'text' }
        }
      }
    });

    table.render();

    const nameFilter = container.querySelector('.tc-filter-input[data-field="name"]');
    nameFilter.value = 'John';
    nameFilter.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(300);

    expect(onFilterCallback).toHaveBeenCalledWith({
      filters: { name: 'John' },
      filteredData: [{ id: 1, name: 'John' }]
    });
  });
});