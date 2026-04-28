/**
 * Multi-column sorting tests for issue #45
 *
 * Covers:
 *   - this.sortKeys ordered list
 *   - sort(field, { append, direction })
 *   - multiSort([{ field, direction }, ...])
 *   - Stable composite ordering
 *   - Per-column custom comparator (column.compare)
 *   - Shift+click DOM wiring
 *   - aria-sort + .tc-sort-priority indicators
 *   - State persistence (incl. legacy migration)
 */

const TableCrafter = require('../src/tablecrafter');

const peopleColumns = [
  { field: 'department', label: 'Department' },
  { field: 'lastName', label: 'Last Name' },
  { field: 'firstName', label: 'First Name' },
  { field: 'salary', label: 'Salary' }
];

function people() {
  return [
    { id: 1, department: 'Eng', lastName: 'Smith', firstName: 'Bob', salary: 100 },
    { id: 2, department: 'Eng', lastName: 'Smith', firstName: 'Alice', salary: 90 },
    { id: 3, department: 'Sales', lastName: 'Jones', firstName: 'Carol', salary: 80 },
    { id: 4, department: 'Eng', lastName: 'Adams', firstName: 'Dave', salary: 110 },
    { id: 5, department: 'Sales', lastName: 'Jones', firstName: 'Bob', salary: 85 }
  ];
}

function setup(extraConfig = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    data: people(),
    columns: peopleColumns,
    sortable: true,
    ...extraConfig
  });
}

describe('Multi-column sort: state shape', () => {
  test('sortKeys is initialized as an empty array', () => {
    const table = setup();
    expect(Array.isArray(table.sortKeys)).toBe(true);
    expect(table.sortKeys.length).toBe(0);
  });

  test('legacy sortField/sortOrder mirror sortKeys[0]', () => {
    const table = setup();
    table.sort('department');
    expect(table.sortKeys).toEqual([{ field: 'department', direction: 'asc' }]);
    expect(table.sortField).toBe('department');
    expect(table.sortOrder).toBe('asc');
  });
});

describe('Multi-column sort: sort(field, options)', () => {
  test('sort(field) without options replaces sortKeys with a single entry', () => {
    const table = setup();
    table.sort('department');
    table.sort('lastName');
    expect(table.sortKeys).toEqual([{ field: 'lastName', direction: 'asc' }]);
  });

  test('sort(field) toggles direction on repeat without options', () => {
    const table = setup();
    table.sort('department');
    table.sort('department');
    expect(table.sortKeys).toEqual([{ field: 'department', direction: 'desc' }]);
  });

  test('sort(field, { append: true }) appends a new key', () => {
    const table = setup();
    table.sort('department');
    table.sort('lastName', { append: true });
    expect(table.sortKeys).toEqual([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'asc' }
    ]);
  });

  test('append-mode toggles direction on existing field, no duplicate', () => {
    const table = setup();
    table.sort('department');
    table.sort('lastName', { append: true });
    table.sort('lastName', { append: true });
    expect(table.sortKeys).toEqual([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'desc' }
    ]);
  });

  test('explicit direction is honoured', () => {
    const table = setup();
    table.sort('salary', { direction: 'desc' });
    expect(table.sortKeys).toEqual([{ field: 'salary', direction: 'desc' }]);
  });
});

describe('Multi-column sort: multiSort()', () => {
  test('exposes multiSort as a function', () => {
    const table = setup();
    expect(typeof table.multiSort).toBe('function');
  });

  test('replaces sortKeys with the provided array', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'desc' }
    ]);
    expect(table.sortKeys).toEqual([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'desc' }
    ]);
  });

  test('throws TypeError on non-array input', () => {
    const table = setup();
    expect(() => table.multiSort('department')).toThrow(TypeError);
    expect(() => table.multiSort(null)).toThrow(TypeError);
  });
});

describe('Multi-column sort: composite ordering', () => {
  test('secondary key breaks ties on primary key', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'asc' }
    ]);
    // Eng group: Adams, Smith, Smith. Sales group: Jones, Jones.
    const order = table.data.map(r => `${r.department}/${r.lastName}/${r.firstName}`);
    expect(order).toEqual([
      'Eng/Adams/Dave',
      'Eng/Smith/Bob',
      'Eng/Smith/Alice',
      'Sales/Jones/Carol',
      'Sales/Jones/Bob'
    ]);
  });

  test('three-key sort: department asc, lastName asc, firstName asc', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'asc' },
      { field: 'firstName', direction: 'asc' }
    ]);
    const order = table.data.map(r => r.id);
    // Eng/Adams/Dave(4), Eng/Smith/Alice(2), Eng/Smith/Bob(1), Sales/Jones/Bob(5), Sales/Jones/Carol(3)
    expect(order).toEqual([4, 2, 1, 5, 3]);
  });

  test('descending direction reverses key order', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'salary', direction: 'desc' }
    ]);
    const eng = table.data.filter(r => r.department === 'Eng').map(r => r.salary);
    expect(eng).toEqual([110, 100, 90]);
  });

  test('sort is stable: ties across all keys preserve original order', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const data = [
      { id: 'a', group: 'X' },
      { id: 'b', group: 'X' },
      { id: 'c', group: 'X' },
      { id: 'd', group: 'X' }
    ];
    const table = new TableCrafter('#t', {
      data,
      columns: [{ field: 'group', label: 'G' }, { field: 'id', label: 'ID' }],
      sortable: true
    });
    table.sort('group'); // all equal
    expect(table.data.map(r => r.id)).toEqual(['a', 'b', 'c', 'd']);
  });
});

describe('Multi-column sort: custom comparator', () => {
  test('column.compare is used when provided', () => {
    document.body.innerHTML = '<div id="t"></div>';
    // Reverse-length comparator on a string field
    const data = [
      { id: 1, label: 'short' },
      { id: 2, label: 'medium-long' },
      { id: 3, label: 'tiny' },
      { id: 4, label: 'a-very-long-label' }
    ];
    const compare = jest.fn((a, b) => a.length - b.length);
    const table = new TableCrafter('#t', {
      data,
      columns: [
        { field: 'id', label: 'ID' },
        { field: 'label', label: 'Label', compare }
      ],
      sortable: true
    });
    table.sort('label');
    expect(compare).toHaveBeenCalled();
    expect(table.data.map(r => r.id)).toEqual([3, 1, 2, 4]);
  });
});

describe('Multi-column sort: DOM event wiring', () => {
  test('shift+click on header triggers append-mode sort', () => {
    const table = setup();
    table.sort('department');

    const header = document.querySelector('th[data-field="lastName"]');
    expect(header).not.toBeNull();
    header.dispatchEvent(new MouseEvent('click', { bubbles: true, shiftKey: true }));

    expect(table.sortKeys).toEqual([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'asc' }
    ]);
  });

  test('plain click on header replaces sort (single-column behaviour)', () => {
    const table = setup();
    table.sort('department');
    table.sort('lastName', { append: true });

    const header = document.querySelector('th[data-field="firstName"]');
    header.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    expect(table.sortKeys).toEqual([{ field: 'firstName', direction: 'asc' }]);
  });
});

describe('Multi-column sort: accessibility & priority indicators', () => {
  test('primary key gets aria-sort=ascending; secondary gets aria-sort=other', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'desc' }
    ]);

    const dept = document.querySelector('th[data-field="department"]');
    const last = document.querySelector('th[data-field="lastName"]');
    const first = document.querySelector('th[data-field="firstName"]');

    expect(dept.getAttribute('aria-sort')).toBe('ascending');
    expect(last.getAttribute('aria-sort')).toBe('other');
    expect(first.getAttribute('aria-sort')).toBe('none');
  });

  test('priority badge appears on each sorted header when multi-sorting', () => {
    const table = setup();
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'lastName', direction: 'asc' }
    ]);

    const deptBadge = document.querySelector('th[data-field="department"] .tc-sort-priority');
    const lastBadge = document.querySelector('th[data-field="lastName"] .tc-sort-priority');
    expect(deptBadge).not.toBeNull();
    expect(lastBadge).not.toBeNull();
    expect(deptBadge.textContent).toBe('1');
    expect(lastBadge.textContent).toBe('2');
  });

  test('priority badge is absent for single-column sort', () => {
    const table = setup();
    table.sort('department');
    const badge = document.querySelector('th[data-field="department"] .tc-sort-priority');
    expect(badge).toBeNull();
  });
});

describe('Multi-column sort: state persistence', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  test('sortKeys round-trips through save/load', () => {
    const table = setup({
      state: { persist: true, storage: 'localStorage', key: 'tc-multi-sort-test' }
    });
    table.multiSort([
      { field: 'department', direction: 'asc' },
      { field: 'salary', direction: 'desc' }
    ]);
    table.saveState();

    document.body.innerHTML = '<div id="t"></div>';
    const reloaded = new TableCrafter('#t', {
      data: people(),
      columns: peopleColumns,
      sortable: true,
      state: { persist: true, storage: 'localStorage', key: 'tc-multi-sort-test' }
    });

    expect(reloaded.sortKeys).toEqual([
      { field: 'department', direction: 'asc' },
      { field: 'salary', direction: 'desc' }
    ]);
  });

  test('legacy { sortField, sortOrder } state migrates to sortKeys on load', () => {
    localStorage.setItem(
      'tc-legacy-sort-test',
      JSON.stringify({
        filters: {},
        sortField: 'department',
        sortOrder: 'desc',
        currentPage: 1,
        selectedRows: [],
        timestamp: Date.now()
      })
    );

    document.body.innerHTML = '<div id="t"></div>';
    const table = new TableCrafter('#t', {
      data: people(),
      columns: peopleColumns,
      sortable: true,
      state: { persist: true, storage: 'localStorage', key: 'tc-legacy-sort-test' }
    });

    expect(table.sortKeys).toEqual([{ field: 'department', direction: 'desc' }]);
    expect(table.sortField).toBe('department');
    expect(table.sortOrder).toBe('desc');
  });
});
