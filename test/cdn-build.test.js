/**
 * CDN distribution metadata regression net (slice 1 of #35).
 *
 * The build itself ships a UMD bundle and SRI hashes (separate ticket
 * for the actual build script). This file pins the package.json metadata
 * so future edits cannot silently strip the unpkg / jsdelivr / exports /
 * files fields that CDN consumers rely on.
 */

const fs = require('fs');
const path = require('path');

const PKG_PATH = path.join(__dirname, '..', 'package.json');

describe('package.json: CDN metadata', () => {
  let pkg;
  beforeAll(() => { pkg = JSON.parse(fs.readFileSync(PKG_PATH, 'utf8')); });

  test('declares main / module / unpkg / jsdelivr', () => {
    expect(pkg.main).toBe('dist/tablecrafter.umd.js');
    expect(pkg.module).toBe('src/tablecrafter.js');
    expect(pkg.unpkg).toBe('dist/tablecrafter.umd.min.js');
    expect(pkg.jsdelivr).toBe('dist/tablecrafter.umd.min.js');
  });

  test('files allowlist limits the published tarball to dist + src + docs', () => {
    expect(pkg.files).toEqual(expect.arrayContaining([
      'dist',
      'src',
      'README.md',
      'LICENSE'
    ]));
  });

  test('exports map declares import + require + default entries', () => {
    expect(pkg.exports).toBeDefined();
    expect(pkg.exports['.']).toBeDefined();
    expect(pkg.exports['.'].require).toBe('./dist/tablecrafter.umd.js');
    expect(pkg.exports['.'].import).toBe('./src/tablecrafter.js');
    expect(pkg.exports['.'].default).toBe('./dist/tablecrafter.umd.js');
  });

  test('build script is wired', () => {
    expect(pkg.scripts && pkg.scripts.build).toBeTruthy();
  });

  test('test script remains wired and untouched', () => {
    expect(pkg.scripts && pkg.scripts.test).toBe('jest');
  });
});
