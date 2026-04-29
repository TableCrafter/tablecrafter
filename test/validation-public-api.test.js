/**
 * Public validation API: validate() / getErrors() / clearErrors() (slice of #41).
 *
 * Self-contained: branched off main. The table.validate() helper returns an
 * aggregated result without mutating tooltip DOM, getErrors() exposes a
 * defensive copy of the internal validationErrors Map, and clearErrors()
 * narrows the clear by rowIndex / field.
 */

const TableCrafter = require('../src/tablecrafter');

const baseMessages = {
  required: 'This field is required',
  email: 'Please enter a valid email address',
  minLength: 'Minimum length is {min} characters',
  maxLength: 'Maximum length is {max} characters',
  min: 'Minimum value is {min}',
  max: 'Maximum value is {max}',
  pattern: 'Please enter a valid format',
  custom: 'Validation failed'
};

function makeTable(rules, data) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [
      { field: 'name' },
      { field: 'email' }
    ],
    data,
    validation: {
      enabled: true,
      showErrors: true,
      validateOnEdit: true,
      validateOnSubmit: true,
      rules,
      messages: baseMessages
    }
  });
}

describe('validate(): full-dataset validation', () => {
  test('returns isValid: true and an empty errors map when every row passes', async () => {
    const t = makeTable({
      name: { required: true },
      email: { email: true }
    }, [
      { name: 'Alice', email: 'alice@example.com' },
      { name: 'Bob',   email: 'bob@example.com' }
    ]);

    const result = await t.validate();
    expect(result.isValid).toBe(true);
    expect(result.errors).toEqual({});
  });

  test('returns aggregated errors keyed by rowIndex with field-level error arrays', async () => {
    const t = makeTable({
      name: { required: true },
      email: { email: true }
    }, [
      { name: '',       email: 'alice@example.com' },
      { name: 'Bob',    email: 'not-an-email'      },
      { name: 'Carol',  email: 'carol@example.com' }
    ]);

    const result = await t.validate();
    expect(result.isValid).toBe(false);
    expect(result.errors[0]).toEqual({ name: expect.arrayContaining([expect.stringMatching(/required/i)]) });
    expect(result.errors[1]).toEqual({ email: expect.arrayContaining([expect.stringMatching(/email/i)]) });
    expect(result.errors[2]).toBeUndefined();
  });

  test('does not mutate tooltip DOM', async () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }]);
    document.body.innerHTML += '<div id="probe"></div>'; // sentinel
    await t.validate();
    expect(document.querySelector('.tc-validation-tooltip')).toBeNull();
  });

  test('resolves a real Promise (not a sync result)', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }]);
    const p = t.validate();
    expect(typeof p.then).toBe('function');
  });
});

describe('getErrors(): defensive snapshot', () => {
  test('returns the full errors map keyed by rowIndex when called with no args', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }, { name: 'Bob' }]);
    t.setValidationError(0, 'name', ['This field is required']);

    const out = t.getErrors();
    expect(out[0].name).toEqual(['This field is required']);
    expect(out[1]).toBeUndefined();
  });

  test('returns just the row when called with a rowIndex', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }, { name: 'Bob' }]);
    t.setValidationError(0, 'name', ['This field is required']);
    t.setValidationError(1, 'name', ['Other error']);

    expect(t.getErrors(0)).toEqual({ name: ['This field is required'] });
    expect(t.getErrors(1)).toEqual({ name: ['Other error'] });
  });

  test('mutating the snapshot does not affect internal state', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }]);
    t.setValidationError(0, 'name', ['msg']);

    const snap = t.getErrors();
    snap[0].name.push('TAMPER');

    expect(t.getErrors(0).name).toEqual(['msg']);
  });
});

describe('clearErrors(): narrowed clearing', () => {
  test('clearErrors(rowIndex, field) clears only that field for that row', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }]);
    t.setValidationError(0, 'name', ['msg']);
    t.setValidationError(0, 'email', ['email msg']);

    t.clearErrors(0, 'name');

    expect(t.getErrors(0).name).toBeUndefined();
    expect(t.getErrors(0).email).toEqual(['email msg']);
  });

  test('clearErrors(rowIndex) clears every field on that row', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }, { name: '' }]);
    t.setValidationError(0, 'name', ['a']);
    t.setValidationError(0, 'email', ['b']);
    t.setValidationError(1, 'name', ['c']);

    t.clearErrors(0);

    expect(t.getErrors(0)).toEqual({});
    expect(t.getErrors(1)).toEqual({ name: ['c'] });
  });

  test('clearErrors() clears every error map-wide', () => {
    const t = makeTable({ name: { required: true } }, [{ name: '' }, { name: '' }]);
    t.setValidationError(0, 'name', ['a']);
    t.setValidationError(1, 'name', ['b']);

    t.clearErrors();

    expect(t.getErrors()).toEqual({});
  });
});
