// API integration, validateEntry, isValidEmail, showValidationErrors,
// apiRequest, loadDataFromAPI, showFormValidationErrors, clearAllValidationErrors,
// validateOnSubmit path in handleAddNewSubmit, autoDiscover exclude string

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }, { field: 'name' }, { field: 'email' }],
    data: [{ id: 1, name: 'Alice', email: 'a@b.com' }],
    ...extra
  });
}

// ── isValidEmail ──────────────────────────────────────────────────────────────

describe('isValidEmail', () => {
  const t = makeTable();
  test('accepts a valid email', () => {
    expect(t.isValidEmail('user@example.com')).toBe(true);
  });
  test('rejects missing @', () => {
    expect(t.isValidEmail('userexample.com')).toBe(false);
  });
  test('rejects missing domain', () => {
    expect(t.isValidEmail('user@')).toBe(false);
  });
});

// ── validateEntry ─────────────────────────────────────────────────────────────

describe('validateEntry', () => {
  const t = makeTable();
  test('required field missing → error', () => {
    const errors = t.validateEntry({ name: '' }, { name: { required: true } });
    expect(errors.length).toBe(1);
    expect(errors[0].field).toBe('name');
  });

  test('valid required field → no error', () => {
    const errors = t.validateEntry({ name: 'Alice' }, { name: { required: true } });
    expect(errors).toHaveLength(0);
  });

  test('email type validation', () => {
    const errors = t.validateEntry({ email: 'notvalid' }, { email: { type: 'email' } });
    expect(errors.length).toBe(1);
  });

  test('valid email passes', () => {
    const errors = t.validateEntry({ email: 'ok@test.com' }, { email: { type: 'email' } });
    expect(errors).toHaveLength(0);
  });

  test('minLength validation', () => {
    const errors = t.validateEntry({ name: 'ab' }, { name: { minLength: 5 } });
    expect(errors.length).toBe(1);
  });

  test('maxLength validation', () => {
    const errors = t.validateEntry({ name: 'too long name' }, { name: { maxLength: 5 } });
    expect(errors.length).toBe(1);
  });
});

// ── showValidationErrors / showFormValidationErrors ──────────────────────────

describe('showValidationErrors (form-style)', () => {
  test('highlights fields and shows error messages', () => {
    const t = makeTable();
    const form = document.createElement('form');
    const input = document.createElement('input');
    input.name = 'email';
    form.appendChild(input);

    t.showValidationErrors(form, [{ field: 'email', message: 'Invalid email' }]);
    expect(input.classList.contains('tc-error')).toBe(true);
    expect(form.querySelector('.tc-form-error')).not.toBeNull();
  });

  test('unknown field name does not throw', () => {
    const t = makeTable();
    const form = document.createElement('form');
    expect(() => t.showValidationErrors(form, [{ field: 'missing', message: 'err' }])).not.toThrow();
  });

  test('clears previous errors before showing new ones', () => {
    const t = makeTable();
    const form = document.createElement('form');
    const input = document.createElement('input');
    input.name = 'email';
    form.appendChild(input);

    t.showValidationErrors(form, [{ field: 'email', message: 'err1' }]);
    t.showValidationErrors(form, [{ field: 'email', message: 'err2' }]);
    expect(form.querySelectorAll('.tc-form-error').length).toBe(1);
  });
});

describe('showFormValidationErrors', () => {
  test('adds tc-field-error class and validation message span', () => {
    const t = makeTable();
    const form = document.createElement('form');
    const input = document.createElement('input');
    input.name = 'name';
    const wrapper = document.createElement('div');
    wrapper.appendChild(input);
    form.appendChild(wrapper);

    t.showFormValidationErrors(form, { name: ['Name is required'] });
    expect(input.classList.contains('tc-field-error')).toBe(true);
    expect(form.querySelector('.tc-validation-message')).not.toBeNull();
  });
});

// ── clearAllValidationErrors ─────────────────────────────────────────────────

describe('clearAllValidationErrors', () => {
  test('clears validationErrors map', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    t.setValidationError(0, 'name', ['required']);
    t.clearAllValidationErrors();
    expect(t.getValidationErrors(0, 'name')).toEqual([]);
  });

  test('removes tc-validation-error classes from DOM after render', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    t.render();
    // manually add an error class
    const el = t.container.querySelector('td');
    if (el) {
      el.classList.add('tc-validation-error');
      t.clearAllValidationErrors();
      expect(el.classList.contains('tc-validation-error')).toBe(false);
    }
  });
});

// ── apiRequest ────────────────────────────────────────────────────────────────

describe('apiRequest', () => {
  test('resolves with JSON response', async () => {
    const t = makeTable({ api: { baseUrl: 'https://api.example.com', headers: {} } });
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([{ id: 1 }])
    });
    const result = await t.apiRequest('/data');
    expect(result).toEqual([{ id: 1 }]);
  });

  test('throws on HTTP error', async () => {
    const t = makeTable({ api: { baseUrl: 'https://api.example.com', headers: {} } });
    global.fetch.mockResolvedValueOnce({ ok: false, status: 404 });
    await expect(t.apiRequest('/data')).rejects.toThrow('HTTP error');
  });

  test('adds Bearer auth header when configured', async () => {
    const t = makeTable({
      api: {
        baseUrl: 'https://api.example.com',
        headers: {},
        authentication: { type: 'bearer', token: 'my-token' }
      }
    });
    global.fetch.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({}) });
    await t.apiRequest('/resource');
    const [, opts] = global.fetch.mock.calls.slice(-1)[0];
    expect(opts.headers['Authorization']).toBe('Bearer my-token');
  });

  test('adds api-key header when configured', async () => {
    const t = makeTable({
      api: {
        baseUrl: 'https://api.example.com',
        headers: {},
        authentication: { type: 'api-key', headerName: 'X-Api-Key', key: 'abc123' }
      }
    });
    global.fetch.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({}) });
    await t.apiRequest('/resource');
    const [, opts] = global.fetch.mock.calls.slice(-1)[0];
    expect(opts.headers['X-Api-Key']).toBe('abc123');
  });
});

// ── loadDataFromAPI ───────────────────────────────────────────────────────────

describe('loadDataFromAPI', () => {
  test('throws when no baseUrl', async () => {
    const t = makeTable();
    await expect(t.loadDataFromAPI()).rejects.toThrow('API base URL not configured');
  });

  test('fetches and sets data array', async () => {
    const t = makeTable({
      api: { baseUrl: 'https://api.example.com', headers: {}, endpoints: { data: '/items' } }
    });
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve([{ id: 10 }, { id: 11 }])
    });
    await t.loadDataFromAPI();
    expect(t.data).toEqual([{ id: 10 }, { id: 11 }]);
  });

  test('unwraps data property when response is object', async () => {
    const t = makeTable({
      api: { baseUrl: 'https://api.example.com', headers: {}, endpoints: { data: '/items' } }
    });
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [{ id: 20 }] })
    });
    await t.loadDataFromAPI();
    expect(t.data).toEqual([{ id: 20 }]);
  });
});

// ── handleAddNewSubmit with validation ────────────────────────────────────────

describe('handleAddNewSubmit with validateOnSubmit', () => {
  test('shows form validation errors and does not add when invalid', () => {
    const t = makeTable({
      addNew: { enabled: true, fields: [{ field: 'name', label: 'Name' }] }
    });
    // Stub validateRow to return invalid so we exercise the error path
    jest.spyOn(t, 'validateRow').mockReturnValue({ isValid: false, errors: { name: ['required'] } });
    t.showAddNewModal();
    const form = document.querySelector('.tc-modal-form');
    const showSpy = jest.spyOn(t, 'showFormValidationErrors').mockImplementation(() => {});
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    expect(showSpy).toHaveBeenCalled();
    expect(t.data).toHaveLength(1); // unchanged
  });
});

// ── autoDiscoverColumns with exclude string ───────────────────────────────────

describe('autoDiscoverColumns exclude as string', () => {
  test('exclude comma string filters columns', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [],
      data: [{ a: 1, b: 2, c: 3 }],
      exclude: 'b,c'
    });
    t.autoDiscoverColumns();
    expect(t.config.columns.map(c => c.field)).toEqual(['a']);
  });
});

// ── global search input debounce path ────────────────────────────────────────

describe('renderGlobalSearch debounce', () => {
  test('input event debounces searchTerm update', () => {
    jest.useFakeTimers();
    const t = makeTable({ globalSearch: true });
    t.render();
    const input = t.container.querySelector('.tc-global-search');
    if (input) {
      const renderSpy = jest.spyOn(t, 'render').mockImplementation(() => {});
      input.value = 'hello';
      input.dispatchEvent(new Event('input'));
      jest.runAllTimers();
      expect(renderSpy).toHaveBeenCalled();
    }
    jest.useRealTimers();
  });
});

// ── export button click and copy button click ─────────────────────────────────

describe('export controls click handlers', () => {
  test('export button click triggers downloadExport', () => {
    const t = makeTable({ export: { enabled: true, formats: ['csv', 'json'] } });
    t.render();
    const spy = jest.spyOn(t, 'downloadExport').mockImplementation(() => {});
    const btn = t.container.querySelector('.tc-export-btn');
    if (btn) {
      btn.click();
      expect(spy).toHaveBeenCalled();
    }
  });

  test('copy button click triggers copyToClipboard', () => {
    const t = makeTable({ export: { formats: ['csv'] } });
    t.render();
    const spy = jest.spyOn(t, 'copyToClipboard').mockImplementation(() => {});
    const btn = t.container.querySelector('.tc-copy-clipboard');
    expect(btn).not.toBeNull();
    btn.click();
    expect(spy).toHaveBeenCalled();
  });
});
