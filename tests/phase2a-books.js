// Run with Node.js; no packages are needed. Production still uses vanilla browser JS.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const elements = new Map();
let lastBlob;
const requestedUrls = [];
const documentEvents = {};
function element(id) {
  if (!elements.has(id)) {
    const classes = new Set();
    elements.set(id, {
      value: id === 'pageSize' ? '10' : '', style: {}, textContent: '', innerHTML: '',
      classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
      addEventListener() {}, focus() {}, click() {}, removeAttribute() {},
    });
  }
  return elements.get(id);
}
class TestURL extends URL {}
TestURL.createObjectURL = blob => { lastBlob = blob; return 'blob:test'; };
TestURL.revokeObjectURL = () => {};
const context = vm.createContext({
  document: { getElementById: element, addEventListener(event, handler) { documentEvents[event] = handler; }, createElement: () => element('download'), activeElement: null },
  window: { location: { href: 'http://localhost:8888/bcihs-library/manage-books.html' } },
  fetch: url => { requestedUrls.push(url); return new Promise(() => {}); },
  URL: TestURL, Blob, setTimeout: () => 1, clearTimeout() {}, console,
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/manage-books.js'), 'utf8'), context);
const run = source => vm.runInContext(source, context);
const fixture = Array.from({ length: 26 }, (_, index) => ({
  id: index + 1, title: index === 0 ? '=FORMULA,"quoted"' : `Test title ${index + 1}`, author: 'Test Author',
  category: index % 2 ? 'Science' : 'Filipiniana', total_copies: 3, borrowed_copies: index % 2 ? 3 : 0,
  ddc: '500', call_number: 'EXACT custom C948 2024', cutter_number: 'OLD', year_of_publication: '2024',
}));
run('allBooks = ' + JSON.stringify(fixture));
assert.equal(run('callNumberFor(allBooks[0])'), 'EXACT custom C948 2024');
assert.equal(run("callNumberFor({ddc:'001.3', cutter_number:'C948', year_of_publication:'2024'})"), '001.3 C948 2024');
assert.equal(run("callNumberFor({title:'Science', ddc:'500', year_of_publication:'2024'})"), '');
assert.equal(run("callNumberFor({title:'Science'})"), '');
run('applyFilter()');
assert.equal(element('pageInfo').textContent, 'Page 1 of 3');
assert.equal((element('booksBody').innerHTML.match(/<tr>/g) || []).length, 10);
run('currentPage = 3; applyFilter(false)');
assert.equal((element('booksBody').innerHTML.match(/<tr>/g) || []).length, 6);
element('categoryFilter').value = 'Science';
element('availabilityFilter').value = 'unavailable';
run('applyFilter()');
assert.equal(run('getFilteredBooks().length'), 13);
assert.equal(element('pageInfo').textContent, 'Page 1 of 2');
element('searchInput').value = 'title 12';
run('applyFilter()');
assert.equal(run('getFilteredBooks().length'), 1);
element('availabilityFilter').value = 'available';
run('applyFilter()');
assert.equal(run('getFilteredBooks().length'), 0);
assert.equal(element('exportBtn').disabled, true);
assert.match(element('booksBody').innerHTML, /No books match these filters/);
element('searchInput').value = ''; element('categoryFilter').value = ''; element('availabilityFilter').value = '';
run('applyFilter()');
assert.equal(run('getFilteredBooks().length'), 26);
assert.equal(element('pageInfo').textContent, 'Page 1 of 3');
for (const value of ['=SUM(A1)', ' +cmd', '-1+2', '@formula', '\ttext', '\rtext', '\ntext']) {
  assert.ok(run('csvCell(' + JSON.stringify(value) + ')').startsWith('"\''));
}
assert.equal(run('csvCell(\'a,"b"\')'), '"a,""b"""');
run('exportCsv()');
assert.equal(element('download').download.startsWith('books-'), true);
(async () => {
  const csv = await lastBlob.text();
  assert.equal(csv.split('\r\n').length, 27, 'all filtered records exported across pages');
  assert.equal(csv.includes('Cutter No.'), false);
  assert.ok(csv.includes('Classification Number (DDC)'));
  assert.ok(csv.includes('"\'=FORMULA,""quoted"""'));
  assert.equal(typeof context.window.openQrModal, 'undefined', 'catalog QR action is removed');
  assert.doesNotMatch(element('booksBody').innerHTML, /openQrModal|Show catalog QR|action-btn qr/);
  assert.match(element('booksBody').innerHTML, /Edit/);
  assert.match(element('booksBody').innerHTML, /Delete/);
  const markup = fs.readFileSync(path.join(__dirname, '../manage-books.html'), 'utf8');
  assert.doesNotMatch(markup, /qrOverlay|qrModalTitle|qrDownloadBtn|qrImage/);
  assert.equal(requestedUrls.some(url => /qrserver|create-qr-code/.test(url)), false);
  const controls = [{disabled:false,offsetParent:{},focus(){context.document.activeElement=this;}},
    {disabled:false,offsetParent:{},focus(){context.document.activeElement=this;}}];
  const modal = element('modalOverlay');
  modal.querySelectorAll = () => controls;
  modal.classList.add('show');
  context.document.activeElement = controls[1];
  let prevented = false;
  documentEvents.keydown({key:'Tab',shiftKey:false,preventDefault(){prevented=true;}});
  assert.ok(prevented && context.document.activeElement === controls[0], 'book modal retains forward focus trap');
  documentEvents.keydown({key:'Tab',shiftKey:true,preventDefault(){}});
  assert.equal(context.document.activeElement, controls[1], 'book modal retains backward focus trap');
  element('saveBtn').disabled = false;
  documentEvents.keydown({key:'Escape'});
  assert.equal(modal.classList.contains('show'), false, 'Escape still closes book editor');
  assert.equal(requestedUrls.some(url => /borrow-request|reservation-action/.test(url)), false);
  assert.equal(run('allBooks[0].borrowed_copies'), 0);
  console.log('PASS: exact and legacy Call Number handling; no guessed classification or incomplete automatic Call Number.');
  console.log('PASS: search, combined filters, empty state, clearing filters, and pagination.');
  console.log('PASS: CSV fields, escaping, formula protection, and export across all pages.');
  console.log('PASS: catalog QR controls and modal removed; Edit/Delete retained; no QR service calls or inventory mutation.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
