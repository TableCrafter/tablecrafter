/**
 * Built-in validation rules: url / oneOf / notOneOf
 * Slice of issue #41 (built-in rule library expansion).
 */

const TableCrafter = require('../src/tablecrafter');

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
      messages: {
        required: 'This field is required',
        email: 'Please enter a valid email address',
        url: 'Please enter a valid URL',
        oneOf: 'Value must be one of {allowed}',
        notOneOf: 'Value is not allowed',
        minLength: 'Minimum length is {min} characters',
        maxLength: 'Maximum length is {max} characters',
        min: 'Minimum value is {min}',
        max: 'Maximum value is {max}',
        pattern: 'Please enter a valid format',
        custom: 'Validation failed'
      }
    }
  });
}

describe('Validation: built-in rules (url / oneOf / notOneOf)', () => {
  describe('url', () => {
    test('accepts well-formed http(s) URLs', () => {
      const t = makeTable({ url: true });
      expect(t.validateField('value', 'https://example.com').isValid).toBe(true);
      expect(t.validateField('value', 'http://example.com/path?q=1#x').isValid).toBe(true);
      expect(t.validateField('value', 'https://sub.example.co.uk:8080/a/b').isValid).toBe(true);
    });

    test('rejects malformed URLs with the configured message', () => {
      const t = makeTable({ url: true });
      const r = t.validateField('value', 'not a url');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/url/i);

      expect(t.validateField('value', 'example.com').isValid).toBe(false);
      expect(t.validateField('value', 'ftp://example.com').isValid).toBe(false);
      expect(t.validateField('value', 'http://').isValid).toBe(false);
    });

    test('honours custom message override', () => {
      const t = makeTable({ url: true, message: 'must be a link' });
      const r = t.validateField('value', 'nope');
      expect(r.errors[0]).toBe('must be a link');
    });

    test('skips validation on empty value when not required', () => {
      const t = makeTable({ url: true });
      expect(t.validateField('value', '').isValid).toBe(true);
      expect(t.validateField('value', null).isValid).toBe(true);
    });
  });

  describe('oneOf', () => {
    test('accepts values present in the allowed list', () => {
      const t = makeTable({ oneOf: ['draft', 'published', 'archived'] });
      expect(t.validateField('value', 'draft').isValid).toBe(true);
      expect(t.validateField('value', 'published').isValid).toBe(true);
    });

    test('rejects values outside the allowed list', () => {
      const t = makeTable({ oneOf: ['draft', 'published'] });
      const r = t.validateField('value', 'pending');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/draft|published|allowed/i);
    });

    test('handles numeric and mixed-type lists', () => {
      const t = makeTable({ oneOf: [1, 2, 3] });
      expect(t.validateField('value', 2).isValid).toBe(true);
      expect(t.validateField('value', 4).isValid).toBe(false);
    });
  });

  describe('notOneOf', () => {
    test('accepts values not in the disallowed list', () => {
      const t = makeTable({ notOneOf: ['admin', 'root'] });
      expect(t.validateField('value', 'editor').isValid).toBe(true);
    });

    test('rejects values present in the disallowed list', () => {
      const t = makeTable({ notOneOf: ['admin', 'root'] });
      const r = t.validateField('value', 'admin');
      expect(r.isValid).toBe(false);
      expect(r.errors[0]).toMatch(/admin|not allowed|reserved/i);
    });
  });

  describe('combined with required and other rules', () => {
    test('required + url: empty fails on required, malformed fails on url', () => {
      const t = makeTable({ required: true, url: true });
      const e = t.validateField('value', '');
      expect(e.isValid).toBe(false);
      expect(e.errors[0]).toMatch(/required/i);

      const u = t.validateField('value', 'nope');
      expect(u.isValid).toBe(false);
      expect(u.errors[0]).toMatch(/url/i);
    });

    test('oneOf + required: empty fails on required only', () => {
      const t = makeTable({ required: true, oneOf: ['a', 'b'] });
      const r = t.validateField('value', '');
      expect(r.errors).toHaveLength(1);
      expect(r.errors[0]).toMatch(/required/i);
    });
  });
});
