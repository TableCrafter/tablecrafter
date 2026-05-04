/**
 * Release workflow regression net (slice 1 of #36).
 *
 * The workflow itself ships in `.github/workflows/release.yml`. There is no
 * traditional unit test for a YAML workflow — verification happens via
 * `actionlint` locally + smoke-testing on a fork. This file exists so a
 * future edit cannot silently delete or rename the file or break the top-
 * level shape that downstream jobs depend on.
 */

const fs = require('fs');
const path = require('path');

const WORKFLOW_PATH = path.join(__dirname, '..', '.github', 'workflows', 'release.yml');

describe('release workflow file', () => {
  let contents;

  beforeAll(() => {
    contents = fs.readFileSync(WORKFLOW_PATH, 'utf8');
  });

  test('exists', () => {
    expect(fs.existsSync(WORKFLOW_PATH)).toBe(true);
  });

  test('starts with name: Release', () => {
    expect(contents).toMatch(/^name:\s*Release/m);
  });

  test('triggers on v*.*.* tag push', () => {
    expect(contents).toMatch(/on:[\s\S]*push:[\s\S]*tags:[\s\S]*v\*\.\*\.\*/);
  });

  test('declares a `release` job', () => {
    expect(contents).toMatch(/jobs:[\s\S]*\brelease:/);
  });

  test('runs npm test as part of the pipeline', () => {
    expect(contents).toMatch(/npm test/);
  });

  test('runs npm run build as part of the pipeline', () => {
    expect(contents).toMatch(/npm run build/);
  });

  test('uses softprops/action-gh-release to publish the release', () => {
    expect(contents).toMatch(/softprops\/action-gh-release/);
  });

  test('grants contents: write permission for the release step', () => {
    expect(contents).toMatch(/permissions:[\s\S]*contents:\s*write/);
  });
});
