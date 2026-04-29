/**
 * parseQuery() — search grammar foundation (slice 1 of #59).
 *
 * This PR lands only the parser producing a normalised AST for the basic
 * grammar: whitespace AND, OR, -negation, "quoted phrase", field:value,
 * bare terms. Comparison operators (>, <, =), regex literals, wildcards,
 * the search builder modal, suggestions, and presets are deferred to
 * follow-up PRs and remain tracked in #59.
 */

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
