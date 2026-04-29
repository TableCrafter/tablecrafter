/**
 * Aggregation: group-by support (slice 2 of #48).
 * Stacked on PR #111 (column.aggregate + getAggregates / aggregate API).
 *
 * Adds:
 *   - groupBy(field) -> Map<groupKey, rows[]>
 *   - getGroupAggregates(field) -> Map<groupKey, { [aggField]: value }>
 *
 * Footer / summary-row UI rendering and persistence of computed aggregates
 * remain queued under #48.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, team: 'core',   qty: 10, price: 5  },
  { id: 2, team: 'core',   qty: 20, price: 7  },
  { id: 3, team: 'mobile', qty: 30, price: 11 },
  { id: 4, team: 'mobile', qty: 5,  price: 13 },
  { id: 5, team: null,     qty: 1,  price: 1  }
];

function makeTable(columns) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('groupBy(field)', () => {
  test('returns a Map keyed by group value with the matching rows', () => {
    const t = makeTable([{ field: 'team' }, { field: 'qty' }]);
    const groups = t.groupBy('team');

    expect(groups.size).toBe(3);
    expect(groups.get('core')).toEqual([
      { id: 1, team: 'core', qty: 10, price: 5 },
      { id: 2, team: 'core', qty: 20, price: 7 }
    ]);
    expect(groups.get('mobile')).toHaveLength(2);
    expect(groups.get(null)).toEqual([{ id: 5, team: null, qty: 1, price: 1 }]);
  });

  test('returns Map preserves first-seen order of group keys', () => {
    const t = makeTable([{ field: 'team' }]);
    const keys = Array.from(t.groupBy('team').keys());
    expect(keys).toEqual(['core', 'mobile', null]);
  });

  test('respects the supplied rows array (filtered subset)', () => {
    const t = makeTable([{ field: 'team' }]);
    const subset = data.filter(r => r.team !== null);
    const groups = t.groupBy('team', subset);
    expect(groups.size).toBe(2);
    expect(groups.has(null)).toBe(false);
  });

  test('unknown field yields a single group keyed by undefined', () => {
    const t = makeTable([{ field: 'team' }]);
    const groups = t.groupBy('ghost');
    expect(groups.size).toBe(1);
    expect(groups.get(undefined)).toHaveLength(data.length);
  });
});

describe('getGroupAggregates(field)', () => {
  test('runs every column.aggregate over each group', () => {
    const t = makeTable([
      { field: 'team' },
      { field: 'qty', aggregate: 'sum' },
      { field: 'price', aggregate: 'avg' }
    ]);
    const groupAggs = t.getGroupAggregates('team');

    expect(groupAggs.get('core')).toEqual({ qty: 30, price: 6 });
    expect(groupAggs.get('mobile')).toEqual({ qty: 35, price: 12 });
    expect(groupAggs.get(null)).toEqual({ qty: 1, price: 1 });
  });

  test('only includes fields that declared aggregate', () => {
    const t = makeTable([
      { field: 'team' },
      { field: 'qty', aggregate: 'sum' }
    ]);
    const groupAggs = t.getGroupAggregates('team');
    expect(Object.keys(groupAggs.get('core'))).toEqual(['qty']);
  });

  test('honours an explicit rows argument (post-filter scope)', () => {
    const t = makeTable([
      { field: 'team' },
      { field: 'qty', aggregate: 'sum' }
    ]);
    const subset = data.filter(r => r.qty >= 10);
    const groupAggs = t.getGroupAggregates('team', subset);

    expect(groupAggs.get('core')).toEqual({ qty: 30 });
    expect(groupAggs.get('mobile')).toEqual({ qty: 30 }); // only qty=30 from subset
    expect(groupAggs.has(null)).toBe(false); // qty=1 row excluded
  });
});
