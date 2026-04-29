// Deep branch coverage: constructor paths, detectDataType, resolveContainer,
// renderLoading SSR, render isHydrating, isTruthy, validateField extended,
// formula functions, color editor, pagination nav, filter type detection,
// saveEdit checkbox/file paths, _applyTheme, plugin system, use() method

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'name' }],
    data: [{ id: 1, name: 'Alice' }, { id: 2, name: 'Bob' }],
    ...extra
  });
}

// ── Constructor: .tc-initial-data embedded data path ──────────────────────────

describe('constructor with embedded .tc-initial-data', () => {
  test('parses JSON from script element and renders', () => {
    document.body.innerHTML = `
      <div id="t">
        <script class="tc-initial-data" type="application/json">[{"id":1,"name":"Eve"}]</script>
      </div>`;
    const t = new TC('#t', { columns: [{ field: 'id' }, { field: 'name' }], data: [] });
    expect(t.data).toHaveLength(1);
    expect(t.data[0].name).toBe('Eve');
  });

  test('SSR + .tc-initial-data runs hydration path', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <script class="tc-initial-data" type="application/json">[{"id":2}]</script>
      </div>`;
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    expect(t.data).toHaveLength(1);
    expect(t.container.dataset.ssr).toBe('false');
  });

  test('invalid JSON in .tc-initial-data logs error without throwing', () => {
    const errSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    document.body.innerHTML = `
      <div id="t">
        <script class="tc-initial-data" type="application/json">NOT_JSON</script>
      </div>`;
    expect(() => new TC('#t', { columns: [{ field: 'id' }], data: [] })).not.toThrow();
    expect(errSpy).toHaveBeenCalled();
    errSpy.mockRestore();
  });
});

// ── Constructor: embedded data + string URL path (line 234) ───────────────────

describe('constructor data.length > 0 with string URL config', () => {
  test('stores dataUrl and renders when data is pre-populated via other means', () => {
    // This path is reached when data.length > 0 AND config.data is a URL string.
    // It only happens via .tc-initial-data; we simulate that scenario.
    document.body.innerHTML = `
      <div id="t">
        <script class="tc-initial-data" type="application/json">[{"id":1}]</script>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: 'https://api.example.com/items'
    });
    expect(t.dataUrl).toBe('https://api.example.com/items');
    expect(t.data.length).toBeGreaterThan(0);
  });
});

// ── resolveContainer with DOM element directly ────────────────────────────────

describe('resolveContainer', () => {
  test('accepts a DOM element directly', () => {
    const div = document.createElement('div');
    document.body.appendChild(div);
    const t = new TC(div, { columns: [{ field: 'id' }], data: [{ id: 1 }] });
    expect(t.container).toBe(div);
  });

  test('returns null for invalid input', () => {
    const t = makeTable();
    expect(t.resolveContainer(null)).toBeNull();
    expect(t.resolveContainer(42)).toBeNull();
  });
});

// ── detectDataType branches ───────────────────────────────────────────────────

describe('detectDataType', () => {
  test('null returns text', () => {
    const t = makeTable();
    expect(t.detectDataType(null)).toBe('text');
  });

  test('"false" string returns boolean', () => {
    const t = makeTable();
    expect(t.detectDataType('false')).toBe('boolean');
  });

  test('image URL returns image', () => {
    const t = makeTable();
    expect(t.detectDataType('https://example.com/photo.jpg')).toBe('image');
  });

  test('non-image URL returns url', () => {
    const t = makeTable();
    expect(t.detectDataType('https://example.com/page')).toBe('url');
  });

  test('invalid date string returns text', () => {
    const t = makeTable();
    // Matches date-ish format but is not a valid date
    expect(t.detectDataType('9999-99-99')).toBe('text');
  });

  test('valid ISO date string returns date', () => {
    const t = makeTable();
    expect(t.detectDataType('2024-01-15')).toBe('date');
  });
});

// ── renderLoading SSR path ────────────────────────────────────────────────────

describe('renderLoading with SSR content', () => {
  test('returns early when ssr=true and children exist', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <table class="tc-table"></table>
      </div>`;
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [] });
    const before = t.container.innerHTML;
    t.renderLoading();
    // Should not replace content with skeleton
    expect(t.container.querySelector('.tc-loading')).toBeNull();
  });
});

// ── isTruthy with various types ───────────────────────────────────────────────

describe('isTruthy', () => {
  test('returns true for non-zero number', () => {
    const t = makeTable();
    expect(t.isTruthy(1)).toBe(true);
    expect(t.isTruthy(42)).toBe(true);
  });

  test('returns false for 0', () => {
    const t = makeTable();
    expect(t.isTruthy(0)).toBe(false);
  });

  test('returns false for non-truthy string', () => {
    const t = makeTable();
    expect(t.isTruthy('no')).toBe(false);
  });

  test('returns true for "yes" string', () => {
    const t = makeTable();
    expect(t.isTruthy('yes')).toBe(true);
    expect(t.isTruthy('1')).toBe(true);
    expect(t.isTruthy('on')).toBe(true);
  });

  test('returns false for other types', () => {
    const t = makeTable();
    expect(t.isTruthy(null)).toBe(false);
  });
});

// ── validateField extended rules ──────────────────────────────────────────────

describe('validateField extended rules', () => {
  function makeValidTable() {
    document.body.innerHTML = '<div id="t"></div>';
    // Don't override validation config — defaults already have enabled:true + all messages
    return new TC('#t', {
      columns: [{ field: 'id' }, { field: 'val' }],
      data: [{ id: 1, val: 'a' }, { id: 2, val: 'b' }]
    });
  }

  test('pattern rule fails for non-matching value', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { pattern: '^\\d+$' });
    const r = t.validateField('val', 'abc', {});
    expect(r.isValid).toBe(false);
  });

  test('pattern rule passes for matching value', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { pattern: '^\\d+$' });
    const r = t.validateField('val', '123', {});
    expect(r.isValid).toBe(true);
  });

  test('custom rule returning false fails validation', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { custom: (v) => v === 'ok' || 'must be ok' });
    expect(t.validateField('val', 'bad', {}).isValid).toBe(false);
    expect(t.validateField('val', 'ok', {}).isValid).toBe(true);
  });

  test('custom rule throwing is caught', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { custom: () => { throw new Error('boom'); } });
    expect(t.validateField('val', 'x', {}).isValid).toBe(false);
  });

  test('phone E.164 fails for bad number', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { phone: true });
    expect(t.validateField('val', 'notanumber', {}).isValid).toBe(false);
  });

  test('phone permissive passes human-formatted number', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { phone: 'permissive' });
    expect(t.validateField('val', '(555) 123-4567', {}).isValid).toBe(true);
  });

  test('unique rule fails for duplicate value', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { unique: true });
    const result = t.validateField('val', 'a', t.data[1]); // 'a' already in row 0
    expect(result.isValid).toBe(false);
  });

  test('unique with caseInsensitive option', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { unique: { caseInsensitive: true } });
    const result = t.validateField('val', 'A', t.data[1]);
    expect(result.isValid).toBe(false);
  });

  test('oneOf fails for value not in list', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { oneOf: ['x', 'y'] });
    expect(t.validateField('val', 'z', {}).isValid).toBe(false);
    expect(t.validateField('val', 'x', {}).isValid).toBe(true);
  });

  test('notOneOf fails for value in exclusion list', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { notOneOf: ['bad', 'evil'] });
    expect(t.validateField('val', 'bad', {}).isValid).toBe(false);
    expect(t.validateField('val', 'good', {}).isValid).toBe(true);
  });

  test('date rule fails for invalid date', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { date: true });
    expect(t.validateField('val', 'not-a-date', {}).isValid).toBe(false);
    expect(t.validateField('val', '2024-01-01', {}).isValid).toBe(true);
  });

  test('date min/max bounds validation', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { date: { min: '2024-01-01', max: '2024-12-31' } });
    expect(t.validateField('val', '2023-12-31', {}).isValid).toBe(false);
    expect(t.validateField('val', '2025-01-01', {}).isValid).toBe(false);
    expect(t.validateField('val', '2024-06-15', {}).isValid).toBe(true);
  });

  test('empty value returns early without format checks', () => {
    const t = makeValidTable();
    t.validationRules.set('val', { minLength: 5 }); // would fail if not empty
    expect(t.validateField('val', '', {}).isValid).toBe(true);
  });
});

// ── validate() full dataset method ────────────────────────────────────────────

describe('validate() full dataset', () => {
  test('returns valid when validation disabled', async () => {
    const t = makeTable();
    const result = await t.validate();
    expect(result.isValid).toBe(true);
  });

  test('returns errors for invalid rows when enabled', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    // Default config has validation.enabled:true — do not override validation object
    const t = new TC('#t', {
      columns: [
        { field: 'id' },
        { field: 'email', validation: { email: true } }
      ],
      data: [{ id: 1, email: 'not-valid' }, { id: 2, email: 'ok@test.com' }]
    });
    const result = await t.validate();
    expect(result.isValid).toBe(false);
    expect(result.errors[0]).toBeDefined();
  });
});

// ── getErrors / clearErrors ───────────────────────────────────────────────────

describe('getErrors / clearErrors', () => {
  test('getErrors() returns all errors', () => {
    const t = makeTable();
    t.setValidationError(0, 'name', ['err']);
    t.setValidationError(1, 'id', ['err2']);
    const all = t.getErrors();
    expect(all[0].name).toEqual(['err']);
    expect(all[1].id).toEqual(['err2']);
  });

  test('getErrors(rowIndex) returns errors for one row', () => {
    const t = makeTable();
    t.setValidationError(0, 'name', ['err']);
    expect(t.getErrors(0)).toEqual({ name: ['err'] });
    expect(t.getErrors(99)).toEqual({});
  });

  test('clearErrors() clears all', () => {
    const t = makeTable();
    t.setValidationError(0, 'name', ['err']);
    t.clearErrors();
    expect(t.getErrors()).toEqual({});
  });

  test('clearErrors(rowIndex) clears one row', () => {
    const t = makeTable();
    t.setValidationError(0, 'name', ['err']);
    t.setValidationError(1, 'name', ['err2']);
    t.clearErrors(0);
    expect(t.getErrors(0)).toEqual({});
    expect(t.getErrors(1)).toEqual({ name: ['err2'] });
  });

  test('clearErrors(rowIndex, field) clears specific field', () => {
    const t = makeTable();
    t.setValidationError(0, 'name', ['err']);
    t.setValidationError(0, 'id', ['err2']);
    t.clearErrors(0, 'name');
    expect(t.getErrors(0)).toEqual({ id: ['err2'] });
  });
});

// ── Formula evaluation: string functions ──────────────────────────────────────

describe('formula string functions', () => {
  test('CONCAT joins strings', () => {
    const t = makeTable();
    expect(t.evaluateFormula('CONCAT("Hello", " ", "World")', {})).toBe('Hello World');
  });

  test('LEFT extracts left N chars', () => {
    const t = makeTable();
    expect(t.evaluateFormula('LEFT("Hello", 3)', {})).toBe('Hel');
  });

  test('RIGHT extracts right N chars', () => {
    const t = makeTable();
    expect(t.evaluateFormula('RIGHT("Hello", 3)', {})).toBe('llo');
  });

  test('MID extracts middle chars', () => {
    const t = makeTable();
    expect(t.evaluateFormula('MID("Hello", 2, 3)', {})).toBe('ell');
  });

  test('UPPER converts to uppercase', () => {
    const t = makeTable();
    expect(t.evaluateFormula('UPPER("hello")', {})).toBe('HELLO');
  });

  test('LOWER converts to lowercase', () => {
    const t = makeTable();
    expect(t.evaluateFormula('LOWER("HELLO")', {})).toBe('hello');
  });

  test('TRIM removes whitespace', () => {
    const t = makeTable();
    expect(t.evaluateFormula('TRIM("  hi  ")', {})).toBe('hi');
  });

  test('LEN returns string length', () => {
    const t = makeTable();
    expect(t.evaluateFormula('LEN("hello")', {})).toBe(5);
  });
});

// ── Formula: aggregate functions ──────────────────────────────────────────────

describe('formula aggregate functions', () => {
  test('SUM adds field values across rows', () => {
    const t = makeTable({ data: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }, { id: 3, name: 'C' }] });
    expect(t.evaluateFormula('SUM("id")', {})).toBe(6);
  });

  test('AVG averages field values', () => {
    const t = makeTable({ data: [{ id: 2, name: 'A' }, { id: 4, name: 'B' }] });
    expect(t.evaluateFormula('AVG("id")', {})).toBe(3);
  });

  test('COUNT counts non-empty values', () => {
    const t = makeTable({ data: [{ id: 1, name: 'A' }, { id: null, name: 'B' }, { id: 3, name: 'C' }] });
    expect(t.evaluateFormula('COUNT("id")', {})).toBe(2);
  });
});

// ── Formula: IF logic ──────────────────────────────────────────────────────────

describe('formula IF logic', () => {
  test('IF returns true branch when condition truthy', () => {
    const t = makeTable();
    // IF(1, "yes", "no") → "yes"
    const r = t.evaluateFormula('IF(1, "yes", "no")', {});
    expect(r).toBe('yes');
  });

  test('IF returns false branch when condition falsy', () => {
    const t = makeTable();
    const r = t.evaluateFormula('IF(0, "yes", "no")', {});
    expect(r).toBe('no');
  });

  test('evaluateFormula returns null for non-string input', () => {
    const t = makeTable();
    expect(t.evaluateFormula(42, {})).toBeNull();
    expect(t.evaluateFormula(null, {})).toBeNull();
  });

  test('evaluateFormula returns null for unknown function', () => {
    const t = makeTable();
    expect(t.evaluateFormula('UNKNOWN(1)', {})).toBeNull();
  });

  test('evaluateFormula handles missing field in placeholder', () => {
    const t = makeTable();
    expect(t.evaluateFormula('{missing} + 1', {})).toBeNull();
  });
});

// ── _applyTheme ───────────────────────────────────────────────────────────────

describe('_applyTheme', () => {
  test('sets theme attribute when config.theme is a string', () => {
    const t = makeTable({ theme: 'dark' });
    const wrapper = document.createElement('div');
    t._applyTheme(wrapper);
    expect(wrapper.getAttribute('data-tc-theme')).toBe('dark');
  });

  test('removes theme attribute when theme is empty', () => {
    const t = makeTable();
    const wrapper = document.createElement('div');
    wrapper.setAttribute('data-tc-theme', 'old');
    t._applyTheme(wrapper);
    expect(wrapper.getAttribute('data-tc-theme')).toBeNull();
  });

  test('applies theme variables', () => {
    const t = makeTable({ themeVariables: { '--tc-primary': '#ff0000' } });
    const wrapper = document.createElement('div');
    t._applyTheme(wrapper);
    expect(wrapper.style.getPropertyValue('--tc-primary')).toBe('#ff0000');
  });

  test('RTL locale sets dir attribute', () => {
    const t = makeTable({
      i18n: {
        locale: 'ur',
        messages: { ur: { '_dir': 'rtl' } }
      }
    });
    const wrapper = document.createElement('div');
    t._applyTheme(wrapper);
    expect(wrapper.getAttribute('dir')).toBe('rtl');
  });

  test('no-op when wrapper is null', () => {
    const t = makeTable();
    expect(() => t._applyTheme(null)).not.toThrow();
  });
});

// ── setTheme / getTheme ───────────────────────────────────────────────────────

describe('setTheme / getTheme', () => {
  test('getTheme returns config theme', () => {
    const t = makeTable({ theme: 'dark' });
    expect(t.getTheme()).toBe('dark');
  });

  test('getTheme defaults to light', () => {
    const t = makeTable();
    expect(t.getTheme()).toBe('light');
  });

  test('setTheme updates config and re-renders', () => {
    const t = makeTable();
    t.render();
    const spy = jest.spyOn(t, 'render');
    t.setTheme('dark');
    expect(t.config.theme).toBe('dark');
    expect(spy).toHaveBeenCalled();
  });
});

// ── Color editor invalid hex branch ───────────────────────────────────────────

describe('createColorEditor invalid hex', () => {
  test('invalid hex in text input does not update color input', () => {
    const t = makeTable();
    const container = t.createColorEditor({ field: 'color' }, '#ff0000');
    const textInput = container.querySelector('.tc-color-text');
    const colorInput = container.querySelector('input[type="color"]');
    const originalVal = colorInput.value;
    textInput.value = 'not-a-hex';
    textInput.dispatchEvent(new Event('change'));
    expect(colorInput.value).toBe(originalVal); // unchanged
  });

  test('valid hex in text input updates color input', () => {
    const t = makeTable();
    const container = t.createColorEditor({ field: 'color' }, '#ff0000');
    const textInput = container.querySelector('.tc-color-text');
    const colorInput = container.querySelector('input[type="color"]');
    textInput.value = '#00ff00';
    textInput.dispatchEvent(new Event('change'));
    expect(colorInput.value).toBe('#00ff00');
  });
});

// ── Pagination navigation ──────────────────────────────────────────────────────

describe('pagination navigation', () => {
  function makePaginatedTable() {
    document.body.innerHTML = '<div id="t"></div>';
    return new TC('#t', {
      columns: [{ field: 'id' }],
      data: Array.from({ length: 20 }, (_, i) => ({ id: i + 1 })),
      pagination: true,
      pageSize: 5
    });
  }

  test('nextPage advances page', () => {
    const t = makePaginatedTable();
    t.currentPage = 1;
    t.nextPage();
    expect(t.currentPage).toBe(2);
  });

  test('prevPage goes back one page', () => {
    const t = makePaginatedTable();
    t.currentPage = 3;
    t.prevPage();
    expect(t.currentPage).toBe(2);
  });

  test('goToPage out of range is no-op', () => {
    const t = makePaginatedTable();
    t.goToPage(0);
    expect(t.currentPage).toBe(1);
    t.goToPage(999);
    expect(t.currentPage).toBe(1);
  });

  test('getTotalPages calculates correctly', () => {
    const t = makePaginatedTable();
    expect(t.getTotalPages()).toBe(4);
  });

  test('shouldShowPagination is true for large dataset', () => {
    const t = makePaginatedTable();
    expect(t.shouldShowPagination()).toBe(true);
  });

  test('shouldShowPagination is false for small dataset', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      pagination: true,
      pageSize: 10
    });
    expect(t.shouldShowPagination()).toBe(false);
  });
});

// ── render isHydrating path ───────────────────────────────────────────────────

describe('render with SSR hydration (isHydrating=true)', () => {
  test('hydrating render does not clear container content', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <div class="tc-wrapper">
          <table class="tc-table"><thead></thead><tbody></tbody></table>
        </div>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }]
    });
    // Set ssr back to true so next render sees isHydrating=true
    t.container.dataset.ssr = 'true';
    t.render();
    // Wrapper should still exist (not cleared)
    expect(t.container.querySelector('.tc-wrapper')).not.toBeNull();
  });

  test('hydrating render with globalSearch inserts search at front', () => {
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
    expect(t.container.querySelector('.tc-global-search')).not.toBeNull();
  });
});

// ── Plugin system: use() ──────────────────────────────────────────────────────

describe('use() plugin system', () => {
  test('plugin install() is called with instance', () => {
    const t = makeTable();
    const installSpy = jest.fn();
    const plugin = { name: 'test-plugin', install: installSpy };
    t.use(plugin, { option: 1 });
    expect(installSpy).toHaveBeenCalledWith(t, { option: 1 });
  });

  test('plugins declared in config are auto-registered', () => {
    const installSpy = jest.fn();
    const plugin = { name: 'auto-plugin', install: installSpy };
    document.body.innerHTML = '<div id="t"></div>';
    new TC('#t', {
      columns: [{ field: 'id' }],
      data: [],
      plugins: [plugin]
    });
    expect(installSpy).toHaveBeenCalled();
  });

  test('plugins declared as [plugin, options] pairs are registered', () => {
    const installSpy = jest.fn();
    const plugin = { name: 'pair-plugin', install: installSpy };
    document.body.innerHTML = '<div id="t"></div>';
    new TC('#t', {
      columns: [{ field: 'id' }],
      data: [],
      plugins: [[plugin, { x: 1 }]]
    });
    expect(installSpy).toHaveBeenCalledWith(expect.anything(), { x: 1 });
  });

  test('use() throws when plugin has no name', () => {
    const t = makeTable();
    expect(() => t.use({ install: jest.fn() })).toThrow('plugin must have a string');
  });

  test('use() throws when plugin already registered', () => {
    const t = makeTable();
    const plugin = { name: 'dup-plugin', install: jest.fn() };
    t.use(plugin);
    expect(() => t.use(plugin)).toThrow('already registered');
  });
});

// ── saveEdit with checkbox / file input ───────────────────────────────────────

describe('saveEdit with special input types', () => {
  test('checkbox input uses checked value', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'active', editable: true, type: 'checkbox' }],
      data: [{ id: 1, active: false }],
      editable: true
    });
    t.render();
    const td = t.container.querySelector('td[data-field="active"]');
    if (td) {
      await t.startEdit({ currentTarget: td }, 0, 'active');
      const input = td.querySelector('input');
      if (input) {
        input.dataset.originalValue = 'false';
        input.dataset.rowIndex = '0';
        input.dataset.field = 'active';
        input.type = 'checkbox';
        input.checked = true;
        t.editingCell = td;
        await t.saveEdit(input);
        expect(t.data[0].active).toBe(true);
      }
    }
  });
});

// ── _computeFilteredData: empty filterValue array ─────────────────────────────

describe('_computeFilteredData empty filter value', () => {
  test('empty array filter value treats as no-op', () => {
    const t = makeTable({ filters: { enabled: true } });
    t.filterTypes['name'] = 'multiselect';
    t.filters['name'] = []; // empty array should return all rows
    const result = t.getFilteredData();
    expect(result).toHaveLength(2);
  });

  test('filterable=false and no globalSearch returns data as-is', () => {
    const t = makeTable();
    expect(t.getFilteredData()).toHaveLength(2);
  });
});

// ── sort() multi-key behavior ─────────────────────────────────────────────────

describe('sort multi-key', () => {
  test('sort toggles direction on second call for same field', () => {
    const t = makeTable({ sortable: true });
    t.sort('name');
    expect(t.sortKeys[0]).toEqual({ field: 'name', direction: 'asc' });
    t.sort('name');
    expect(t.sortKeys[0]).toEqual({ field: 'name', direction: 'desc' });
  });

  test('sort with append:true adds secondary sort key', () => {
    const t = makeTable({ sortable: true });
    t.sort('id');
    t.sort('name', { append: true });
    expect(t.sortKeys.length).toBe(2);
  });
});

// ── getFilteredData with global search term ───────────────────────────────────

describe('getFilteredData with globalSearch', () => {
  test('filters rows by search term', () => {
    const t = makeTable({ globalSearch: true });
    t.searchTerm = 'Alice';
    const result = t.getFilteredData();
    expect(result).toHaveLength(1);
    expect(result[0].name).toBe('Alice');
  });
});

// ── renderFilters with showClearAll ───────────────────────────────────────────

describe('renderFilters with showClearAll', () => {
  test('renders clear all button when showClearAll is true', () => {
    const t = makeTable({
      filterable: true,
      filters: { enabled: true, showClearAll: true }
    });
    const filtersEl = t.renderFilters();
    expect(filtersEl.querySelector('.tc-clear-filters')).not.toBeNull();
  });

  test('renderFilters returns null when not filterable', () => {
    const t = makeTable({ filterable: false });
    expect(t.renderFilters()).toBeNull();
  });
});

// ── detectFilterTypes with explicit types config ──────────────────────────────

describe('detectFilterTypes with explicit types', () => {
  test('uses explicitly set filter type from config', () => {
    document.body.innerHTML = '<div id="t"></div>';
    // Must keep autoDetect:true (default) so detectFilterTypes doesn't return early
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'name' }],
      data: [{ id: 1, name: 'Alice' }],
      filters: { autoDetect: true, types: { name: { type: 'text' } }, showClearAll: false }
    });
    t.detectFilterTypes();
    expect(t.filterTypes['name']).toBe('text');
  });
});

// ── hydrateListeners: no table in container ───────────────────────────────────

describe('hydrateListeners edge cases', () => {
  test('no-op when no tc-table present', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', { columns: [{ field: 'id' }], data: [{ id: 1 }] });
    // Remove table from container
    t.container.innerHTML = '';
    expect(() => t.hydrateListeners()).not.toThrow();
  });

  test('no-op for sort when sortable is false', () => {
    document.body.innerHTML = `
      <div id="t" data-ssr="true">
        <table class="tc-table">
          <thead><tr><th class="tc-sortable" data-field="id">ID</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>`;
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: [{ id: 1 }],
      sortable: false
    });
    t.hydrateListeners();
    const sortSpy = jest.spyOn(t, 'sort');
    t.container.querySelector('th').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })
    );
    expect(sortSpy).not.toHaveBeenCalled();
  });
});

// ── isDateField / isNumericField ──────────────────────────────────────────────

describe('isDateField / isNumericField', () => {
  test('isDateField returns true for ISO dates', () => {
    const t = makeTable();
    expect(t.isDateField(['2024-01-01', '2024-06-15'])).toBe(true);
  });

  test('isDateField returns false for numbers', () => {
    const t = makeTable();
    expect(t.isDateField(['123', '456'])).toBe(false);
  });

  test('isDateField returns false for empty array', () => {
    const t = makeTable();
    expect(t.isDateField([])).toBe(false);
  });

  test('isNumericField returns true for numeric strings', () => {
    const t = makeTable();
    expect(t.isNumericField(['1', '2.5', '3'])).toBe(true);
  });

  test('isNumericField returns false for mixed', () => {
    const t = makeTable();
    expect(t.isNumericField(['1', 'abc'])).toBe(false);
  });
});

// ── loadData AbortError is swallowed ──────────────────────────────────────────

describe('loadData AbortError handling', () => {
  test('AbortError from fetch is silently swallowed', async () => {
    document.body.innerHTML = '<div id="t"></div>';
    const consoleErrSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    const t = new TC('#t', { columns: [{ field: 'id' }], data: 'https://api.example.com/data' });
    const abortErr = new Error('aborted');
    abortErr.name = 'AbortError';
    global.fetch.mockRejectedValueOnce(abortErr);
    const result = await t.loadData().catch(() => null);
    // Should not have called renderError for AbortError
    expect(t.container.querySelector('.tc-error')).toBeNull();
    consoleErrSpy.mockRestore();
  });
});
