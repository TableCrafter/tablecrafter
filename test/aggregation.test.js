/**
 * Aggregation foundation (slice 1 of #48).
 *
 * Adds column.aggregate config + getAggregates() / aggregate() helpers.
 * Footer-row UI, group-by per-group totals, and state persistence remain
 * queued under #48 follow-ups.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, qty: 10, price: 5.5,   note: 'a'  },
  { id: 2, qty: 20, price: 'n/a', note: null },
  { id: 3, qty: 30, price: 7.0,   note: 'b'  },
  { id: 4, qty: 0,  price: 12.5,  note: 'c'  }
];

function makeTable(columns) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('Aggregation: built-in operators', () => {
  test('sum over numeric values', () => {
    const t = makeTable([{ field: 'qty', aggregate: 'sum' }]);
    expect(t.getAggregates().qty).toBe(60);
  });

  test('avg over numeric values, ignoring non-numeric', () => {
    const t = makeTable([{ field: 'price', aggregate: 'avg' }]);
    expect(t.getAggregates().price).toBeCloseTo((5.5 + 7.0 + 12.5) / 3);
  });

  test('count counts non-null cells', () => {
    const t = makeTable([{ field: 'note', aggregate: 'count' }]);
    expect(t.getAggregates().note).toBe(3); // null skipped
  });

  test('min and max ignore non-numeric cells', () => {
    const t = makeTable([
      { field: 'qty',   aggregate: 'min' },
      { field: 'price', aggregate: 'max' }
    ]);
    const agg = t.getAggregates();
    expect(agg.qty).toBe(0);
    expect(agg.price).toBe(12.5);
  });

  test('numeric ops on a column with no numeric cells return null', () => {
    const t = makeTable([{ field: 'note', aggregate: 'sum' }]);
    expect(t.getAggregates().note).toBeNull();
  });
});

describe('Aggregation: custom function', () => {
  test('receives (values, rows) and the return value lands as-is', () => {
    const fn = jest.fn((values, rows) => `${rows.length} rows / ${values.length} qtys`);
    const t = makeTable([{ field: 'qty', aggregate: fn }]);

    const agg = t.getAggregates();
    expect(fn).toHaveBeenCalledTimes(1);
    expect(fn.mock.calls[0][0]).toEqual([10, 20, 30, 0]);   // values
    expect(fn.mock.calls[0][1]).toHaveLength(4);             // rows
    expect(agg.qty).toBe('4 rows / 4 qtys');
  });
});

describe('Aggregation: getAggregates() shape', () => {
  test('only includes fields that declared aggregate', () => {
    const t = makeTable([
      { field: 'id' },
      { field: 'qty', aggregate: 'sum' },
      { field: 'price' }
    ]);
    expect(Object.keys(t.getAggregates())).toEqual(['qty']);
  });

  test('uses getFilteredData() when rows arg is omitted', () => {
    const t = makeTable([{ field: 'qty', aggregate: 'sum' }]);
    t.searchTerm = 'b'; // 'b' only appears in row 3's note field
    expect(t.getAggregates().qty).toBe(30); // qty of just row 3
  });

  test('honours an explicit rows argument', () => {
    const t = makeTable([{ field: 'qty', aggregate: 'sum' }]);
    expect(t.getAggregates([{ qty: 1 }, { qty: 2 }]).qty).toBe(3);
  });
});

describe('aggregate() one-shot helper', () => {
  test('honours the column-declared aggregate when no fn passed', () => {
    const t = makeTable([{ field: 'qty', aggregate: 'sum' }]);
    expect(t.aggregate('qty')).toBe(60);
  });

  test('explicit fn overrides the declared aggregate', () => {
    const t = makeTable([{ field: 'qty', aggregate: 'sum' }]);
    expect(t.aggregate('qty', 'avg')).toBe(15);
  });

  test('returns null when neither column nor explicit fn is configured', () => {
    const t = makeTable([{ field: 'qty' }]);
    expect(t.aggregate('qty')).toBeNull();
  });
});
