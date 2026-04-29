// Advanced export formats — exportData, downloadExport, xlsx/pdf, UI dropdown (#46)

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'Alice', email: 'alice@example.com' },
  { id: 2, name: 'Bob',   email: 'bob@example.com' }
];
const columns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' },
  { field: 'email', label: 'Email' }
];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns, ...extra });
}

describe('exportData(format)', () => {
  test('exportData("csv") returns the same string as exportToCSV()', async () => {
    const table = makeTable();
    const result = await table.exportData('csv');
    expect(result).toBe(table.exportToCSV());
    expect(typeof result).toBe('string');
  });

  test('exportData("json") returns valid JSON whose parsed structure matches the visible rows', async () => {
    const table = makeTable();
    const result = await table.exportData('json');
    expect(typeof result).toBe('string');
    const parsed = JSON.parse(result);
    expect(parsed).toEqual(data);
  });

  test('exportData("json") respects exportFiltered: false by returning the raw data', async () => {
    const table = makeTable({ exportFiltered: false });
    table.searchTerm = 'Alice';
    const result = await table.exportData('json');
    expect(JSON.parse(result)).toEqual(data);
  });

  test('exportData("json") only includes exportable columns', async () => {
    const table = makeTable({
      columns: [
        { field: 'id', label: 'ID' },
        { field: 'name', label: 'Name' },
        { field: 'email', label: 'Email', exportable: false }
      ]
    });
    const parsed = JSON.parse(await table.exportData('json'));
    expect(parsed).toEqual([
      { id: 1, name: 'Alice' },
      { id: 2, name: 'Bob' }
    ]);
  });

  test('exportData rejects for an unsupported format with a clear error', async () => {
    const table = makeTable();
    await expect(table.exportData('zip')).rejects.toThrow(/unsupported|format/i);
  });

  test('exportData("xlsx") and exportData("pdf") reject with the not-yet-available message', async () => {
    const table = makeTable();
    await expect(table.exportData('xlsx')).rejects.toThrow(/xlsx|not available|not yet/i);
    await expect(table.exportData('pdf')).rejects.toThrow(/pdf|not available|not yet/i);
  });

  test('exportData fires onExport callback for json format', async () => {
    const onExport = jest.fn();
    const table = makeTable({ onExport });
    await table.exportData('json');
    expect(onExport).toHaveBeenCalledWith(expect.objectContaining({
      format: 'json',
      data: expect.any(Array)
    }));
  });
});

// ── xlsx via mocked peer dep ─────────────────────────────────────────────────

describe('exportData("xlsx") with mocked xlsx peer dep', () => {
  beforeEach(() => {
    jest.resetModules();
    jest.mock('xlsx', () => ({
      utils: {
        json_to_sheet: jest.fn(() => ({})),
        book_new: jest.fn(() => ({})),
        book_append_sheet: jest.fn()
      },
      write: jest.fn(() => new Uint8Array([1, 2, 3]))
    }), { virtual: true });
  });

  afterEach(() => jest.resetModules());

  test('returns a Blob with xlsx MIME type', async () => {
    const TC = require('../src/tablecrafter');
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { data, columns });
    const blob = await t.exportData('xlsx');
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.type).toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  });
});

// ── pdf via mocked peer deps ─────────────────────────────────────────────────

describe('exportData("pdf") with mocked jspdf peer deps', () => {
  beforeEach(() => {
    jest.resetModules();
    const mockDoc = { output: jest.fn(() => new ArrayBuffer(4)) };
    jest.mock('jspdf', () => ({ jsPDF: jest.fn(() => mockDoc) }), { virtual: true });
    jest.mock('jspdf-autotable', () => jest.fn(), { virtual: true });
  });

  afterEach(() => jest.resetModules());

  test('returns a Blob with pdf MIME type', async () => {
    const TC = require('../src/tablecrafter');
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { data, columns });
    const blob = await t.exportData('pdf');
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.type).toBe('application/pdf');
  });
});

// ── downloadExport ────────────────────────────────────────────────────────────

describe('downloadExport()', () => {
  let createObjectURL, revokeObjectURL, clickSpy;

  beforeEach(() => {
    createObjectURL = jest.fn(() => 'blob:mock');
    revokeObjectURL = jest.fn();
    global.URL.createObjectURL = createObjectURL;
    global.URL.revokeObjectURL = revokeObjectURL;
    clickSpy = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  });

  afterEach(() => jest.restoreAllMocks());

  test('triggers a download for csv', async () => {
    const table = makeTable();
    await table.downloadExport('csv');
    expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
    expect(clickSpy).toHaveBeenCalled();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:mock');
  });

  test('triggers a download for json', async () => {
    const table = makeTable();
    await table.downloadExport('json');
    expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
    expect(clickSpy).toHaveBeenCalled();
  });

  test('uses custom filename when provided', async () => {
    const table = makeTable();
    await table.downloadExport('csv', 'my-report.csv');
    const anchor = document.querySelector('a[download]');
    if (anchor) expect(anchor.download).toBe('my-report.csv');
  });
});

// ── UI: multi-format dropdown ─────────────────────────────────────────────────

describe('renderExportControls(): multi-format dropdown', () => {
  test('single csv format renders button, no select', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new (require('../src/tablecrafter'))('#t', {
      data, columns, exportable: true
    });
    t.render();
    expect(document.querySelector('.tc-export-format')).toBeNull();
    expect(document.querySelector('.tc-export-csv')).not.toBeNull();
  });

  test('multiple formats renders a <select.tc-export-format> with correct options', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new (require('../src/tablecrafter'))('#t', {
      data, columns,
      export: { formats: ['csv', 'json'] }
    });
    t.render();
    const sel = document.querySelector('.tc-export-format');
    expect(sel).not.toBeNull();
    const opts = [...sel.querySelectorAll('option')].map(o => o.value);
    expect(opts).toEqual(['csv', 'json']);
  });

  test('exportable:true is backwards-compat — still renders single CSV button', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new (require('../src/tablecrafter'))('#t', {
      data, columns, exportable: true
    });
    t.render();
    expect(document.querySelector('.tc-export-csv')).not.toBeNull();
    expect(document.querySelector('.tc-export-format')).toBeNull();
  });
});
