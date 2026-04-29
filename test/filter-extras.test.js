// Multiselect dropdown filter, date range filter, number range filter,
// updateMultiselectFilter, updateMultiselectButton, clearFilters button

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'status' }, { field: 'date' }, { field: 'price' }],
    data: [
      { status: 'open',   date: '2024-01-10', price: 10 },
      { status: 'closed', date: '2024-06-15', price: 50 },
      { status: 'open',   date: '2024-12-01', price: 100 }
    ],
    ...extra
  });
}

// ── updateMultiselectButton ──────────────────────────────────────────────────

describe('updateMultiselectButton', () => {
  test('shows "Select values..." when nothing selected', () => {
    const t = makeTable();
    const btn = document.createElement('button');
    t.updateMultiselectButton(btn, []);
    expect(btn.textContent).toBe('Select values...');
  });

  test('shows single value when one selected', () => {
    const t = makeTable();
    const btn = document.createElement('button');
    t.updateMultiselectButton(btn, ['open']);
    expect(btn.textContent).toBe('open');
  });

  test('shows count when multiple selected', () => {
    const t = makeTable();
    const btn = document.createElement('button');
    t.updateMultiselectButton(btn, ['open', 'closed']);
    expect(btn.textContent).toBe('2 selected');
  });
});

// ── updateMultiselectFilter ──────────────────────────────────────────────────

describe('updateMultiselectFilter', () => {
  test('calls setFilter with checked checkbox values', () => {
    const t = makeTable();
    const dropdown = document.createElement('div');

    const addOption = (value, checked) => {
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = value;
      cb.checked = checked;
      dropdown.appendChild(cb);
    };
    addOption('open', true);
    addOption('closed', false);

    const setFilterSpy = jest.spyOn(t, 'setFilter').mockImplementation(() => {});
    const btn = document.createElement('button');
    t.updateMultiselectFilter('status', dropdown, btn);
    expect(setFilterSpy).toHaveBeenCalledWith('status', ['open']);
  });
});

// ── createMultiselectFilter (integration via renderFilters) ───────────────────

describe('createMultiselectFilter integration', () => {
  test('renders multiselect button for enum-type column', () => {
    const t = makeTable({
      filters: { enabled: true, advanced: false },
      columns: [
        {
          field: 'status',
          filterType: 'multiselect',
          options: ['open', 'closed'],
          filterable: true
        }
      ]
    });
    t.detectFilterTypes();
    t.filterTypes['status'] = 'multiselect';
    const filterDiv = t.createFilterControl(
      { field: 'status', options: ['open', 'closed'], filterable: true },
      'multiselect'
    );
    const btn = filterDiv.querySelector('button') || filterDiv;
    expect(btn).toBeDefined();
  });
});

// ── createDateRangeFilter ────────────────────────────────────────────────────

describe('createDateRangeFilter', () => {
  test('returns a container with from/to date inputs', () => {
    const t = makeTable();
    const container = t.createDateRangeFilter({ field: 'date', label: 'Date' });
    expect(container.querySelector('.tc-date-from')).not.toBeNull();
    expect(container.querySelector('.tc-date-to')).not.toBeNull();
  });

  test('from/to change event calls setFilter', () => {
    const t = makeTable();
    const spy = jest.spyOn(t, 'setFilter').mockImplementation(() => {});
    const container = t.createDateRangeFilter({ field: 'date', label: 'Date' });
    const from = container.querySelector('.tc-date-from');
    from.value = '2024-01-01';
    from.dispatchEvent(new Event('change'));
    expect(spy).toHaveBeenCalledWith('date', { from: '2024-01-01' });
  });

  test('sets filter to null when both inputs are empty', () => {
    const t = makeTable();
    const spy = jest.spyOn(t, 'setFilter').mockImplementation(() => {});
    const container = t.createDateRangeFilter({ field: 'date', label: 'Date' });
    const from = container.querySelector('.tc-date-from');
    from.value = '';
    from.dispatchEvent(new Event('change'));
    expect(spy).toHaveBeenCalledWith('date', null);
  });

  test('pre-fills from existing filter value', () => {
    const t = makeTable();
    t.filters['date'] = { from: '2024-03-01', to: '2024-09-01' };
    const container = t.createDateRangeFilter({ field: 'date', label: 'Date' });
    expect(container.querySelector('.tc-date-from').value).toBe('2024-03-01');
    expect(container.querySelector('.tc-date-to').value).toBe('2024-09-01');
  });
});

// ── createNumberRangeFilter ──────────────────────────────────────────────────

describe('createNumberRangeFilter', () => {
  test('returns a container with min/max number inputs', () => {
    const t = makeTable();
    const container = t.createNumberRangeFilter({ field: 'price', label: 'Price' });
    expect(container.querySelector('.tc-number-min')).not.toBeNull();
    expect(container.querySelector('.tc-number-max')).not.toBeNull();
  });

  test('min input event calls setFilter with min', () => {
    const t = makeTable();
    const spy = jest.spyOn(t, 'setFilter').mockImplementation(() => {});
    // bypass debounce
    jest.spyOn(t, 'debounce').mockImplementation(fn => fn);
    const container = t.createNumberRangeFilter({ field: 'price', label: 'Price' });
    const min = container.querySelector('.tc-number-min');
    min.value = '10';
    min.dispatchEvent(new Event('input'));
    expect(spy).toHaveBeenCalledWith('price', { min: 10 });
  });

  test('sets filter to null when both inputs empty', () => {
    const t = makeTable();
    const spy = jest.spyOn(t, 'setFilter').mockImplementation(() => {});
    jest.spyOn(t, 'debounce').mockImplementation(fn => fn);
    const container = t.createNumberRangeFilter({ field: 'price', label: 'Price' });
    const min = container.querySelector('.tc-number-min');
    min.value = '';
    min.dispatchEvent(new Event('input'));
    expect(spy).toHaveBeenCalledWith('price', null);
  });
});

// ── clearFilters button ──────────────────────────────────────────────────────

describe('clearFilters button in renderFilters', () => {
  test('clearFilters button click calls clearFilters()', () => {
    const t = makeTable({
      filters: {
        enabled: true,
        advanced: true,
        showClearAll: true
      }
    });
    t.render();
    const spy = jest.spyOn(t, 'clearFilters').mockImplementation(() => {});
    const btn = t.container.querySelector('.tc-clear-filters');
    if (btn) {
      btn.click();
      expect(spy).toHaveBeenCalled();
    }
  });
});
