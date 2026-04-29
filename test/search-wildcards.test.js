/**
 * Search grammar — wildcard substitution in term + field:eq values.
 * Slice 4 of #59. Stacked on PR #89 (comparison operators).
 *
 * Adds support for `*` (zero-or-more) and `?` (single char) inside terms and
 * the value of a `field:eq` query. Quoted phrases continue to be literal.
 * Regex literals (`field:/regex/i`) are still tracked as a follow-up.
 */

const TableCrafter = require('../src/tablecrafter');

const data = [
  { id: 1, name: 'gold' },
  { id: 2, name: 'golden' },
  { id: 3, name: 'goldfish' },
  { id: 4, name: 'silver' },
  { id: 5, name: 'word' },
  { id: 6, name: 'wood' },
  { id: 7, name: 'wo' }
];
const columns = [
  { field: 'id', label: 'ID' },
  { field: 'name', label: 'Name' }
];

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { data, columns });
}

describe('Wildcards in bare terms', () => {
  test('gold* matches gold, golden, goldfish', () => {
    const t = makeTable();
    t.setQuery('gold*');
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['gold', 'golden', 'goldfish']);
  });

  test('wo?d matches word and wood, but not wo', () => {
    const t = makeTable();
    t.setQuery('wo?d');
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['wood', 'word']);
  });

  test('* matches every row (matches anything)', () => {
    const t = makeTable();
    t.setQuery('*');
    expect(t.getFilteredData()).toHaveLength(data.length);
  });

  test('regex metacharacters in the term are escaped', () => {
    const escapeData = [{ id: 1, label: 'a.b.c' }, { id: 2, label: 'aXbYc' }];
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TableCrafter('#t', {
      data: escapeData,
      columns: [{ field: 'id' }, { field: 'label' }]
    });
    t.setQuery('a.b*');
    expect(t.getFilteredData().map(r => r.label)).toEqual(['a.b.c']);
  });
});

describe('Wildcards in field:eq', () => {
  test('name:gold* scopes the wildcard to the field', () => {
    const t = makeTable();
    t.setQuery('name:gold*');
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['gold', 'golden', 'goldfish']);
  });

  test('name:wo?d scopes the single-char wildcard', () => {
    const t = makeTable();
    t.setQuery('name:wo?d');
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['wood', 'word']);
  });
});

describe('Wildcards do not apply inside quoted phrases', () => {
  test('"gold*" matches the literal three characters g-o-l-d-* (none in fixture)', () => {
    const t = makeTable();
    t.setQuery('"gold*"');
    expect(t.getFilteredData()).toHaveLength(0);
  });
});

describe('Wildcards do not apply to comparison ops or strict eq', () => {
  test('field:=gold* requires a literal value containing the asterisk', () => {
    const literalData = [{ id: 1, code: 'gold*' }, { id: 2, code: 'golden' }];
    document.body.innerHTML = '<div id="t"></div>';
    const t = new TableCrafter('#t', {
      data: literalData,
      columns: [{ field: 'id' }, { field: 'code' }]
    });
    t.setQuery('code:=gold*');
    expect(t.getFilteredData().map(r => r.code)).toEqual(['gold*']);
  });
});
