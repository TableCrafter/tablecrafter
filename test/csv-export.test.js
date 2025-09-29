/**
 * CSV Export Tests for TableCrafter
 * Inspired by Grid.js export functionality
 */

const TableCrafter = require('../src/tablecrafter');

describe('TableCrafter CSV Export', () => {
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

  test('should render export button when export is enabled', () => {
    const data = [
      { id: 1, name: 'John', email: 'john@example.com' },
      { id: 2, name: 'Jane', email: 'jane@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    expect(container.querySelector('.tc-export-controls')).toBeTruthy();
    expect(container.querySelector('.tc-export-csv')).toBeTruthy();
    expect(container.querySelector('.tc-export-csv').textContent).toContain('Export CSV');
  });

  test('should not render export button when export is disabled', () => {
    const data = [{ id: 1, name: 'John' }];
    const columns = [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: false
    });

    table.render();

    expect(container.querySelector('.tc-export-controls')).toBeFalsy();
  });

  test('should export all data to CSV format', () => {
    const data = [
      { id: 1, name: 'John Doe', email: 'john@example.com', department: 'Engineering' },
      { id: 2, name: 'Jane Smith', email: 'jane@example.com', department: 'Marketing' },
      { id: 3, name: 'Bob Johnson', email: 'bob@example.com', department: 'Sales' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' },
      { field: 'department', label: 'Department' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    const csvData = table.exportToCSV();
    const expectedCSV = [
      'ID,Name,Email,Department',
      '1,"John Doe","john@example.com","Engineering"',
      '2,"Jane Smith","jane@example.com","Marketing"',
      '3,"Bob Johnson","bob@example.com","Sales"'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should handle special characters and quotes in CSV export', () => {
    const data = [
      { id: 1, name: 'John "Johnny" Doe', notes: 'Has a, comma' },
      { id: 2, name: 'Jane\nNewline', notes: 'Text with "quotes"' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'notes', label: 'Notes' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    const csvData = table.exportToCSV();
    
    // Test the full CSV content rather than splitting by lines 
    // since newlines within fields are valid CSV
    const expectedCSV = [
      'ID,Name,Notes',
      '1,"John ""Johnny"" Doe","Has a, comma"',
      '2,"Jane\nNewline","Text with ""quotes"""'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should export only filtered data when filters are applied', () => {
    const data = [
      { id: 1, name: 'John', department: 'Engineering' },
      { id: 2, name: 'Jane', department: 'Marketing' },
      { id: 3, name: 'Bob', department: 'Engineering' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'department', label: 'Department' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true,
      filterable: true
    });

    table.render();

    // Apply filter
    table.setFilter('department', 'Engineering');

    const csvData = table.exportToCSV();
    const expectedCSV = [
      'ID,Name,Department',
      '1,"John","Engineering"',
      '3,"Bob","Engineering"'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should export all data when exportFiltered is false', () => {
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
      exportable: true,
      filterable: true,
      exportFiltered: false
    });

    table.render();

    // Apply filter
    table.setFilter('department', 'Engineering');

    const csvData = table.exportToCSV();
    const expectedCSV = [
      'ID,Name,Department',
      '1,"John","Engineering"',
      '2,"Jane","Marketing"'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should download CSV file when export button is clicked', () => {
    // Mock URL.createObjectURL and document.createElement
    const mockCreateObjectURL = jest.fn(() => 'blob:mock-url');
    const mockRevokeObjectURL = jest.fn();
    global.URL.createObjectURL = mockCreateObjectURL;
    global.URL.revokeObjectURL = mockRevokeObjectURL;

    const mockClick = jest.fn();
    const mockAnchor = {
      href: '',
      download: '',
      click: mockClick
    };

    const originalCreateElement = document.createElement;
    document.createElement = jest.fn((tagName) => {
      if (tagName === 'a') {
        return mockAnchor;
      }
      return originalCreateElement.call(document, tagName);
    });

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
      exportable: true
    });

    table.render();

    const exportBtn = container.querySelector('.tc-export-csv');
    exportBtn.click();

    expect(mockCreateObjectURL).toHaveBeenCalledWith(expect.any(Blob));
    expect(mockAnchor.download).toBe('table-export.csv');
    expect(mockClick).toHaveBeenCalled();
    expect(mockRevokeObjectURL).toHaveBeenCalled();

    // Restore original functions
    document.createElement = originalCreateElement;
  });

  test('should use custom filename when provided', () => {
    const mockCreateObjectURL = jest.fn(() => 'blob:mock-url');
    const mockRevokeObjectURL = jest.fn();
    global.URL.createObjectURL = mockCreateObjectURL;
    global.URL.revokeObjectURL = mockRevokeObjectURL;

    const mockClick = jest.fn();
    const mockAnchor = {
      href: '',
      download: '',
      click: mockClick
    };

    const originalCreateElement = document.createElement;
    document.createElement = jest.fn((tagName) => {
      if (tagName === 'a') {
        return mockAnchor;
      }
      return originalCreateElement.call(document, tagName);
    });

    const data = [{ id: 1, name: 'John' }];
    const columns = [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true,
      exportFilename: 'my-custom-export.csv'
    });

    table.render();

    const exportBtn = container.querySelector('.tc-export-csv');
    exportBtn.click();

    expect(mockAnchor.download).toBe('my-custom-export.csv');

    // Restore original functions
    document.createElement = originalCreateElement;
  });

  test('should export only visible columns when specified', () => {
    const data = [
      { id: 1, name: 'John', email: 'john@example.com', internal: 'secret' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' },
      { field: 'internal', label: 'Internal', exportable: false }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    const csvData = table.exportToCSV();
    const expectedCSV = [
      'ID,Name,Email',
      '1,"John","john@example.com"'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should handle empty data export', () => {
    const data = [];
    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    const csvData = table.exportToCSV();
    expect(csvData).toBe('ID,Name');
  });

  test('should handle missing field values in export', () => {
    const data = [
      { id: 1, name: 'John' }, // missing email
      { id: 2, email: 'jane@example.com' }, // missing name
      { id: 3, name: 'Bob', email: 'bob@example.com' }
    ];

    const columns = [
      { field: 'id', label: 'ID' },
      { field: 'name', label: 'Name' },
      { field: 'email', label: 'Email' }
    ];

    table = new TableCrafter('#table-container', {
      data,
      columns,
      exportable: true
    });

    table.render();

    const csvData = table.exportToCSV();
    const expectedCSV = [
      'ID,Name,Email',
      '1,"John",""',
      '2,"","jane@example.com"',
      '3,"Bob","bob@example.com"'
    ].join('\n');

    expect(csvData).toBe(expectedCSV);
  });

  test('should call onExport callback when export is triggered', () => {
    const onExportCallback = jest.fn();
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
      exportable: true,
      onExport: onExportCallback
    });

    table.render();

    const csvData = table.exportToCSV();

    expect(onExportCallback).toHaveBeenCalledWith({
      format: 'csv',
      data: expect.any(Array),
      csvData: csvData
    });
  });

  test('should work with pagination - export all pages', () => {
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
      exportable: true,
      pagination: true,
      pageSize: 10
    });

    table.render();

    const csvData = table.exportToCSV();
    const lines = csvData.split('\n');
    
    expect(lines).toHaveLength(26); // 25 data rows + 1 header
    expect(lines[0]).toBe('ID,Name');
    expect(lines[25]).toBe('25,"User 25"');
  });
});