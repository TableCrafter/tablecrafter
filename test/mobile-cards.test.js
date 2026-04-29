// Mobile card rendering, toggleCard, getVisibleFields, getHiddenFields

const TC = require('../src/tablecrafter');

function makeTable(extra = {}) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TC('#t', {
    columns: [
      { field: 'id',    label: 'ID' },
      { field: 'name',  label: 'Name' },
      { field: 'email', label: 'Email' }
    ],
    data: [{ id: 1, name: 'Alice', email: 'a@x.com' }],
    ...extra
  });
}

// ── getVisibleFields ─────────────────────────────────────────────────────────

describe('getVisibleFields', () => {
  test('returns all non-hidden columns when no fieldVisibility config', () => {
    const t = makeTable();
    const visible = t.getVisibleFields('mobile');
    expect(visible.map(c => c.field)).toEqual(['id', 'name', 'email']);
  });

  test('filters by showFields when provided', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id', 'name'] } } }
    });
    const visible = t.getVisibleFields('mobile');
    expect(visible.map(c => c.field)).toEqual(['id', 'name']);
  });

  test('excludes hideFields when provided', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { hideFields: ['email'] } } }
    });
    const visible = t.getVisibleFields('mobile');
    expect(visible.map(c => c.field)).toEqual(['id', 'name']);
  });

  test('skips columns with hidden:true', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }, { field: 'secret', hidden: true }],
      data: [{ id: 1, secret: 'x' }]
    });
    const visible = t.getVisibleFields('desktop');
    expect(visible.map(c => c.field)).toEqual(['id']);
  });
});

// ── getHiddenFields ──────────────────────────────────────────────────────────

describe('getHiddenFields', () => {
  test('returns empty array when no fieldVisibility config', () => {
    const t = makeTable();
    expect(t.getHiddenFields('mobile')).toEqual([]);
  });

  test('returns fields not in showFields', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id'] } } }
    });
    const hidden = t.getHiddenFields('mobile');
    expect(hidden.map(c => c.field)).toEqual(['name', 'email']);
  });

  test('returns hideFields columns', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { hideFields: ['email'] } } }
    });
    const hidden = t.getHiddenFields('mobile');
    expect(hidden.map(c => c.field)).toEqual(['email']);
  });
});

// ── renderCards ──────────────────────────────────────────────────────────────

describe('renderCards', () => {
  test('renders a .tc-cards-container with cards', () => {
    const t = makeTable();
    const container = t.renderCards();
    expect(container.className).toBe('tc-cards-container');
    expect(container.querySelectorAll('.tc-card').length).toBe(1);
  });

  test('renders "No results found" when data is empty', () => {
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TC('#t', {
      columns: [{ field: 'id' }],
      data: []
    });
    const container = t.renderCards();
    expect(container.querySelector('.tc-no-results')).not.toBeNull();
  });

  test('card shows expand toggle when there are hidden fields', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id'] } } }
    });
    jest.spyOn(t, 'getCurrentBreakpoint').mockReturnValue('mobile');
    const container = t.renderCards();
    expect(container.querySelector('.tc-card-toggle')).not.toBeNull();
  });

  test('card has no expand toggle when all fields visible', () => {
    const t = makeTable();
    const container = t.renderCards();
    expect(container.querySelector('.tc-card-toggle')).toBeNull();
  });

  test('renders checkbox in card when bulk.enabled', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    const container = t.renderCards();
    expect(container.querySelector('input[type="checkbox"]')).not.toBeNull();
  });

  test('bulk checkbox change calls toggleRowSelection', () => {
    const t = makeTable({ bulk: { enabled: true, operations: ['delete'] } });
    const spy = jest.spyOn(t, 'toggleRowSelection').mockImplementation(() => {});
    const container = t.renderCards();
    const cb = container.querySelector('input[type="checkbox"]');
    cb.checked = true;
    cb.dispatchEvent(new Event('change'));
    expect(spy).toHaveBeenCalledWith(0, true);
  });
});

// ── toggleCard ───────────────────────────────────────────────────────────────

describe('toggleCard', () => {
  test('adds tc-card-expanded class on first toggle', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id'] } } }
    });
    jest.spyOn(t, 'getCurrentBreakpoint').mockReturnValue('mobile');
    const container = t.renderCards();
    const card = container.querySelector('.tc-card');

    t.toggleCard(card);
    expect(card.classList.contains('tc-card-expanded')).toBe(true);
  });

  test('removes tc-card-expanded class on second toggle (collapse)', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id'] } } }
    });
    jest.spyOn(t, 'getCurrentBreakpoint').mockReturnValue('mobile');
    const container = t.renderCards();
    const card = container.querySelector('.tc-card');

    t.toggleCard(card);
    t.toggleCard(card);
    expect(card.classList.contains('tc-card-expanded')).toBe(false);
  });

  test('card header click calls toggleCard', () => {
    const t = makeTable({
      responsive: { fieldVisibility: { mobile: { showFields: ['id'] } } }
    });
    jest.spyOn(t, 'getCurrentBreakpoint').mockReturnValue('mobile');
    const spy = jest.spyOn(t, 'toggleCard');
    const container = t.renderCards();
    const header = container.querySelector('.tc-card-header');
    header.click();
    expect(spy).toHaveBeenCalled();
  });
});
