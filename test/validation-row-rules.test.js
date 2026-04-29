/**
 * Cross-field validation: config.validation.rowRules (slice of #41 AC item 2).
 * Stacked on PR #103 (validate / getErrors / clearErrors public API).
 *
 * rowRules is an Array<({ row }) => Array<{ field, message }>>. Each rule
 * function is called per row during validate(); returned errors decorate
 * the named field exactly like single-field validation errors.
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

function makeTable(extra) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', {
    columns: [
      { field: 'start' },
      { field: 'end' },
      { field: 'password' },
      { field: 'confirm' }
    ],
    validation: {
      enabled: true,
      showErrors: true,
      validateOnEdit: true,
      validateOnSubmit: true,
      rules: {},
      messages: baseMessages,
      ...extra
    }
  });
}

describe('rowRules: cross-field validation', () => {
  test('a passing rule produces no errors', async () => {
    const t = makeTable({
      rowRules: [({ row }) => row.start <= row.end ? [] : [{ field: 'end', message: 'end must follow start' }]
      ]
    });
    t.data = [{ start: 1, end: 5 }];

    const result = await t.validate();
    expect(result.isValid).toBe(true);
    expect(result.errors).toEqual({});
  });

  test('a failing rule decorates the named field', async () => {
    const t = makeTable({
      rowRules: [
        ({ row }) => row.start <= row.end ? [] : [{ field: 'end', message: 'end must follow start' }]
      ]
    });
    t.data = [{ start: 10, end: 5 }];

    const result = await t.validate();
    expect(result.isValid).toBe(false);
    expect(result.errors[0].end).toEqual(['end must follow start']);
  });

  test('multiple errors from one rule decorate multiple fields', async () => {
    const t = makeTable({
      rowRules: [
        ({ row }) => row.password === row.confirm ? [] : [
          { field: 'password', message: 'must match' },
          { field: 'confirm',  message: 'must match' }
        ]
      ]
    });
    t.data = [{ password: 'a', confirm: 'b' }];

    const result = await t.validate();
    expect(result.errors[0].password).toEqual(['must match']);
    expect(result.errors[0].confirm).toEqual(['must match']);
  });

  test('row-rule errors merge with single-field rule errors on the same field', async () => {
    const t = makeTable({
      rules: { end: { required: true } },
      rowRules: [
        ({ row }) => row.start <= row.end ? [] : [{ field: 'end', message: 'end must follow start' }]
      ]
    });
    t.data = [{ start: 10, end: '' }];

    const result = await t.validate();
    // Field rule fires first (required), row rule appends.
    expect(result.errors[0].end).toEqual(expect.arrayContaining([
      expect.stringMatching(/required/i),
      'end must follow start'
    ]));
  });

  test('a throwing row-rule does not break validate() — error is swallowed', async () => {
    const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    const t = makeTable({
      rowRules: [
        () => { throw new Error('boom'); },
        ({ row }) => row.start <= row.end ? [] : [{ field: 'end', message: 'm' }]
      ]
    });
    t.data = [{ start: 10, end: 5 }];

    const result = await t.validate();
    expect(result.errors[0].end).toEqual(['m']);
    expect(warnSpy).toHaveBeenCalled();

    warnSpy.mockRestore();
  });
});
