const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '../demo-assets/staging-indicator.js'), 'utf8');
let ready;
let removed = 0;
let requests = 0;
const context = {
  document: {
    addEventListener: (_event, callback) => { ready = callback; },
    getElementById: id => id === 'stagingIndicator' ? {remove: () => removed++} : null
  },
  fetch: () => { requests++; throw new Error('No network request expected'); }
};
vm.runInNewContext(source, context);
ready();
if (removed !== 1 || requests !== 0) throw new Error('Legacy banner must be removed without a network request');
context.document.getElementById = () => null;
ready();
if (removed !== 1 || requests !== 0) throw new Error('Banner-free pages must remain untouched');
console.log('Passed staging banner cleanup and no-network checks.');
