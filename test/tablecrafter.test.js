/**
 * TableCrafter Core Tests
 * Test-driven development for the standalone JavaScript library
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter Initialization', () => {
  let container;

  beforeEach(() => {
    // Set up DOM container for each test
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
  });

  test('should create a new TableCrafter instance', () => {
    const table = new TableCrafter('#table-container');
    expect(table).toBeInstanceOf(TableCrafter);
  });

  test('should accept container as DOM element', () => {
    const table = new TableCrafter(container);
    expect(table).toBeInstanceOf(TableCrafter);
  });

  test('should throw error if container not found', () => {
    expect(() => {
      new TableCrafter('#non-existent');
    }).toThrow('Container element not found');
  });

  test('should accept configuration options', () => {
    const config = {
      data: [],
      columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name' }
      ],
      editable: true,
      responsive: { enabled: true }
    };

    const table = new TableCrafter('#table-container', config);
    expect(table.config).toMatchObject(config);
  });

  test('should have default configuration', () => {
    const table = new TableCrafter('#table-container');

    expect(table.config).toMatchObject({
      editable: false,
      responsive: {
        enabled: true,
        breakpoints: {
          mobile: { width: 480, layout: 'cards' },
          tablet: { width: 768, layout: 'compact' },
          desktop: { width: 1024, layout: 'table' }
        }
      },
      pageSize: 25,
      sortable: true,
      filterable: true
    });
  });
});

describe('TableCrafter Data Loading', () => {
  let container;
  let table;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
  });

  test('should load data from array', () => {
    const data = [
      { id: 1, name: 'John Doe', email: 'john@example.com' },
      { id: 2, name: 'Jane Smith', email: 'jane@example.com' }
    ];

    table = new TableCrafter('#table-container', { data });
    expect(table.getData()).toEqual(data);
  });

  test('should load data from URL', async () => {
    const mockData = [
      { id: 1, name: 'John' },
      { id: 2, name: 'Jane' }
    ];

    fetch.mockResolvedValue({
      ok: true,
      json: async () => mockData
    });

    table = new TableCrafter('#table-container', {
      data: 'https://api.example.com/data'
    });

    await table.loadData();
    expect(fetch).toHaveBeenCalledWith('https://api.example.com/data');
    expect(table.getData()).toEqual(mockData);
  });

  test('should handle data loading errors', async () => {
    fetch.mockRejectedValue(new Error('Network error'));

    table = new TableCrafter('#table-container', {
      data: 'https://api.example.com/data'
    });

    await expect(table.loadData()).rejects.toThrow('Network error');
  });
});

describe('TableCrafter Rendering', () => {
  let container;
  let table;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
  });

  test('should render table structure', () => {
    const data = [
      { id: 1, name: 'John Doe', email: 'john@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', { data, columns });
    table.render();

    expect(container.querySelector('.tc-wrapper')).toBeTruthy();
    expect(container.querySelector('.tc-table')).toBeTruthy();
    expect(container.querySelector('thead')).toBeTruthy();
    expect(container.querySelector('tbody')).toBeTruthy();
  });

  test('should render column headers', () => {
    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', { columns });
    table.render();

    const headers = container.querySelectorAll('thead th');
    expect(headers).toHaveLength(2);
    expect(headers[0].textContent).toBe('ID');
    expect(headers[1].textContent).toBe('Name');
  });

  test('should render data rows', () => {
    const data = [
      { id: 1, name: 'John' },
      { id: 2, name: 'Jane' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', { data, columns });
    table.render();

    const rows = container.querySelectorAll('tbody tr');
    expect(rows).toHaveLength(2);

    const firstRowCells = rows[0].querySelectorAll('td');
    expect(firstRowCells[0].textContent).toBe('1');
    expect(firstRowCells[1].textContent).toBe('John');
  });
});

describe('TableCrafter Mobile Responsiveness', () => {
  let container;
  let table;
  let originalInnerWidth;

  beforeEach(() => {
    document.body.innerHTML = '<div id="table-container"></div>';
    container = document.getElementById('table-container');
    originalInnerWidth = window.innerWidth;
  });

  afterEach(() => {
    if (table) {
      table.destroy();
    }
    // Reset window width
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: originalInnerWidth
    });
  });

  test('should detect mobile viewport', () => {
    // Mock window width for mobile
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 480
    });

    table = new TableCrafter('#table-container', {
      responsive: {
        enabled: true,
        breakpoints: { mobile: { width: 768 } }
      }
    });

    expect(table.isMobile()).toBe(true);
  });

  test('should render cards on mobile', () => {
    Object.defineProperty(window, 'innerWidth', {
      writable: true,
      configurable: true,
      value: 480
    });

    const data = [
      { id: 1, name: 'John', email: 'john@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      responsive: { enabled: true }
    });
    table.render();

    expect(container.querySelector('.tc-cards-container')).toBeTruthy();
    expect(container.querySelector('.tc-card')).toBeTruthy();
  });

  test('should switch between table and cards on resize', () => {
    const data = [{ id: 1, name: 'John' }];
    const columns = [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      responsive: {
        enabled: true,
        breakpoints: {
          mobile: { width: 768, layout: 'cards' }
        }
      }
    });

    // Start in desktop view
    Object.defineProperty(window, 'innerWidth', { value: 1024, configurable: true });
    table.render();
    expect(container.querySelector('.tc-table')).toBeTruthy();
    expect(container.querySelector('.tc-cards-container')).toBeFalsy();

    // Resize to mobile
    Object.defineProperty(window, 'innerWidth', { value: 480, configurable: true });
    window.dispatchEvent(new Event('resize'));
    expect(container.querySelector('.tc-cards-container')).toBeTruthy();
  });
});

describe('TableCrafter Editing', () => {
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

  test('should make cells editable when configured', () => {
    const data = [{ id: 1, name: 'John', email: 'john@example.com' }];
    const columns = [
      { field: 'id', label: 'ID', editable: false },
      { field: 'name', label: 'Name', editable: true },
      { field: 'email', label: 'Email', editable: true }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      editable: true
    });
    table.render();

    const cells = container.querySelectorAll('tbody td');
    expect(cells).toHaveLength(3);
    expect(cells[0].classList.contains('tc-editable')).toBe(false);
    expect(cells[1].classList.contains('tc-editable')).toBe(true);
    expect(cells[2].classList.contains('tc-editable')).toBe(true);
  });

  test('should enter edit mode on cell click', () => {
    const data = [{ id: 1, name: 'John' }];
    const columns = [
      { field: 'id', label: 'ID', editable: false },
      { field: 'name', label: 'Name', editable: true }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      editable: true
    });
    table.render();

    const cells = container.querySelectorAll('tbody td');
    expect(cells).toHaveLength(2);

    const editableCell = cells[1];
    editableCell.click();

    expect(editableCell.querySelector('input')).toBeTruthy();
    expect(editableCell.querySelector('input').value).toBe('John');
  });

  test('should save changes on blur', () => {
    const data = [{ id: 1, name: 'John' }];
    const columns = [
      { field: 'id', label: 'ID', editable: false },
      { field: 'name', label: 'Name', editable: true }
    ];

    const onEdit = jest.fn();

    table = new TableCrafter('#table-container', {
      data,
      columns,
      editable: true,
      onEdit
    });
    table.render();

    const cells = container.querySelectorAll('tbody td');
    expect(cells).toHaveLength(2);

    const editableCell = cells[1];
    editableCell.click();

    const input = editableCell.querySelector('input');
    input.value = 'Jane';
    input.blur();

    expect(onEdit).toHaveBeenCalledWith({
      row: 0,
      field: 'name',
      oldValue: 'John',
      newValue: 'Jane'
    });

    expect(table.getData()[0].name).toBe('Jane');
  });
});