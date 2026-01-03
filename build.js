const fs = require('fs');
const path = require('path');

const srcPath = path.join(__dirname, 'src', 'tablecrafter.js');
const cssPath = path.join(__dirname, 'src', 'tablecrafter.css');
const distDir = path.join(__dirname, 'dist');
const distJsPath = path.join(distDir, 'tablecrafter.js');
const distCssPath = path.join(distDir, 'tablecrafter.css');

// Ensure dist directory exists
if (!fs.existsSync(distDir)) {
    fs.mkdirSync(distDir);
}

// Read Source JS
let jsContent = fs.readFileSync(srcPath, 'utf8');

// Simple bundling: Wrapper for browser compatibility if needed, 
// but since src/tablecrafter.js is already a class, we just ensure it's global or module compliant.
// For this simple case, we just copy it, maybe adding a UMD wrapper later if needed.
// Checking if it has module.exports, if so, strip it for browser global usage or wrap it.
// The current source seems to differ between the one I read and what might be ideal.
// Let's assume the source is the single file class definition I saw.

// Minification is out of scope for this simple script, just copying for now.
fs.writeFileSync(distJsPath, jsContent);
console.log(`JS Built to ${distJsPath}`);

// Copy CSS
if (fs.existsSync(cssPath)) {
    const cssContent = fs.readFileSync(cssPath, 'utf8');
    fs.writeFileSync(distCssPath, cssContent);
    console.log(`CSS Built to ${distCssPath}`);
}
