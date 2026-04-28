/**
 * Export-format dispatcher (slice of #46) — exportData(format) entry point.
 * Covers csv pass-through and json output. xlsx, pdf, downloadExport, and
 * the UI dropdown are deferred to follow-up PRs.
 */

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
