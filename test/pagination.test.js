/**
 * Pagination Tests for TableCrafter
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Pagination', () => {
  let container;
  let table;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
  });

  afterEach(() => {
    if (table) {
      table.destroy();
    }
  });

  test('should render pagination controls when data exceeds page size', () => {
    const data = Array.from({ length: 30 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`,
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
      pageSize: 10,
      pagination: true
    });

    table.render();

    expect(container.querySelector('.tc-pagination')).toBeTruthy();
    expect(container.querySelector('.tc-pagination-info')).toBeTruthy();
    expect(container.querySelector('.tc-pagination-controls')).toBeTruthy();
    expect(container.querySelector('.tc-prev-btn')).toBeTruthy();
    expect(container.querySelector('.tc-next-btn')).toBeTruthy();
  });

  test('should not render pagination when data fits in one page', () => {
    const data = [
      { id: 1, name: 'User 1', email: 'user1@example.com' },
      { id: 2, name: 'User 2', email: 'user2@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    expect(container.querySelector('.tc-pagination')).toBeFalsy();
  });

  test('should display correct number of rows per page', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    const rows = container.querySelectorAll('tbody tr');
    expect(rows.length).toBe(10);
  });

  test('should show correct pagination info', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    const paginationInfo = container.querySelector('.tc-pagination-info');
    expect(paginationInfo.textContent).toContain('1-10 of 25');
  });

  test('should navigate to next page', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    const nextBtn = container.querySelector('.tc-next-btn');
    nextBtn.click();

    const paginationInfo = container.querySelector('.tc-pagination-info');
    expect(paginationInfo.textContent).toContain('11-20 of 25');

    const firstRow = container.querySelector('tbody tr:first-child td:first-child');
    expect(firstRow.textContent).toBe('11');
  });

  test('should navigate to previous page', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true,
      currentPage: 2
    });

    table.render();

    const prevBtn = container.querySelector('.tc-prev-btn');
    prevBtn.click();

    const paginationInfo = container.querySelector('.tc-pagination-info');
    expect(paginationInfo.textContent).toContain('1-10 of 25');

    const firstRow = container.querySelector('tbody tr:first-child td:first-child');
    expect(firstRow.textContent).toBe('1');
  });

  test('should disable prev button on first page', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    const prevBtn = container.querySelector('.tc-prev-btn');
    expect(prevBtn.disabled).toBe(true);
  });

  test('should disable next button on last page', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true,
      currentPage: 3
    });

    table.render();

    const nextBtn = container.querySelector('.tc-next-btn');
    expect(nextBtn.disabled).toBe(true);
  });

  test('should show page numbers', () => {
    const data = Array.from({ length: 25 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 10,
      pagination: true
    });

    table.render();

    const currentPage = container.querySelector('.tc-current-page');
    const totalPages = container.querySelector('.tc-total-pages');
    
    expect(currentPage.textContent).toBe('1');
    expect(totalPages.textContent).toBe('3');
  });

  test('should work with mobile card view', () => {
    // Mock mobile viewport
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 480
    });

    const data = Array.from({ length: 15 }, (_, i) => ({
      id: i + 1,
      name: `User ${i + 1}`
    }));

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      pageSize: 5,
      pagination: true,
      responsive: true
    });

    table.render();

    const cards = container.querySelectorAll('.tc-card');
    expect(cards.length).toBe(5);

    const paginationInfo = container.querySelector('.tc-pagination-info');
    expect(paginationInfo.textContent).toContain('1-5 of 15');
  });
});