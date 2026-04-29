/**
 * CSV import foundation (slice 1 of #60).
 *
 * RFC-4180-flavoured parser: quoted fields can contain commas, newlines,
 * and embedded `""`. Excel xlsx, JSON, drag-and-drop UI, and schema-aware
 * import remain queued.
 */

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'a' }, { field: 'b' }, { field: 'c' }],
    data: [{ a: 1, b: 2, c: 3 }]
  });
}

describe('parseCSV: basic', () => {
  test('plain comma-separated values + header → array of objects', () => {
    const t = makeTable();
    const { rows, errors } = t.parseCSV('a,b,c\n1,2,3\n4,5,6');
    expect(errors).toEqual([]);
    expect(rows).toEqual([
      { a: '1', b: '2', c: '3' },
      { a: '4', b: '5', c: '6' }
    ]);
  });

  test('header: false → array of arrays', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('1,2,3\n4,5,6', { header: false });
    expect(rows).toEqual([['1', '2', '3'], ['4', '5', '6']]);
  });

  test('custom delimiter', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a;b;c\n1;2;3', { delimiter: ';' });
    expect(rows).toEqual([{ a: '1', b: '2', c: '3' }]);
  });

  test('TSV via tab delimiter', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a\tb\tc\n1\t2\t3', { delimiter: '\t' });
    expect(rows).toEqual([{ a: '1', b: '2', c: '3' }]);
  });
});

describe('parseCSV: quoted fields', () => {
  test('embedded comma inside quoted value', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a,b\n"hello, world",2');
    expect(rows).toEqual([{ a: 'hello, world', b: '2' }]);
  });

  test('embedded newline inside quoted value', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a,b\n"line1\nline2",2');
    expect(rows).toEqual([{ a: 'line1\nline2', b: '2' }]);
  });

  test('"" inside quoted value → literal "', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a,b\n"she said ""hi""",2');
    expect(rows).toEqual([{ a: 'she said "hi"', b: '2' }]);
  });

  test('mixed quoted and unquoted fields on the same row', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a,b,c\n1,"with, comma",3');
    expect(rows).toEqual([{ a: '1', b: 'with, comma', c: '3' }]);
  });
});

describe('parseCSV: error reporting', () => {
  test('a line with too many / too few fields surfaces in errors but does not throw', () => {
    const t = makeTable();
    const { rows, errors } = t.parseCSV('a,b,c\n1,2,3\n1,2\n4,5,6');
    expect(errors).toEqual([
      expect.objectContaining({ line: 3 })
    ]);
    expect(rows).toEqual([
      { a: '1', b: '2', c: '3' },
      { a: '4', b: '5', c: '6' }
    ]);
  });

  test('handles \\r\\n line endings', () => {
    const t = makeTable();
    const { rows } = t.parseCSV('a,b\r\n1,2\r\n3,4');
    expect(rows).toEqual([
      { a: '1', b: '2' },
      { a: '3', b: '4' }
    ]);
  });

  test('empty input yields rows: []', () => {
    const t = makeTable();
    expect(t.parseCSV('').rows).toEqual([]);
    expect(t.parseCSV('   ').rows).toEqual([]);
  });
});

describe('importCSV: applies to this.data', () => {
  test('replaces this.data by default', () => {
    const t = makeTable();
    t.importCSV('a,b\n10,20');
    expect(t.data).toEqual([{ a: '10', b: '20' }]);
  });

  test('append: true extends this.data', () => {
    const t = makeTable();
    t.importCSV('a,b,c\n9,9,9', { append: true });
    expect(t.data).toEqual([
      { a: 1, b: 2, c: 3 },
      { a: '9', b: '9', c: '9' }
    ]);
  });
});
