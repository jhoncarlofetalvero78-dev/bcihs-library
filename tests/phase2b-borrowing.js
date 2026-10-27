const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
let checks = 0;
function verify(condition, label) { assert.ok(condition, label); checks++; }
function page(script) {
  const elements = new Map();
  const requests = [];
  function element(id) {
    if (!elements.has(id)) elements.set(id, {value:'', textContent:'', innerHTML:'', style:{}, hidden:false,
      addEventListener(){}, setAttribute(){}, getAttribute(){return '';}, focus(){}, reset(){},
      classList:{add(){},remove(){},contains(){return false;}}, replaceChildren(){}, appendChild(){}});
    return elements.get(id);
  }
  const context = vm.createContext({
    window:{location:{href:'http://localhost:8888/bcihs-library/browse-books.html',search:''}},
    document:{getElementById:element, querySelectorAll(){return [];}, addEventListener(){}, createElement(){return {
      set textContent(value){this.innerHTML=String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}, innerHTML:''};}},
    fetch:(url,options)=>{requests.push({url,options});return new Promise(()=>{});},
    confirm:()=>true, setTimeout:()=>1,clearTimeout(){},URL,URLSearchParams,Blob,console,
  });
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/borrowing-status.js'),'utf8'),context);
  context.borrowing=context.window.borrowing;
  if(script)vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/'+script),'utf8'),context);
  return {run:code=>vm.runInContext(code,context),elements,requests,context};
}
const shared=page();
verify(shared.run("borrowing.label('approved')")==='Checked Out','legacy approvals are actual old loans');
verify(shared.run("borrowing.label('awaiting_pickup')")==='Approved / Awaiting Pickup','new approvals have an explicit pickup label');
verify(!shared.run("borrowing.isCheckedOut('awaiting_pickup')"),'a new approval is not checked out');
verify(shared.run("borrowing.isCheckedOut('checked_out')"),'checkout is an outstanding loan');
verify(shared.run("borrowing.loanStart({status:'awaiting_pickup',approved_at:'2026-10-01 09:00:00'})")===null,'approval alone never supplies a checkout date');
verify(shared.run("borrowing.loanStart({status:'approved',approved_at:'2020-01-01 09:00:00'})")==='2020-01-01 09:00:00','legacy checkout-date fallback preserves old dates');
verify(shared.run("borrowing.loanStart({status:'checked_out',approved_at:'2026-10-01',checked_out_at:'2026-10-27'})")==='2026-10-27','actual checkout takes priority');
verify(shared.run("borrowing.date('2026-10-27 12:00:00').toISOString()")==='2026-10-27T04:00:00.000Z','API dates use Philippine time consistently');
verify(shared.run('borrowing.requestable({total_copies:3,borrowed_copies:1,requestable_copies:1})')===1,'catalog uses server allocation counts');
const staff=page('borrow-requests.js');
const base={id:1,student_name:'Local test student',book_title:'Test book',requested_at:'2026-10-27 09:00:00'};
for(const [status,expected,excluded] of [
  ['pending',['Approve','Reject'],['Confirm Checkout','Confirm Return']],
  ['awaiting_pickup',['Confirm Checkout','Reject'],['Approve','Confirm Return']],
  ['checked_out',['Confirm Return'],['Approve','Reject','Confirm Checkout']],
  ['approved',['Confirm Return'],['Approve','Reject','Confirm Checkout']],
  ['returned',[],['Approve','Reject','Confirm Checkout','Confirm Return']],
  ['rejected',[],['Approve','Reject','Confirm Checkout','Confirm Return']],
]){
  staff.run('renderRows(['+JSON.stringify({...base,status})+'])');
  const html=staff.elements.get('requestsBody').innerHTML;
  verify(html.includes('Details</button>'),status+' retains history details');
  for(const label of expected)verify(html.includes('>'+label+'</button>'),status+' shows '+label);
  for(const label of excluded)verify(!html.includes('>'+label+'</button>'),status+' hides '+label);
}
staff.run('allRequests = '+JSON.stringify([{...base,status:'awaiting_pickup'}])+"; csrfToken='test-token'; window.act(1,'checkout');");
const checkout=staff.requests.find(item=>item.url==='backend/borrow-request-action.php');
verify(checkout.options.headers['X-CSRF-Token']==='test-token','librarian action includes the session token');
verify(JSON.parse(checkout.options.body).action==='checkout','physical checkout is an explicit action');
const catalog=page('browse-books.js');
for(const [status,label] of [['pending','Request Pending'],['awaiting_pickup','Approved — Awaiting Pickup'],['checked_out','Already Borrowed'],['approved','Already Borrowed']]){
  catalog.run('myRequestByBook = {1:'+JSON.stringify(status)+'}');
  const button=catalog.run('buttonFor({id:1,total_copies:1,borrowed_copies:0,requestable_copies:0})');
  verify(button.includes(label) && button.includes('disabled'), 'student sees '+label+' without an automatic action');
}
catalog.run('myRequestByBook={};');
verify(catalog.run('buttonFor({id:1,total_copies:1,borrowed_copies:0,requestable_copies:0})').includes('Reserve'),'a physically present but held copy is not offered for another borrow request');
catalog.run("myReservationsByBook={1:{status:'fulfilled'}}");
verify(catalog.run('buttonFor({id:1,total_copies:2,borrowed_copies:1,requestable_copies:1})').includes('Checked Out'),'reservation borrowers cannot request the same title again');
verify(!catalog.requests.some(item=>/borrow-request-process|reservation-process/.test(item.url)),'opening the QR/catalog page performs no automatic borrowing mutation');
const transactions=page('transactions.js');
verify(!transactions.run('durationFor('+JSON.stringify({...base,status:'awaiting_pickup',approved_at:'2026-10-01 09:00:00'})+')').includes('ongoing'),'awaiting pickup has no running loan duration');
verify(transactions.run('durationFor('+JSON.stringify({...base,status:'checked_out',approved_at:'2026-10-01 09:00:00',checked_out_at:'2026-10-27 09:00:00'})+')').includes('ongoing'),'duration begins only at checkout');
console.log('Completed '+checks+' Phase 2B JavaScript checks.');
