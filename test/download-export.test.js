/**
 * downloadExport(format, filename?) — slice 2 of #46.
 * Stacked on PR #79 (exportData dispatcher + JSON output).
 *
 * xlsx and pdf paths still throw the not-yet-available error from PR #79;
 * downloadExport surfaces those as a rejected promise without triggering
 * a download.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [{ id: 1, name: 'A' }, { id: 2, name: 'B' }];
const columns = [{ field: 'id', label: 'ID' }, { field: 'name', label: 'Name' }];

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns, ...extra });
}

function spyDownloadMechanism() {
  const created = [];
  const revoked = [];
  const clicks = [];

  const origCreateObjectURL = URL.createObjectURL;
  const origRevokeObjectURL = URL.revokeObjectURL;

  URL.createObjectURL = blob => {
    created.push(blob);
    return 'blob:mock-' + created.length;
  };
  URL.revokeObjectURL = url => revoked.push(url);

  const origCreateElement = document.createElement.bind(document);
  document.createElement = tagName => {
    const el = origCreateElement(tagName);
    if (tagName === 'a') {
      el.click = () => clicks.push({ href: el.href, download: el.download });
    }
    return el;
  };

  return {
    created, revoked, clicks,
    restore() {
      URL.createObjectURL = origCreateObjectURL;
      URL.revokeObjectURL = origRevokeObjectURL;
      document.createElement = origCreateElement;
    }
  };
}

describe('downloadExport(format, filename?)', () => {
  test('csv path triggers a download with the configured filename', async () => {
    const table = makeTable({ exportFilename: 'my-table.csv' });
    const spy = spyDownloadMechanism();

    await table.downloadExport('csv');

    expect(spy.clicks).toHaveLength(1);
    expect(spy.clicks[0].download).toBe('my-table.csv');
    expect(spy.created).toHaveLength(1);
    expect(spy.revoked).toHaveLength(1);

    spy.restore();
  });

  test('json path triggers a download with .json extension when no filename given', async () => {
    const table = makeTable({ exportFilename: 'my-table.csv' });
    const spy = spyDownloadMechanism();

    await table.downloadExport('json');

    expect(spy.clicks).toHaveLength(1);
    expect(spy.clicks[0].download).toMatch(/\.json$/);

    spy.restore();
  });

  test('explicit filename argument overrides the configured default', async () => {
    const table = makeTable({ exportFilename: 'default.csv' });
    const spy = spyDownloadMechanism();

    await table.downloadExport('json', 'custom-name.json');

    expect(spy.clicks[0].download).toBe('custom-name.json');

    spy.restore();
  });

  test('xlsx rejects without triggering a download', async () => {
    const table = makeTable();
    const spy = spyDownloadMechanism();

    await expect(table.downloadExport('xlsx')).rejects.toThrow(/xlsx|not available/i);
    expect(spy.clicks).toHaveLength(0);
    expect(spy.created).toHaveLength(0);

    spy.restore();
  });

  test('pdf rejects without triggering a download', async () => {
    const table = makeTable();
    const spy = spyDownloadMechanism();

    await expect(table.downloadExport('pdf')).rejects.toThrow(/pdf|not available/i);
    expect(spy.clicks).toHaveLength(0);

    spy.restore();
  });

  test('unsupported format rejects without triggering a download', async () => {
    const table = makeTable();
    const spy = spyDownloadMechanism();

    await expect(table.downloadExport('zip')).rejects.toThrow(/unsupported|format/i);
    expect(spy.clicks).toHaveLength(0);

    spy.restore();
  });
});
