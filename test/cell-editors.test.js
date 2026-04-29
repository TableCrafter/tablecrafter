// Cell type editor factory methods (#42 rich cell types - inline editing)

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [{ field: 'id' }],
    data: [{ id: 1 }],
    ...extra
  });
}

const t = makeTable();

// ── createTextEditor ─────────────────────────────────────────────────────────

describe('createTextEditor', () => {
  test('returns a text input with the current value', () => {
    const el = t.createTextEditor({ field: 'name' }, 'Alice');
    expect(el.tagName).toBe('INPUT');
    expect(el.type).toBe('text');
    expect(el.value).toBe('Alice');
  });

  test('empty string when no current value', () => {
    const el = t.createTextEditor({ field: 'name' }, null);
    expect(el.value).toBe('');
  });

  test('applies maxLength from column config', () => {
    const el = t.createTextEditor({ field: 'name', maxLength: 50 }, '');
    expect(el.maxLength).toBe(50);
  });

  test('applies placeholder from column config', () => {
    const el = t.createTextEditor({ field: 'name', placeholder: 'Enter name' }, '');
    expect(el.placeholder).toBe('Enter name');
  });
});

// ── createTextareaEditor ─────────────────────────────────────────────────────

describe('createTextareaEditor', () => {
  test('returns a textarea with the current value', () => {
    const el = t.createTextareaEditor({ field: 'bio' }, 'Hello');
    expect(el.tagName).toBe('TEXTAREA');
    expect(el.value).toBe('Hello');
  });

  test('applies column rows override', () => {
    const el = t.createTextareaEditor({ field: 'bio', rows: 10 }, '');
    expect(el.rows).toBe(10);
  });
});

// ── createNumberEditor ───────────────────────────────────────────────────────

describe('createNumberEditor', () => {
  test('returns a number input with current value', () => {
    const el = t.createNumberEditor({ field: 'qty' }, '42');
    expect(el.type).toBe('number');
    expect(el.value).toBe('42');
  });

  test('applies min/max/step', () => {
    const el = t.createNumberEditor({ field: 'qty', min: 1, max: 100, step: 5 }, '');
    expect(el.min).toBe('1');
    expect(el.max).toBe('100');
    expect(el.step).toBe('5');
  });
});

// ── createEmailEditor ────────────────────────────────────────────────────────

describe('createEmailEditor', () => {
  test('returns an email input with current value', () => {
    const el = t.createEmailEditor({ field: 'email', placeholder: 'user@example.com' }, 'a@b.com');
    expect(el.type).toBe('email');
    expect(el.value).toBe('a@b.com');
    expect(el.placeholder).toBe('user@example.com');
  });
});

// ── createDateEditor ─────────────────────────────────────────────────────────

describe('createDateEditor', () => {
  test('returns a date input', () => {
    const el = t.createDateEditor({ field: 'dob' }, null);
    expect(el.type).toBe('date');
  });

  test('formats ISO date string for input', () => {
    const el = t.createDateEditor({ field: 'dob' }, '2024-03-15T00:00:00Z');
    expect(el.value).toBe('2024-03-15');
  });

  test('applies min/max constraints', () => {
    const el = t.createDateEditor({ field: 'dob', min: '2020-01-01', max: '2030-12-31' }, null);
    expect(el.min).toBe('2020-01-01');
    expect(el.max).toBe('2030-12-31');
  });

  test('invalid date string leaves value empty', () => {
    const el = t.createDateEditor({ field: 'dob' }, 'not-a-date');
    expect(el.value).toBe('');
  });
});

// ── createDateTimeEditor ─────────────────────────────────────────────────────

describe('createDateTimeEditor', () => {
  test('returns a datetime-local input', () => {
    const el = t.createDateTimeEditor({ field: 'ts' }, null);
    expect(el.type).toBe('datetime-local');
  });

  test('formats ISO datetime for input', () => {
    const el = t.createDateTimeEditor({ field: 'ts' }, '2024-06-01T12:00:00Z');
    expect(el.value).toBeTruthy();
    expect(el.value.startsWith('2024-06-01')).toBe(true);
  });
});

// ── createSelectEditor ───────────────────────────────────────────────────────

describe('createSelectEditor', () => {
  test('returns a select with string options', () => {
    const el = t.createSelectEditor(
      { field: 'status', options: ['open', 'closed', 'pending'] },
      'closed'
    );
    expect(el.tagName).toBe('SELECT');
    expect(el.querySelectorAll('option').length).toBe(3);
    expect(el.value).toBe('closed');
  });

  test('supports object options with value/label', () => {
    const el = t.createSelectEditor(
      { field: 'role', options: [{ value: 'admin', label: 'Administrator' }, { value: 'user', label: 'User' }] },
      'admin'
    );
    const options = el.querySelectorAll('option');
    expect(options[0].textContent).toBe('Administrator');
    expect(el.value).toBe('admin');
  });

  test('adds disabled placeholder option when column.placeholder is set', () => {
    const el = t.createSelectEditor(
      { field: 'status', placeholder: 'Choose...', options: ['open'] },
      ''
    );
    const first = el.querySelector('option');
    expect(first.disabled).toBe(true);
    expect(first.textContent).toBe('Choose...');
  });
});

// ── createMultiSelectEditor ──────────────────────────────────────────────────

describe('createMultiSelectEditor', () => {
  test('returns container with checkboxes for each option', () => {
    const el = t.createMultiSelectEditor(
      { field: 'tags', options: ['a', 'b', 'c'] },
      'a,b'
    );
    const checkboxes = el.querySelectorAll('input[type="checkbox"]');
    expect(checkboxes.length).toBe(3);
    expect(checkboxes[0].checked).toBe(true);
    expect(checkboxes[1].checked).toBe(true);
    expect(checkboxes[2].checked).toBe(false);
  });

  test('accepts array as current value', () => {
    const el = t.createMultiSelectEditor(
      { field: 'tags', options: ['x', 'y'] },
      ['x']
    );
    const cbs = el.querySelectorAll('input[type="checkbox"]');
    expect(cbs[0].checked).toBe(true);
    expect(cbs[1].checked).toBe(false);
  });

  test('getValue() returns array of checked values', () => {
    const el = t.createMultiSelectEditor(
      { field: 'tags', options: ['a', 'b', 'c'] },
      ['a', 'c']
    );
    expect(el.getValue()).toEqual(['a', 'c']);
  });

  test('supports object options', () => {
    const el = t.createMultiSelectEditor(
      { field: 'tags', options: [{ value: 'v1', label: 'Label 1' }] },
      ['v1']
    );
    expect(el.querySelectorAll('input[type="checkbox"]')[0].checked).toBe(true);
  });
});

// ── createCheckboxEditor ─────────────────────────────────────────────────────

describe('createCheckboxEditor', () => {
  test('returns a container with a checked checkbox for truthy value', () => {
    const el = t.createCheckboxEditor({ field: 'active' }, true);
    const cb = el.querySelector('input[type="checkbox"]');
    expect(cb.checked).toBe(true);
  });

  test('unchecked for falsy value', () => {
    const el = t.createCheckboxEditor({ field: 'active' }, false);
    expect(el.querySelector('input[type="checkbox"]').checked).toBe(false);
  });

  test('wraps in label when column.label is set', () => {
    const el = t.createCheckboxEditor({ field: 'active', label: 'Active?' }, false);
    expect(el.querySelector('label')).not.toBeNull();
  });

  test('getValue() returns boolean', () => {
    const el = t.createCheckboxEditor({ field: 'active' }, true);
    expect(el.getValue()).toBe(true);
  });
});

// ── createRadioEditor ────────────────────────────────────────────────────────

describe('createRadioEditor', () => {
  test('returns container with radio inputs', () => {
    const el = t.createRadioEditor(
      { field: 'priority', options: ['low', 'medium', 'high'] },
      'medium'
    );
    const radios = el.querySelectorAll('input[type="radio"]');
    expect(radios.length).toBe(3);
    expect(radios[1].checked).toBe(true);
  });

  test('supports object options', () => {
    const el = t.createRadioEditor(
      { field: 'priority', options: [{ value: 'hi', label: 'High' }] },
      'hi'
    );
    expect(el.querySelector('input[type="radio"]').checked).toBe(true);
  });

  test('getValue() returns selected radio value', () => {
    const el = t.createRadioEditor(
      { field: 'priority', options: ['a', 'b'] },
      'a'
    );
    expect(el.getValue()).toBe('a');
  });

  test('getValue() returns empty string when nothing selected', () => {
    const el = t.createRadioEditor(
      { field: 'priority', options: ['a', 'b'] },
      'none'
    );
    expect(el.getValue()).toBe('');
  });
});

// ── createFileEditor ─────────────────────────────────────────────────────────

describe('createFileEditor', () => {
  test('returns container with file input', () => {
    const el = t.createFileEditor({ field: 'doc' }, null);
    expect(el.querySelector('input[type="file"]')).not.toBeNull();
  });

  test('shows current file preview when value exists', () => {
    const el = t.createFileEditor({ field: 'doc' }, 'existing.pdf');
    expect(el.querySelector('.tc-file-preview')).not.toBeNull();
    expect(el.querySelector('.tc-file-preview').textContent).toContain('existing.pdf');
  });

  test('applies accept and multiple attributes', () => {
    const el = t.createFileEditor({ field: 'doc', accept: '.pdf', multiple: true }, null);
    const input = el.querySelector('input[type="file"]');
    expect(input.accept).toBe('.pdf');
    expect(input.multiple).toBe(true);
  });

  test('getValue() returns currentValue when no file selected', () => {
    const el = t.createFileEditor({ field: 'doc' }, 'old.pdf');
    expect(el.getValue()).toBe('old.pdf');
  });
});

// ── createUrlEditor ──────────────────────────────────────────────────────────

describe('createUrlEditor', () => {
  test('returns a url input with current value and default placeholder', () => {
    const el = t.createUrlEditor({ field: 'website' }, 'https://foo.com');
    expect(el.type).toBe('url');
    expect(el.value).toBe('https://foo.com');
    expect(el.placeholder).toBe('https://example.com');
  });

  test('uses column placeholder if provided', () => {
    const el = t.createUrlEditor({ field: 'website', placeholder: 'https://mine.io' }, '');
    expect(el.placeholder).toBe('https://mine.io');
  });
});

// ── createColorEditor ────────────────────────────────────────────────────────

describe('createColorEditor', () => {
  test('returns container with color and text inputs synced', () => {
    const el = t.createColorEditor({ field: 'color' }, '#ff0000');
    const colorInput = el.querySelector('input[type="color"]');
    const textInput = el.querySelector('.tc-color-text');
    expect(colorInput.value).toBe('#ff0000');
    expect(textInput.value).toBe('#ff0000');
  });

  test('color picker change syncs to text input', () => {
    const el = t.createColorEditor({ field: 'color' }, '#000000');
    const colorInput = el.querySelector('input[type="color"]');
    const textInput = el.querySelector('.tc-color-text');
    colorInput.value = '#00ff00';
    colorInput.dispatchEvent(new Event('change'));
    expect(textInput.value).toBe('#00ff00');
  });

  test('text input valid hex syncs to color picker', () => {
    const el = t.createColorEditor({ field: 'color' }, '#000000');
    const colorInput = el.querySelector('input[type="color"]');
    const textInput = el.querySelector('.tc-color-text');
    textInput.value = '#abcdef';
    textInput.dispatchEvent(new Event('change'));
    expect(colorInput.value).toBe('#abcdef');
  });

  test('getValue() returns text input value', () => {
    const el = t.createColorEditor({ field: 'color' }, '#123456');
    expect(el.getValue()).toBe('#123456');
  });
});

// ── createRangeEditor ────────────────────────────────────────────────────────

describe('createRangeEditor', () => {
  test('returns container with range input and display span', () => {
    const el = t.createRangeEditor({ field: 'score', min: 0, max: 100, step: 1 }, '75');
    const range = el.querySelector('input[type="range"]');
    const display = el.querySelector('.tc-range-display');
    expect(range).not.toBeNull();
    expect(range.value).toBe('75');
    expect(display.textContent).toBe('75');
  });

  test('input event updates display', () => {
    const el = t.createRangeEditor({ field: 'score', min: 0, max: 100 }, '50');
    const range = el.querySelector('input[type="range"]');
    const display = el.querySelector('.tc-range-display');
    range.value = '80';
    range.dispatchEvent(new Event('input'));
    expect(display.textContent).toBe('80');
  });

  test('getValue() returns range input value', () => {
    const el = t.createRangeEditor({ field: 'score', min: 0, max: 10 }, '5');
    expect(el.getValue()).toBe('5');
  });

  test('uses column.min as default when no current value', () => {
    const el = t.createRangeEditor({ field: 'score', min: 10, max: 100 }, null);
    expect(el.querySelector('input[type="range"]').value).toBe('10');
  });
});
