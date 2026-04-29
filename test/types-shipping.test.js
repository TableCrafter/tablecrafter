/**
 * Types-shipping regression net (slice 1 of #33).
 *
 * Pins the existence and basic shape of the .d.ts file + package.json
 * metadata so a future edit cannot silently strip TypeScript support.
 *
 * Actual TS-compiler validation of the declarations themselves is gated on
 * a follow-up ticket that wires `tsc --noEmit` against a TS smoke fixture.
 */

const fs = require('fs');
const path = require('path');

const PKG_PATH = path.join(__dirname, '..', 'package.json');
const DTS_PATH = path.join(__dirname, '..', 'src', 'tablecrafter.d.ts');

describe('TypeScript declarations: shipping', () => {
  test('src/tablecrafter.d.ts exists and is non-empty', () => {
    expect(fs.existsSync(DTS_PATH)).toBe(true);
    const contents = fs.readFileSync(DTS_PATH, 'utf8');
    expect(contents.length).toBeGreaterThan(0);
  });

  test('declares the TableCrafter class', () => {
    const contents = fs.readFileSync(DTS_PATH, 'utf8');
    expect(contents).toMatch(/declare class TableCrafter/);
  });

  test('exports as a CommonJS module (export = ...) and as a namespace', () => {
    const contents = fs.readFileSync(DTS_PATH, 'utf8');
    expect(contents).toMatch(/export = TableCrafter/);
    expect(contents).toMatch(/export as namespace TableCrafter/);
  });

  test('declares constructor (container, config?)', () => {
    const contents = fs.readFileSync(DTS_PATH, 'utf8');
    expect(contents).toMatch(/constructor\(container:[\s\S]*?config\?:[\s\S]*?TableConfig/);
  });
});

describe('package.json: types metadata', () => {
  let pkg;
  beforeAll(() => { pkg = JSON.parse(fs.readFileSync(PKG_PATH, 'utf8')); });

  test('top-level "types" field points at the .d.ts', () => {
    expect(pkg.types).toBe('src/tablecrafter.d.ts');
  });

  test('exports map declares a "types" entry alongside default', () => {
    expect(pkg.exports).toBeDefined();
    expect(pkg.exports['.']).toBeDefined();
    expect(pkg.exports['.'].types).toBe('./src/tablecrafter.d.ts');
  });
});
