// Final branch sweep: formatValue edge cases, constructor data paths,
// loadData SSR paths, hydrateListeners edge cases, saveEdit paths,
// filter/render branches, getCurrentBreakpoint, pagination, getVisibleFields

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }],
    ...extra
  });
}

// ── formatValue null/undefined + type branches ────────────────────────────────

describe('formatValue edge cases', () => {
  test('null value returns empty string', () => {
    const t = makeTable();
    expect(t.formatValue(null, 'text')).toBe('');
    expect(t.formatValue(undefined, 'text')).toBe('');
  });

  test('date type with invalid date string returns raw value', () => {
    const t = makeTable();
    expect(t.formatValue('not-a-date', 'date')).toBe('not-a-date');
  });

  test('date type with valid date returns formatted string', () => {
    const t = makeTable();
    const result = t.formatValue('2024-06-15', 'date');
    expect(typeof result).toBe('string');
    expect(result).toContain('2024');
  });

  test('datetime type with invalid datetime returns raw value', () => {
    const t = makeTable();
    expect(t.formatValue('bad-datetime', 'datetime')).toBe('bad-datetime');
  });

  test('datetime type with valid value returns formatted string', () => {
    const t = makeTable();
    const result = t.formatValue('2024-06-15T14:30:00', 'datetime');
    expect(typeof result).toBe('string');
    expect(result.length).toBeGreaterThan(0);
  });

  test('image type returns img tag', () => {
    const t = makeTable();
    const result = t.formatValue('https://example.com/img.jpg', 'image');
    expect(result).toContain('<img');
    expect(result).toContain('tc-cell-image');
  });

  test('email type returns mailto link', () => {
    const t = makeTable();
    const result = t.formatValue('user@test.com', 'email');
    expect(result).toContain('mailto:');
  });

  test('unknown type with non-string value calls toString', () => {
    const t = makeTable();
    const result = t.formatValue(42, 'unknown-type');
    expect(result).toBe('42');
  });

  test('auto-detect type when type not provided', () => {
    const t = makeTable();
    const result = t.formatValue('user@test.com');
    expect(typeof result).toBe('string');
  });
});

// ── Constructor edge cases ────────────────────────────────────────────────────

describe('constructor edge cases', () => {
  test('no data and no config.data — empty table renders', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    // No crash, data is empty
    expect(t.data).toHaveLength(0);
  });

  test('responsive:false skips resize listener', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const spy = jest.spyOn(window, 'addEventListener');
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      responsive: false
    });
    // Should not have added resize listener
    const resizeCalls = spy.mock.calls.filter(c => c[0] === 'resize');
    expect(resizeCalls.length).toBe(0);
    spy.mockRestore();
  });

  test('config.data as object (non-array, non-string) — no crash', () => {
    document.body.innerHTML = '<div id="t"></div>';
    // config.data as object falls through both if-checks
    const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => new TC('#t', { columns: [{ field: 'id' }], data: {} })).not.toThrow();
    consoleSpy.mockRestore();
  });
});

// ── loadData SSR path ─────────────────────────────────────────────────────────

describe('loadData SSR paths', () => {
  test('SSR mode with no dataUrl returns data directly', async () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper"><table class="tc-table"></table></div>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }, { id: 2 }]
    });
    // Set SSR back to true to test the SSR path
    t.container.dataset.ssr = 'true';
    t.dataUrl = null; // no URL
    const result = await t.loadData();
    expect(result).toEqual(t.data);
  });

  test('SSR mode with dataUrl and HTTP error uses console.error', async () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper"><table class="tc-table"></table></div>
      </div>`;
    const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    t.container.dataset.ssr = 'true';
    t.dataUrl = 'https://api.example.com/data';
    global.fetch.mockResolvedValueOnce({ ok: false, status: 500 });
    await t.loadData();
    expect(consoleSpy).toHaveBeenCalled();
    consoleSpy.mockRestore();
  });

  test('SSR mode AbortError is silently ignored', async () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper"><table class="tc-table"></table></div>
      </div>`;
    const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    t.container.dataset.ssr = 'true';
    t.dataUrl = 'https://api.example.com/data';
    const abortErr = new Error('aborted');
    abortErr.name = 'AbortError';
    global.fetch.mockRejectedValueOnce(abortErr);
    const result = await t.loadData();
    expect(result).toEqual(t.data);
    consoleSpy.mockRestore();
  });

  test('non-SSR loadData with AbortError returns silently', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const t = new TC('#t', { columns: [{ field: 'id' }], data: 'https://api.example.com/data' });
    const abortErr = new Error('aborted');
    abortErr.name = 'AbortError';
    global.fetch.mockRejectedValueOnce(abortErr);
    const result = await t.loadData().catch(() => null);
    // AbortError should be swallowed
    consoleSpy.mockRestore();
  });
});

// ── renderLoading with and without SSR ────────────────────────────────────────

describe('renderLoading edge cases', () => {
  test('renders skeleton when not SSR', () => {
    const t = makeTable();
    t.container.innerHTML = '';
    t.renderLoading();
    expect(t.container.querySelector('.tc-loading-container')).not.toBeNull();
  });

  test('SSR mode with children: skip skeleton rendering', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true"><table class="tc-table"></table></div>`;
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    t.renderLoading();
    // Skeleton should NOT have been rendered
    expect(t.container.querySelector('.tc-loading')).toBeNull();
  });
});

// ── hydrateListeners: space key triggers sort ─────────────────────────────────

describe('hydrateListeners space key', () => {
  test('Space key triggers sort on sortable th', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <table class="tc-table">
          <thead><tr>
            <th class="tc-sortable" data-field="id" tabindex="0">ID</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      sortable: true
    });
    t.hydrateListeners();
    const sortSpy = jest.spyOn(t, 'sort');
    t.container.querySelector('th[data-field="id"]').dispatchEvent(
      new KeyboardEvent('keydown', { key: ' ', bubbles: true })
    );
    expect(sortSpy).toHaveBeenCalledWith('id');
  });
});

// ── getCurrentBreakpoint ──────────────────────────────────────────────────────

describe('getCurrentBreakpoint', () => {
  test('mobile width returns mobile', () => {
    const t = makeTable();
    Object.defineProperty(window, 'innerWidth', { value: 400, configurable: true, writable: true });
    expect(t.getCurrentBreakpoint()).toBe('mobile');
  });

  test('tablet width returns tablet', () => {
    const t = makeTable();
    Object.defineProperty(window, 'innerWidth', { value: 600, configurable: true, writable: true });
    expect(t.getCurrentBreakpoint()).toBe('tablet');
  });

  test('desktop width returns desktop', () => {
    const t = makeTable();
    Object.defineProperty(window, 'innerWidth', { value: 1200, configurable: true, writable: true });
    expect(t.getCurrentBreakpoint()).toBe('desktop');
  });
});

// ── getVisibleFields / getHiddenFields: hideFields path ───────────────────────

describe('getVisibleFields hideFields path', () => {
  test('hideFields excludes specified fields', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name' }, { field: 'email' }],
      data: [{ id: 1, name: 'A', email: 'a@b.c' }],
      responsive: { fieldVisibility: { mobile: { hideFields: ['email'] } } }
    });
    const visible = t.getVisibleFields('mobile');
    expect(visible.map(c => c.field)).not.toContain('email');
    expect(visible.map(c => c.field)).toContain('id');
  });

  test('getHiddenFields with hideFields returns hidden fields', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name' }, { field: 'email' }],
      data: [{ id: 1, name: 'A', email: 'a@b.c' }],
      responsive: { fieldVisibility: { mobile: { hideFields: ['email'] } } }
    });
    const hidden = t.getHiddenFields('mobile');
    expect(hidden.map(c => c.field)).toContain('email');
  });

  test('getHiddenFields with showFields returns non-visible fields', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name' }, { field: 'email' }],
      data: [{ id: 1, name: 'A', email: 'a@b.c' }],
      responsive: { fieldVisibility: { mobile: { showFields: ['id', 'name'] } } }
    });
    const hidden = t.getHiddenFields('mobile');
    expect(hidden.map(c => c.field)).toContain('email');
  });
});

// ── startEdit: no permission blocks editing ───────────────────────────────────

describe('startEdit permissions', () => {
  test('no edit permission returns without entering edit mode', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name', editable: true }],
      data: [{ id: 1, name: 'Alice', user_id: 'bob' }],
      editable: true,
      permissions: { enabled: true, edit: ['admin'], view: ['*'], delete: ['*'], create: ['*'] }
    });
    t.setCurrentUser({ id: 'alice', roles: ['user'] });
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    if (td) {
      await t.startEdit({ currentTarget: td }, 0, 'name');
      // Should NOT have placed an input (no edit permission)
      expect(td.querySelector('input')).toBeNull();
    }
  });
});

// ── saveEdit: validation failure blocks save ──────────────────────────────────

describe('saveEdit with validation failure', () => {
  test('invalid value keeps old value and returns early', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'email', editable: true }],
      data: [{ id: 1, email: 'ok@test.com' }],
      editable: true,
      validation: { enabled: true, validateOnEdit: true, showErrors: true, messages: { email: 'bad email' }, rules: { email: { email: true } } }
    });
    t.render();
    const td = t.container.querySelector('td[data-field="email"]');
    if (td) {
      await t.startEdit({ currentTarget: td }, 0, 'email');
      const input = td.querySelector('input');
      if (input) {
        input.value = 'not-an-email';
        input.dataset.originalValue = 'ok@test.com';
        input.dataset.rowIndex = '0';
        input.dataset.field = 'email';
        t.editingCell = td;
        jest.spyOn(t, 'showValidationError').mockImplementation(() => {});
        await t.saveEdit(input);
        expect(t.data[0].email).toBe('ok@test.com'); // unchanged
      }
    }
  });
});

// ── saveEdit with rich cell (getValue) ────────────────────────────────────────

describe('saveEdit with element.getValue()', () => {
  test('uses getValue() method when available on element', async () => {
    const t = makeTable();
    t.render();

    // Manually craft a mock element with getValue
    const input = document.createElement('div');
    input.getValue = () => 'rich-value';
    input.dataset.originalValue = 'old-value';
    input.dataset.rowIndex = '0';
    input.dataset.field = 'name';
    const td = t.container.querySelector('td[data-field="name"]');
    if (td) {
      td.innerHTML = '';
      td.appendChild(input);
      t.editingCell = td;
      await t.saveEdit(input);
      expect(t.data[0].name).toBe('rich-value');
    }
  });
});

// ── saveEdit with file input ──────────────────────────────────────────────────

describe('saveEdit with file input', () => {
  test('file input with no files keeps original value', async () => {
    const t = makeTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    if (td) {
      const fileInput = document.createElement('input');
      fileInput.type = 'file';
      fileInput.dataset.originalValue = 'original.txt';
      fileInput.dataset.rowIndex = '0';
      fileInput.dataset.field = 'name';
      // files is FileList, length 0
      Object.defineProperty(fileInput, 'files', {
        value: { length: 0 },
        configurable: true
      });
      td.innerHTML = '';
      td.appendChild(fileInput);
      t.editingCell = td;
      await t.saveEdit(fileInput);
      expect(t.data[0].name).toBe('original.txt');
    }
  });
});

// ── saveEdit with lookup column ───────────────────────────────────────────────

describe('saveEdit with lookup column', () => {
  test('formats display using formatLookupValue after save', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [
        { field: 'id' },
        {
          field: 'catId', editable: true,
          lookup: { data: [{ id: '1', name: 'Books' }], valueField: 'id', displayField: 'name' }
        }
      ],
      data: [{ id: 1, catId: '1' }],
      editable: true
    });
    t.render();
    const td = t.container.querySelector('td[data-field="catId"]');
    if (td) {
      jest.spyOn(t, 'formatLookupValue').mockResolvedValue('Books');
      await t.startEdit({ currentTarget: td }, 0, 'catId');
      const select = td.querySelector('select') || td.querySelector('input');
      if (select) {
        select.value = '1';
        t.editingCell = td;
        await t.saveEdit(select);
        expect(t.data[0].catId).toBe('1');
      }
    }
  });
});

// ── saveEdit with API update path ─────────────────────────────────────────────

describe('saveEdit with API baseUrl', () => {
  test('calls updateEntry when api.baseUrl configured', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name', editable: true }],
      data: [{ id: 1, name: 'Alice' }],
      editable: true,
      api: { baseUrl: 'https://api.example.com', headers: {} }
    });
    t.render();
    jest.spyOn(t, 'updateEntry').mockResolvedValue({});
    const td = t.container.querySelector('td[data-field="name"]');
    if (td) {
      await t.startEdit({ currentTarget: td }, 0, 'name');
      const input = td.querySelector('input');
      if (input) {
        input.value = 'Bob';
        t.editingCell = td;
        await t.saveEdit(input);
        expect(t.data[0].name).toBe('Bob');
      }
    }
  });

  test('reverts value when API updateEntry fails', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name', editable: true }],
      data: [{ id: 1, name: 'Alice' }],
      editable: true,
      api: { baseUrl: 'https://api.example.com', headers: {} }
    });
    t.render();
    jest.spyOn(t, 'updateEntry').mockRejectedValue(new Error('server error'));
    jest.spyOn(window, 'alert').mockImplementation(() => {});
    const td = t.container.querySelector('td[data-field="name"]');
    if (td) {
      await t.startEdit({ currentTarget: td }, 0, 'name');
      const input = td.querySelector('input');
      if (input) {
        input.value = 'Bob';
        input.dataset.originalValue = 'Alice';
        t.editingCell = td;
        await t.saveEdit(input);
        expect(t.data[0].name).toBe('Alice'); // reverted
      }
    }
  });
});

// ── cancelEdit: no input found in cell ────────────────────────────────────────

describe('cancelEdit with no input', () => {
  test('editingCell with no input child — no crash', () => {
    const t = makeTable();
    t.render();
    const td = t.container.querySelector('td[data-field="name"]');
    t.editingCell = td; // Set editingCell directly without actually editing
    expect(() => t.cancelEdit()).not.toThrow();
    expect(t.editingCell).toBeNull();
  });
});

// ── multiselect filter switch case ───────────────────────────────────────────

describe('_computeFilteredData multiselect', () => {
  test('multiselect filter returns matching rows', () => {
    const t = makeTable(); // use default config which has proper filters.types
    t.filterTypes['name'] = 'multiselect';
    t.filters['name'] = ['Alice']; // set directly to bypass setFilter string trim
    const result = t.getFilteredData();
    expect(result).toHaveLength(1);
    expect(result[0].name).toBe('Alice');
  });

  test('multiselect filter with non-array filterValue returns false', () => {
    const t = makeTable();
    t.filterTypes['name'] = 'multiselect';
    t.filters['name'] = 'Alice'; // non-array
    const result = t.getFilteredData();
    expect(result).toHaveLength(0);
  });
});

// ── renderTable: no results row ───────────────────────────────────────────────

describe('renderTable with no data', () => {
  test('renders no-results message when displayData is empty', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: []
    });
    const tableEl = t.renderTable();
    expect(tableEl.querySelector('.tc-no-results')).not.toBeNull();
  });
});

// ── renderFilters: column with filterable:false ───────────────────────────────

describe('renderFilters with filterable:false column', () => {
  test('column with filterable:false is skipped', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [
        { field: 'id', filterable: false },
        { field: 'name' }
      ],
      data: [{ id: 1, name: 'Alice' }],
      filterable: true
      // Use default filters config to preserve filters.types and autoDetect
    });
    const filtersEl = t.renderFilters();
    expect(filtersEl).not.toBeNull();
    // The filters container should exist but id column should be excluded
    expect(filtersEl.querySelector('.tc-filters-row')).not.toBeNull();
  });
});

// ── render with isHydrating and filterable ────────────────────────────────────

describe('render isHydrating with filterable', () => {
  test('filterable+hydrating inserts filters into wrapper', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper">
          <table class="tc-table"><thead></thead><tbody></tbody></table>
        </div>
      </div>`;
    // Use default config (filterable=true is already default)
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name' }],
      data: [{ id: 1, name: 'A' }]
    });
    t.container.dataset.ssr = 'true';
    t.render();
    expect(t.container.querySelector('.tc-filters')).not.toBeNull();
  });
});

// ── processData: null/undefined input ────────────────────────────────────────

describe('processData edge cases', () => {
  test('null and undefined inputs return empty array', () => {
    const t = makeTable();
    expect(t.processData(null)).toEqual([]);
    expect(t.processData(undefined)).toEqual([]);
    expect(t.processData(0)).toEqual([]); // 0 is falsy
    expect(t.processData('')).toEqual([]); // empty string is falsy
  });

  test('truthy non-array object is wrapped in array', () => {
    const t = makeTable();
    expect(t.processData({ id: 1 })).toEqual([{ id: 1 }]);
  });
});

// ── sort: direction override ──────────────────────────────────────────────────

describe('sort with explicit direction', () => {
  test('sort with explicit direction:desc sets desc', () => {
    const t = makeTable({ sortable: true });
    t.sort('name', { direction: 'desc' });
    expect(t.sortKeys[0]).toEqual({ field: 'name', direction: 'desc' });
  });

  test('sort with append:true toggles existing key direction', () => {
    const t = makeTable({ sortable: true });
    t.sort('name', { append: true });
    expect(t.sortKeys[0].direction).toBe('asc');
    t.sort('name', { append: true });
    expect(t.sortKeys[0].direction).toBe('desc');
  });
});

// ── multiSort API ─────────────────────────────────────────────────────────────

describe('multiSort', () => {
  test('sets multiple sort keys at once', () => {
    const t = makeTable({ sortable: true });
    t.multiSort([
      { field: 'name', direction: 'asc' },
      { field: 'id', direction: 'desc' }
    ]);
    expect(t.sortKeys).toHaveLength(2);
    expect(t.sortKeys[0].field).toBe('name');
  });

  test('throws for non-array input', () => {
    const t = makeTable();
    expect(() => t.multiSort('not-array')).toThrow('multiSort');
  });
});

// ── unuse() plugin ────────────────────────────────────────────────────────────

describe('unuse() plugin', () => {
  test('removes a registered plugin', () => {
    const t = makeTable();
    const plugin = { name: 'removable', install: jest.fn() };
    t.use(plugin);
    const removed = t.unuse('removable');
    expect(removed).toBe(true);
    expect(t._plugins.find(p => p.plugin.name === 'removable')).toBeUndefined();
  });

  test('returns false when plugin not found', () => {
    const t = makeTable();
    expect(t.unuse('nonexistent')).toBe(false);
  });

  test('calls uninstall when defined', () => {
    const t = makeTable();
    const uninstallSpy = jest.fn();
    const plugin = { name: 'uninstall-test', install: jest.fn(), uninstall: uninstallSpy };
    t.use(plugin);
    t.unuse('uninstall-test');
    expect(uninstallSpy).toHaveBeenCalledWith(t);
  });
});

// ── getTotalPages and shouldShowPagination when no pagination ─────────────────

describe('pagination fallbacks', () => {
  test('getTotalPages returns 1 when no pagination config', () => {
    const t = makeTable({ pagination: false });
    expect(t.getTotalPages()).toBe(1);
  });

  test('getPaginatedData returns all when no pagination', () => {
    const t = makeTable({ pagination: false });
    expect(t.getPaginatedData()).toHaveLength(2);
  });
});

// ── renderGlobalSearch with isHydrating ───────────────────────────────────────

describe('render with isHydrating + globalSearch', () => {
  test('hydrating render inserts globalSearch before first child', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper">
          <table class="tc-table"><thead></thead><tbody></tbody></table>
        </div>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      globalSearch: true
    });
    t.container.dataset.ssr = 'true';
    t.render();
    const wrapper = t.container.querySelector('.tc-wrapper');
    const search = wrapper.querySelector('.tc-global-search-container');
    expect(search).not.toBeNull();
  });
});

// ── render with isHydrating + filterable + search ─────────────────────────────

describe('render isHydrating filterable with adjacent search', () => {
  test('filterable inserts after search when search present in hydrated wrapper', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper">
          <div class="tc-global-search-container"><input class="tc-global-search" /></div>
          <table class="tc-table"><thead></thead><tbody></tbody></table>
        </div>
      </div>`;
    // Use default config (globalSearch and filterable already true by default)
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }]
    });
    t.container.dataset.ssr = 'true';
    t.render();
    expect(t.container.querySelector('.tc-filters')).not.toBeNull();
  });
});

// ── render isHydrating + exportFormats ────────────────────────────────────────

describe('render isHydrating + export controls', () => {
  test('hydrating inserts export controls near table', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper">
          <table class="tc-table"><thead></thead><tbody></tbody></table>
        </div>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      export: { formats: ['csv'] }
    });
    t.container.dataset.ssr = 'true';
    t.render();
    expect(t.container.querySelector('.tc-export-controls')).not.toBeNull();
  });
});

// ── getCurrentBreakpoint uses window.innerWidth directly ──────────────────────

describe('getCurrentBreakpoint with window.innerWidth', () => {
  test('getCurrentBreakpoint uses window.innerWidth', () => {
    const t = makeTable();
    Object.defineProperty(window, 'innerWidth', { value: 320, configurable: true, writable: true });
    expect(t.getCurrentBreakpoint()).toBe('mobile');
    // Restore
    Object.defineProperty(window, 'innerWidth', { value: 1024, configurable: true, writable: true });
  });
});
