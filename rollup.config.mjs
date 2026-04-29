import commonjs from '@rollup/plugin-commonjs';
import resolve from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, 'package.json'), 'utf8'));

const banner = `/*!
 * TableCrafter v${pkg.version}
 * (c) ${new Date().getFullYear()} Fahad Murtaza
 * @license MIT
 */`;

const input = 'src/tablecrafter.js';

const plugins = [
  resolve({ browser: true }),
  commonjs(),
];

export default [
  // ESM — for bundlers (webpack, vite, rollup)
  {
    input,
    output: {
      file: 'dist/tablecrafter.esm.mjs',
      format: 'esm',
      banner,
      sourcemap: true,
    },
    plugins,
  },
  // CJS — for Node / older bundlers
  {
    input,
    output: {
      file: 'dist/tablecrafter.cjs.js',
      format: 'cjs',
      banner,
      sourcemap: true,
      exports: 'default',
    },
    plugins,
  },
  // UMD unminified — for download / debug
  {
    input,
    output: {
      file: 'dist/tablecrafter.umd.js',
      format: 'umd',
      name: 'TableCrafter',
      banner,
      sourcemap: true,
      exports: 'default',
    },
    plugins,
  },
  // UMD minified — for CDN (jsDelivr / unpkg)
  {
    input,
    output: {
      file: 'dist/tablecrafter.umd.min.js',
      format: 'umd',
      name: 'TableCrafter',
      banner,
      sourcemap: true,
      exports: 'default',
    },
    plugins: [
      ...plugins,
      terser({ format: { comments: /^!/ } }),
    ],
  },
];
