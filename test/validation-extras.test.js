// validateRow, showValidationError, clearValidationError, setValidationError, getValidationErrors

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'name' }, { field: 'email' }],
    data: [{ name: 'Alice', email: 'a@b.com' }],
    ...extra
  });
}

// ── validateRow ──────────────────────────────────────────────────────────────

describe('validateRow', () => {
  test('returns {isValid:true} when validation is disabled', () => {
    const t = makeTable({ validation: { enabled: false } });
    const result = t.validateRow({ name: '', email: '' }, 0);
    expect(result.isValid).toBe(true);
  });

  test('returns errors for invalid fields when enabled', () => {
    const t = makeTable({
      validation: {
        enabled: true,
        rules: {
          name: { required: true, message: 'Name is required' }
        }
      }
    });
    const result = t.validateRow({ name: '', email: 'ok@ok.com' }, 0);
    expect(result.isValid).toBe(false);
    expect(result.errors['name']).toBeDefined();
  });

  test('returns isValid:true when all required fields pass', () => {
    const t = makeTable({
      validation: {
        enabled: true,
        rules: {
          name: { required: true }
        }
      }
    });
    const result = t.validateRow({ name: 'Alice', email: 'a@b.com' }, 0);
    expect(result.isValid).toBe(true);
  });
});

// ── setValidationError / getValidationErrors ─────────────────────────────────

describe('setValidationError / getValidationErrors', () => {
  test('stores and retrieves errors by rowIndex + field key', () => {
    const t = makeTable();
    t.setValidationError(0, 'email', ['Invalid email']);
    expect(t.getValidationErrors(0, 'email')).toEqual(['Invalid email']);
  });

  test('returns empty array when no errors for a key', () => {
    const t = makeTable();
    expect(t.getValidationErrors(99, 'name')).toEqual([]);
  });

  test('deletes errors when empty array passed', () => {
    const t = makeTable();
    t.setValidationError(0, 'email', ['bad']);
    t.setValidationError(0, 'email', []);
    expect(t.getValidationErrors(0, 'email')).toEqual([]);
  });

  test('deletes errors when null passed', () => {
    const t = makeTable();
    t.setValidationError(1, 'name', ['required']);
    t.setValidationError(1, 'name', null);
    expect(t.getValidationErrors(1, 'name')).toEqual([]);
  });
});

// ── showValidationError / clearValidationError ───────────────────────────────

describe('showValidationError', () => {
  test('adds tc-validation-error class to element', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    const div = document.createElement('div');
    document.body.appendChild(div);
    jest.useFakeTimers();
    t.showValidationError(div, ['Field required']);
    expect(div.classList.contains('tc-validation-error')).toBe(true);
    jest.useRealTimers();
  });

  test('appends a tooltip to document.body', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    const div = document.createElement('div');
    document.body.appendChild(div);
    jest.useFakeTimers();
    t.showValidationError(div, ['Must be valid']);
    expect(document.querySelector('.tc-validation-tooltip')).not.toBeNull();
    jest.useRealTimers();
  });

  test('no-op when showErrors is false', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: false } });
    const div = document.createElement('div');
    t.showValidationError(div, ['error']);
    expect(div.classList.contains('tc-validation-error')).toBe(false);
  });

  test('no-op when errors array is empty', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    const div = document.createElement('div');
    t.showValidationError(div, []);
    expect(div.classList.contains('tc-validation-error')).toBe(false);
  });
});

describe('clearValidationError', () => {
  test('removes tc-validation-error class and tooltip', () => {
    const t = makeTable({ validation: { enabled: true, showErrors: true } });
    const div = document.createElement('div');
    document.body.appendChild(div);
    jest.useFakeTimers();
    t.showValidationError(div, ['error']);
    t.clearValidationError(div);
    expect(div.classList.contains('tc-validation-error')).toBe(false);
    expect(document.querySelector('.tc-validation-tooltip')).toBeNull();
    jest.useRealTimers();
  });

  test('no-op when element has no tooltip', () => {
    const t = makeTable();
    const div = document.createElement('div');
    expect(() => t.clearValidationError(div)).not.toThrow();
  });
});
