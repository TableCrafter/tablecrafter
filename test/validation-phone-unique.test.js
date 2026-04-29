/**
 * Built-in validation rules: phone / unique.
 * Slice of issue #41 (built-in rule library expansion).
 *
 * Note: this file is intentionally independent of test/validation-builtin-rules.test.js
 * (the url/oneOf/notOneOf slice on PR #77) so the two PRs can merge in either order.
 */

const TableCrafter = require('../src/tablecrafter');

const baseMessages = {
  required: 'This field is required',
  email: 'Please enter a valid email address',
  phone: 'Please enter a valid phone number',
  unique: 'Value must be unique',
  minLength: 'Minimum length is {min} characters',
  maxLength: 'Maximum length is {max} characters',
  min: 'Minimum value is {min}',
  max: 'Maximum value is {max}',
  pattern: 'Please enter a valid format',
  custom: 'Validation failed'
};

function makeTable(rules, data = []) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [{ field: 'value', label: 'Value' }],
    data,
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

describe('Validation: phone rule', () => {
  describe('default (E.164)', () => {
    test('accepts well-formed E.164 numbers', () => {
      const t = makeTable({ phone: true });
      expect(t.validateField('value', '+14155552671').isValid).toBe(true);
      expect(t.validateField('value', '+442071838750').isValid).toBe(true);
      expect(t.validateField('value', '14155552671').isValid).toBe(true);
    });

    test('rejects malformed numbers', () => {
      const t = makeTable({ phone: true });
      expect(t.validateField('value', '0123456789').isValid).toBe(false); // E.164 forbids leading 0
      expect(t.validateField('value', '+').isValid).toBe(false);
      expect(t.validateField('value', 'abc').isValid).toBe(false);
      expect(t.validateField('value', '+1234567890123456').isValid).toBe(false); // > 15 digits
    });

    test('explicit phone: "E.164" behaves the same', () => {
      const t = makeTable({ phone: 'E.164' });
      expect(t.validateField('value', '+14155552671').isValid).toBe(true);
      expect(t.validateField('value', '0123456789').isValid).toBe(false);
    });
  });

  describe('permissive', () => {
    test('accepts numbers with separators, parentheses, and country codes', () => {
      const t = makeTable({ phone: 'permissive' });
      expect(t.validateField('value', '(415) 555-2671').isValid).toBe(true);
      expect(t.validateField('value', '+1 415-555-2671').isValid).toBe(true);
      expect(t.validateField('value', '415.555.2671').isValid).toBe(true);
    });

    test('still rejects clearly non-phone strings', () => {
      const t = makeTable({ phone: 'permissive' });
      expect(t.validateField('value', 'hello').isValid).toBe(false);
      expect(t.validateField('value', '12').isValid).toBe(false); // too few digits
    });
  });
});

describe('Validation: unique rule', () => {
  test('accepts values not present elsewhere in this.data', () => {
    const data = [{ value: 'alpha' }, { value: 'beta' }];
    const t = makeTable({ unique: true }, data);
    expect(t.validateField('value', 'gamma', { value: 'gamma' }).isValid).toBe(true);
  });

  test('rejects values that already appear in another row', () => {
    const data = [{ value: 'alpha' }, { value: 'beta' }];
    const t = makeTable({ unique: true }, data);
    const r = t.validateField('value', 'alpha', { value: 'alpha-new' });
    expect(r.isValid).toBe(false);
    expect(r.errors[0]).toMatch(/unique/i);
  });

  test('does not count the row being edited as a duplicate of itself', () => {
    const data = [{ value: 'alpha' }, { value: 'beta' }];
    const t = makeTable({ unique: true }, data);
    // rowData is the same reference as data[0] — editing the row to its current
    // value must not trip the unique rule.
    const r = t.validateField('value', 'alpha', data[0]);
    expect(r.isValid).toBe(true);
  });

  test('case-insensitive match when unique: { caseInsensitive: true }', () => {
    const data = [{ value: 'Alpha' }];
    const t = makeTable({ unique: { caseInsensitive: true } }, data);
    expect(t.validateField('value', 'alpha', { value: 'alpha' }).isValid).toBe(false);
  });
});

describe('Validation: phone + required interplay', () => {
  test('empty value with required: true reports required only, not phone', () => {
    const t = makeTable({ required: true, phone: true });
    const r = t.validateField('value', '');
    expect(r.errors).toHaveLength(1);
    expect(r.errors[0]).toMatch(/required/i);
  });
});
