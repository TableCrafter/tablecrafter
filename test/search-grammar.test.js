// parseQuery + evaluator tests for enhanced search grammar (#59)

const TableCrafter = require('../src/tablecrafter');

function makeTable() {
  document.body.innerHTML = '<div id="t"></div>';
  return new TableCrafter('#t', { columns: [{ field: 'id' }] });
}

describe('parseQuery: bare terms', () => {
  test('single bare term', () => {
    const t = makeTable();
    expect(t.parseQuery('foo')).toEqual({
      type: 'and',
      children: [{ type: 'term', value: 'foo' }]
    });
  });

  test('whitespace separates AND-combined terms', () => {
    const t = makeTable();
    expect(t.parseQuery('foo bar baz')).toEqual({
      type: 'and',
      children: [
        { type: 'term', value: 'foo' },
        { type: 'term', value: 'bar' },
        { type: 'term', value: 'baz' }
      ]
    });
  });

  test('empty query produces an empty AND', () => {
    const t = makeTable();
    expect(t.parseQuery('')).toEqual({ type: 'and', children: [] });
  });

  test('whitespace-only query produces an empty AND', () => {
    const t = makeTable();
    expect(t.parseQuery('   ')).toEqual({ type: 'and', children: [] });
  });
});

describe('parseQuery: OR', () => {
  test('OR splits adjacent terms into a disjunction', () => {
    const t = makeTable();
    expect(t.parseQuery('foo OR bar')).toEqual({
      type: 'and',
      children: [
        {
          type: 'or',
          children: [
            { type: 'term', value: 'foo' },
            { type: 'term', value: 'bar' }
          ]
        }
      ]
    });
  });

  test('case-insensitive: "or" works the same as "OR"', () => {
    const t = makeTable();
    const upper = t.parseQuery('foo OR bar');
    const lower = t.parseQuery('foo or bar');
    expect(lower).toEqual(upper);
  });
});

describe('parseQuery: negation', () => {
  test('-term wraps a term in a NOT', () => {
    const t = makeTable();
    expect(t.parseQuery('-foo')).toEqual({
      type: 'and',
      children: [{ type: 'not', child: { type: 'term', value: 'foo' } }]
    });
  });

  test('-"phrase" negates a quoted phrase', () => {
    const t = makeTable();
    expect(t.parseQuery('-"foo bar"')).toEqual({
      type: 'and',
      children: [{ type: 'not', child: { type: 'phrase', value: 'foo bar' } }]
    });
  });

  test('-field:value negates a field-scoped match', () => {
    const t = makeTable();
    expect(t.parseQuery('-status:archived')).toEqual({
      type: 'and',
      children: [{
        type: 'not',
        child: { type: 'field', field: 'status', op: 'eq', value: 'archived' }
      }]
    });
  });
});

describe('parseQuery: quoted phrases', () => {
  test('quoted phrase becomes a single phrase node', () => {
    const t = makeTable();
    expect(t.parseQuery('"foo bar"')).toEqual({
      type: 'and',
      children: [{ type: 'phrase', value: 'foo bar' }]
    });
  });

  test('quoted phrase preserves internal whitespace', () => {
    const t = makeTable();
    expect(t.parseQuery('"  spaced  out  "')).toEqual({
      type: 'and',
      children: [{ type: 'phrase', value: '  spaced  out  ' }]
    });
  });
});

describe('parseQuery: field:value', () => {
  test('simple field:value', () => {
    const t = makeTable();
    expect(t.parseQuery('status:open')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'status', op: 'eq', value: 'open' }]
    });
  });

  test('field:"quoted value" preserves internal whitespace', () => {
    const t = makeTable();
    expect(t.parseQuery('name:"Jane Doe"')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'name', op: 'eq', value: 'Jane Doe' }]
    });
  });
});

describe('parseQuery: combinations', () => {
  test('mixed AND + OR + negation + field + phrase', () => {
    const t = makeTable();
    const ast = t.parseQuery('foo OR bar -baz status:open "exact phrase"');
    expect(ast).toEqual({
      type: 'and',
      children: [
        {
          type: 'or',
          children: [
            { type: 'term', value: 'foo' },
            { type: 'term', value: 'bar' }
          ]
        },
        { type: 'not', child: { type: 'term', value: 'baz' } },
        { type: 'field', field: 'status', op: 'eq', value: 'open' },
        { type: 'phrase', value: 'exact phrase' }
      ]
    });
  });
});

// ── Comparison operators ──────────────────────────────────────────────────────

describe('parseQuery: comparison operators', () => {
  test('field:>10 produces op:gt', () => {
    const t = makeTable();
    expect(t.parseQuery('age:>10')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'gt', value: '10' }]
    });
  });

  test('field:>=10 produces op:gte', () => {
    const t = makeTable();
    expect(t.parseQuery('age:>=10')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'gte', value: '10' }]
    });
  });

  test('field:<5 produces op:lt', () => {
    const t = makeTable();
    expect(t.parseQuery('age:<5')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'lt', value: '5' }]
    });
  });

  test('field:<=5 produces op:lte', () => {
    const t = makeTable();
    expect(t.parseQuery('age:<=5')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'age', op: 'lte', value: '5' }]
    });
  });
});

// ── Regex literals ────────────────────────────────────────────────────────────

describe('parseQuery: regex literals', () => {
  test('field:/pattern/ produces op:regex', () => {
    const t = makeTable();
    expect(t.parseQuery('name:/^foo/')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'name', op: 'regex', value: '/^foo/' }]
    });
  });

  test('field:/pattern/i preserves flags', () => {
    const t = makeTable();
    expect(t.parseQuery('name:/bar/i')).toEqual({
      type: 'and',
      children: [{ type: 'field', field: 'name', op: 'regex', value: '/bar/i' }]
    });
  });
});

// ── Evaluator: basic matching ─────────────────────────────────────────────────

const ROWS = [
  { name: 'Alice',   age: 30, status: 'active' },
  { name: 'Bob',     age: 25, status: 'inactive' },
  { name: 'Charlie', age: 35, status: 'active' },
  { name: 'Delta',   age: 28, status: 'archived' },
];

function filtered(t, query) {
  t.data = ROWS;
  t.searchTerm = query;
  return t.getFilteredData();
}

describe('evaluator: bare term and phrase', () => {
  test('bare term matches any column substring', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'ali');
    expect(result.map(r => r.name)).toEqual(['Alice']);
  });

  test('AND of two terms narrows results', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'active alice');
    expect(result.map(r => r.name)).toEqual(['Alice']);
  });

  test('quoted phrase matches literal substring', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, '"li"');
    expect(result.map(r => r.name)).toEqual(['Alice', 'Charlie']);
  });
});

describe('evaluator: OR and negation', () => {
  test('OR returns rows matching either side', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'alice OR bob');
    expect(result.map(r => r.name).sort()).toEqual(['Alice', 'Bob']);
  });

  test('negation excludes matching rows', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, '-inactive -archived');
    expect(result.map(r => r.name).sort()).toEqual(['Alice', 'Charlie']);
  });
});

describe('evaluator: field:value', () => {
  test('field:value scopes match to that column (substring)', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    // 'archived' only appears in Delta's status column, not in any name/age
    const result = filtered(t, 'status:archived');
    expect(result.map(r => r.name)).toEqual(['Delta']);
  });

  test('field:>N numeric comparison', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'age:>28');
    expect(result.map(r => r.name).sort()).toEqual(['Alice', 'Charlie']);
  });

  test('field:<=N numeric comparison', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'age:<=25');
    expect(result.map(r => r.name)).toEqual(['Bob']);
  });

  test('non-numeric cell skipped for numeric comparison', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    t.data = [{ name: 'X', age: 'N/A' }, { name: 'Y', age: 40 }];
    t.searchTerm = 'age:>30';
    expect(t.getFilteredData().map(r => r.name)).toEqual(['Y']);
  });

  test('field:/regex/i matches via regex', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    const result = filtered(t, 'name:/^(alice|bob)$/i');
    expect(result.map(r => r.name).sort()).toEqual(['Alice', 'Bob']);
  });
});

describe('evaluator: wildcards', () => {
  test('* wildcard in bare term matches prefix', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    t.data = [{ name: 'gold' }, { name: 'golden' }, { name: 'goldfish' }, { name: 'silver' }];
    t.searchTerm = 'gold*';
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['gold', 'golden', 'goldfish']);
  });

  test('? wildcard matches a single character', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    t.data = [{ name: 'cat' }, { name: 'bat' }, { name: 'cart' }];
    t.searchTerm = '?at';
    expect(t.getFilteredData().map(r => r.name).sort()).toEqual(['bat', 'cat']);
  });
});

// ── setQuery public API ───────────────────────────────────────────────────────

describe('setQuery()', () => {
  test('setQuery updates searchTerm and re-renders', () => {
    const t = makeTable();
    t.config.globalSearch = true;
    t.data = ROWS;
    const renderSpy = jest.spyOn(t, 'render').mockImplementation(() => {});
    t.setQuery('alice');
    expect(t.searchTerm).toBe('alice');
    expect(renderSpy).toHaveBeenCalled();
  });

  test('setQuery(null) clears search', () => {
    const t = makeTable();
    t.setQuery(null);
    expect(t.searchTerm).toBe('');
  });
});

// ── Presets API ───────────────────────────────────────────────────────────────

describe('savePreset / removePreset', () => {
  test('savePreset stores current searchTerm in config.search.presets', () => {
    const t = makeTable();
    t.searchTerm = 'status:active';
    const preset = t.savePreset('Active users');
    expect(t.config.search.presets).toHaveLength(1);
    expect(t.config.search.presets[0]).toMatchObject({ label: 'Active users', query: 'status:active' });
    expect(preset.id).toBeTruthy();
  });

  test('removePreset deletes the preset by id', () => {
    const t = makeTable();
    t.searchTerm = 'foo';
    const preset = t.savePreset('Foo');
    t.removePreset(preset.id);
    expect(t.config.search.presets).toHaveLength(0);
  });

  test('removePreset is a no-op when no presets exist', () => {
    const t = makeTable();
    expect(() => t.removePreset('nonexistent')).not.toThrow();
  });
});
