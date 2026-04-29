/**
 * Rich cell types: badge / progress / link (slice 1 of #42).
 *
 * File-upload and rich-text-editor cell types remain queued under #42;
 * they require multipart and contentEditable plumbing respectively.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, status: 'open',   completion: 75,   url: 'https://example.com/a',         label: 'Alpha' },
  { id: 2, status: 'closed', completion: 100,  url: 'https://example.com/b',         label: 'Beta'  },
  { id: 3, status: 'open',   completion: -10,  url: 'javascript:alert(1)',           label: 'Bad'   },
  { id: 4, status: null,     completion: null, url: null,                            label: null    }
];

function makeTable(columns) {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('Rich cell types: badge', () => {
  test('renders <span class="tc-badge tc-badge-{status}">{value}</span>', () => {
    const table = makeTable([
      { field: 'id' },
      { field: 'status', cellType: 'badge' }
    ]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="status"]')[0];
    const badge = cell.querySelector('.tc-badge');
    expect(badge).not.toBeNull();
    expect(badge.classList.contains('tc-badge-open')).toBe(true);
    expect(badge.textContent).toBe('open');
  });

  test('column.badge.statusFor overrides the default', () => {
    const table = makeTable([
      {
        field: 'completion',
        cellType: 'badge',
        badge: { statusFor: v => v >= 100 ? 'done' : 'pending' }
      }
    ]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="completion"]');
    expect(cells[0].querySelector('.tc-badge').classList.contains('tc-badge-pending')).toBe(true);
    expect(cells[1].querySelector('.tc-badge').classList.contains('tc-badge-done')).toBe(true);
  });

  test('null / undefined value renders an empty cell', () => {
    const table = makeTable([{ field: 'status', cellType: 'badge' }]);
    table.render();
    const last = document.querySelectorAll('td[data-field="status"]')[3];
    expect(last.querySelector('.tc-badge')).toBeNull();
  });

  test('value content is rendered via textContent (no innerHTML)', () => {
    const xss = [{ status: '<img src=x onerror=alert(1)>' }];
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TableCrafter('#t', {
      data: xss,
      columns: [{ field: 'status', cellType: 'badge' }]
    });
    t.render();
    const cell = document.querySelector('td[data-field="status"]');
    expect(cell.querySelector('img')).toBeNull();
    expect(cell.querySelector('.tc-badge').textContent).toBe('<img src=x onerror=alert(1)>');
  });
});

describe('Rich cell types: progress', () => {
  test('renders wrapper + fill with inline width', () => {
    const table = makeTable([{ field: 'completion', cellType: 'progress' }]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="completion"]')[0];
    const wrap = cell.querySelector('.tc-progress');
    const fill = cell.querySelector('.tc-progress-fill');
    expect(wrap).not.toBeNull();
    expect(fill).not.toBeNull();
    expect(fill.style.width).toBe('75%');
  });

  test('clamps values over max to 100% and below zero to 0%', () => {
    const table = makeTable([{ field: 'completion', cellType: 'progress' }]);
    table.render();

    const cells = document.querySelectorAll('td[data-field="completion"]');
    expect(cells[1].querySelector('.tc-progress-fill').style.width).toBe('100%');
    expect(cells[2].querySelector('.tc-progress-fill').style.width).toBe('0%');
  });

  test('column.progress.max scales the value', () => {
    const data2 = [{ x: 50 }];
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TableCrafter('#t', {
      data: data2,
      columns: [{ field: 'x', cellType: 'progress', progress: { max: 200 } }]
    });
    t.render();

    const fill = document.querySelector('.tc-progress-fill');
    expect(fill.style.width).toBe('25%'); // 50 / 200
  });

  test('null / undefined value renders an empty cell', () => {
    const table = makeTable([{ field: 'completion', cellType: 'progress' }]);
    table.render();
    const last = document.querySelectorAll('td[data-field="completion"]')[3];
    expect(last.querySelector('.tc-progress')).toBeNull();
  });
});

describe('Rich cell types: link', () => {
  test('renders <a> with href, target=_blank, rel=noopener noreferrer', () => {
    const table = makeTable([{ field: 'url', cellType: 'link', link: { hrefFor: v => v, labelFrom: 'label' } }]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="url"]')[0];
    const link = cell.querySelector('a.tc-link');
    expect(link.getAttribute('href')).toBe('https://example.com/a');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
  });

  test('label defaults to the value when no labelFrom configured', () => {
    const table = makeTable([{ field: 'url', cellType: 'link' }]);
    table.render();

    const link = document.querySelectorAll('td[data-field="url"]')[0].querySelector('a.tc-link');
    expect(link.textContent).toBe('https://example.com/a');
  });

  test('disallowed scheme drops href and renders a plain <span>', () => {
    const table = makeTable([{ field: 'url', cellType: 'link' }]);
    table.render();

    const cell = document.querySelectorAll('td[data-field="url"]')[2];
    expect(cell.querySelector('a')).toBeNull();
    expect(cell.querySelector('span').textContent).toBe('javascript:alert(1)');
  });

  test('null / undefined value renders an empty cell', () => {
    const table = makeTable([{ field: 'url', cellType: 'link' }]);
    table.render();
    const last = document.querySelectorAll('td[data-field="url"]')[3];
    expect(last.textContent).toBe('');
  });
});
