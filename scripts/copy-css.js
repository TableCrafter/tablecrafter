const fs = require('fs');
const path = require('path');

const src = path.join(__dirname, '..', 'src', 'tablecrafter.css');
const dest = path.join(__dirname, '..', 'dist', 'tablecrafter.css');

if (fs.existsSync(src)) {
  fs.copyFileSync(src, dest);
  const size = (fs.statSync(dest).size / 1024).toFixed(1);
  console.log(`CSS → dist/tablecrafter.css (${size} KB)`);
} else {
  console.warn('No CSS source found at', src);
}
