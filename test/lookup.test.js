// loadLookupData, createLookupDropdown, formatLookupValue

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'categoryId' }],
    data: [{ id: 1, categoryId: '2' }],
    ...extra
  });
}

const CATEGORIES = [
  { id: '1', name: 'Electronics' },
  { id: '2', name: 'Books' },
  { id: '3', name: 'Clothing' }
];

// ── loadLookupData ───────────────────────────────────────────────────────────

describe('loadLookupData', () => {
  test('returns static data provided in lookupConfig.data', async () => {
    const t = makeTable();
    const result = await t.loadLookupData('categoryId', { data: CATEGORIES });
    expect(result).toEqual(CATEGORIES);
  });

  test('caches result on second call', async () => {
    const t = makeTable();
    const cfg = { data: CATEGORIES };
    await t.loadLookupData('categoryId', cfg);
    const spy = jest.spyOn(t.lookupCache, 'get');
    await t.loadLookupData('categoryId', cfg);
    expect(spy).toHaveBeenCalled();
  });

  test('applies filter when lookupConfig.filter provided', async () => {
    const t = makeTable();
    const result = await t.loadLookupData('categoryId', {
      data: CATEGORIES,
      filter: { name: 'Books' }
    });
    expect(result).toEqual([{ id: '2', name: 'Books' }]);
  });

  test('fetches from URL when lookupConfig.url provided', async () => {
    const t = makeTable();
    global.fetch.mockResolvedValueOnce({
      json: () => Promise.resolve(CATEGORIES)
    });
    const result = await t.loadLookupData('categoryId', { url: 'https://api.example.com/categories' });
    expect(result).toEqual(CATEGORIES);
  });

  test('returns empty array on error', async () => {
    const t = makeTable();
    const result = await t.loadLookupData('categoryId', {}); // no source
    expect(result).toEqual([]);
  });
});

// ── createLookupDropdown ─────────────────────────────────────────────────────

describe('createLookupDropdown', () => {
  test('returns null when no lookup config on column', async () => {
    const t = makeTable();
    const result = await t.createLookupDropdown({ field: 'id' }, '1');
    expect(result).toBeNull();
  });

  test('returns a select populated with lookup data', async () => {
    const t = makeTable();
    const select = await t.createLookupDropdown(
      { field: 'categoryId', lookup: { data: CATEGORIES, valueField: 'id', displayField: 'name' } },
      '2'
    );
    expect(select.tagName).toBe('SELECT');
    const options = select.querySelectorAll('option');
    // empty option + 3 items
    expect(options.length).toBe(4);
    // selected value matches
    expect(select.value).toBe('2');
  });

  test('selected option matches currentValue', async () => {
    const t = makeTable();
    const select = await t.createLookupDropdown(
      { field: 'categoryId', lookup: { data: CATEGORIES, valueField: 'id', displayField: 'name' } },
      '3'
    );
    expect(select.value).toBe('3');
  });
});

// ── formatLookupValue ────────────────────────────────────────────────────────

describe('formatLookupValue', () => {
  test('returns display field value for matching lookup item', async () => {
    const t = makeTable();
    const column = { field: 'categoryId', lookup: { data: CATEGORIES, valueField: 'id', displayField: 'name' } };
    const result = await t.formatLookupValue(column, '1');
    expect(result).toBe('Electronics');
  });

  test('returns raw value when no match found', async () => {
    const t = makeTable();
    const column = { field: 'categoryId', lookup: { data: CATEGORIES, valueField: 'id', displayField: 'name' } };
    const result = await t.formatLookupValue(column, '99');
    expect(result).toBe('99');
  });

  test('returns value unchanged when no lookup config', async () => {
    const t = makeTable();
    const result = await t.formatLookupValue({ field: 'id' }, 'abc');
    expect(result).toBe('abc');
  });

  test('returns falsy value as-is', async () => {
    const t = makeTable();
    const result = await t.formatLookupValue(
      { field: 'categoryId', lookup: { data: CATEGORIES } },
      null
    );
    expect(result).toBeNull();
  });
});
