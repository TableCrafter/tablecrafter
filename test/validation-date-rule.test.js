/**
 * Built-in validation rule: date with min / max bounds (slice of #41).
 *
 * Self-contained: branches off main rather than off any of the other
 * #41 slices (PRs #77 / #81). The validateField change only adds a
 * new block, so the slices merge in any order.
 */

const TableCrafter = require('../src/tablecrafter');

const baseMessages = {
  required: 'This field is required',
  email: 'Please enter a valid email address',
  date: 'Please enter a valid date',
  dateMin: 'Date must be on or after {min}',
  dateMax: 'Date must be on or before {max}',
  minLength: 'Minimum length is {min} characters',
  maxLength: 'Maximum length is {max} characters',
  min: 'Minimum value is {min}',
  max: 'Maximum value is {max}',
  pattern: 'Please enter a valid format',
  custom: 'Validation failed'
};

function makeTable(rules) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'value', label: 'Value' }],
    validation: {
      enabled: true,
      showErrors: true,
      validateOnEdit: true,
      validateOnSubmit: true,
      rules: { value: rules },
      messages: baseMessages
    }
  });
}

describe('Validation: date rule', () => {
  describe('basic parseability', () => {
    test('accepts ISO date strings (YYYY-MM-DD)', () => {
      const t = makeTable({ date: true });
      expect(t.validateField('value', '2026-04-28').isValid).toBe(true);
      expect(t.validateField('value', '2024-02-29').isValid).toBe(true); // leap year
    });

    test('accepts Date instances and ISO datetimes', () => {
      const t = makeTable({ date: true });
      expect(t.validateField('value', new Date()).isValid).toBe(true);
      expect(t.validateField('value', '2026-04-28T12:00:00Z').isValid).toBe(true);
    });

    test('rejects unparseable dates', () => {
      const t = makeTable({ date: true });
      const r = t.validateField('value', 'not a date');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/date/i);
    });
  });

  describe('min bound', () => {
    test('rejects dates earlier than min', () => {
      const t = makeTable({ date: { min: '2026-01-01' } });
      const r = t.validateField('value', '2025-12-31');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/2026-01-01|after/i);
    });

    test('accepts dates equal to min', () => {
      const t = makeTable({ date: { min: '2026-01-01' } });
      expect(t.validateField('value', '2026-01-01').isValid).toBe(true);
    });

    test('accepts dates after min', () => {
      const t = makeTable({ date: { min: '2026-01-01' } });
      expect(t.validateField('value', '2026-02-01').isValid).toBe(true);
    });
  });

  describe('max bound', () => {
    test('rejects dates after max', () => {
      const t = makeTable({ date: { max: '2026-12-31' } });
      const r = t.validateField('value', '2027-01-01');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/2026-12-31|before/i);
    });

    test('accepts dates equal to max', () => {
      const t = makeTable({ date: { max: '2026-12-31' } });
      expect(t.validateField('value', '2026-12-31').isValid).toBe(true);
    });
  });

  describe('combined min + max', () => {
    test('only dates inside the inclusive range pass', () => {
      const t = makeTable({ date: { min: '2026-01-01', max: '2026-12-31' } });
      expect(t.validateField('value', '2025-12-31').isValid).toBe(false);
      expect(t.validateField('value', '2026-01-01').isValid).toBe(true);
      expect(t.validateField('value', '2026-06-15').isValid).toBe(true);
      expect(t.validateField('value', '2026-12-31').isValid).toBe(true);
      expect(t.validateField('value', '2027-01-01').isValid).toBe(false);
    });
  });

  describe('interplay with other rules', () => {
    test('empty + required + date → required only', () => {
      const t = makeTable({ required: true, date: true });
      const r = t.validateField('value', '');
      expect(r.errors).toHaveLength(1);
      expect(r.errors[0]).toMatch(/required/i);
    });
  });
});
