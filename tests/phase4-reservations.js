const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
process.env.TZ = 'Pacific/Honolulu';
let checks = 0;
function verify(ok, message) { assert.ok(ok, message); checks++; }
const context = vm.createContext({window:{}, Intl, Date});
vm.runInContext(fs.readFileSync('js/borrowing-status.js','utf8'), context);
context.borrowing = context.window.borrowing;
vm.runInContext(fs.readFileSync('js/reservation-scheduling.js','utf8'), context);
context.reservationScheduling = context.window.reservationScheduling;
const picker = context.reservationScheduling;
const now = Date.parse('2026-10-28T08:00:00+08:00');
const data = {settings:{daily_limit:null,slot_limit:null},days:{},slots:{}};
verify(picker.slots().length === 19, 'original half-hour intervals from 07:00 through 16:00 retained');
verify(picker.slots()[0] === '07:00' && picker.slots().at(-1) === '16:00', 'operating-hour boundaries retained');
verify(/^\d{4}-\d{2}-\d{2}$/.test(picker.today()), 'calendar date key is stable Philippine ISO date');
verify(picker.slotAvailable(data,'2026-10-28','09:00',now), 'future Philippine weekday slot selectable');
verify(!picker.slotAvailable(data,'2026-10-28','07:30',now), 'past Philippine time disabled despite different device timezone');
verify(!picker.slotAvailable(data,'2026-10-28','16:30',now), 'after-closing slot rejected');
verify(!picker.slotAvailable(data,'2026-10-28','09:15',now), 'non-half-hour slot rejected');
verify(!picker.slotAvailable(data,'2026-10-31','09:00',now), 'Saturday closed');
verify(!picker.slotAvailable(data,'2026-11-01','09:00',now), 'Sunday closed');
verify(picker.format('legacy date') === 'legacy date', 'invalid historical dates remain readable without crashing the view');
verify(picker.format('2026-10-28 09:00:00').includes('9:00'), 'display time remains Philippine time on Honolulu device');
data.settings.slot_limit = 1;data.slots['2026-10-28 09:00:00'] = 1;
verify(!picker.slotAvailable(data,'2026-10-28','09:00',now), 'full half-hour slot disabled');
verify(picker.slotAvailable(data,'2026-10-28','09:30',now), 'another slot remains available');
data.settings.daily_limit = 2;data.days['2026-10-28'] = 2;
verify(!picker.slotAvailable(data,'2026-10-28','09:30',now), 'full pickup date disables remaining slots');
data.settings.daily_limit = null;data.settings.slot_limit = null;
verify(picker.slotAvailable(data,'2026-10-28','09:00',now), 'unconfigured limits preserve unlimited scheduling');
const adminSource = fs.readFileSync('js/reservations.js','utf8');
const actions = adminSource.slice(adminSource.indexOf('  function reservationActions('),adminSource.indexOf('  function renderRows('));
vm.runInContext(actions,context);
for (const [status, expected, excluded] of [
  ['waiting',['Schedule','Mark Ready','Confirm Checkout','Cancel'],['Confirm Return']],
  ['ready',['Confirm Checkout','Cancel'],['Schedule','Mark Ready','Confirm Return']],
  ['fulfilled',['Confirm Return'],['Schedule','Mark Ready','Confirm Checkout','Cancel']],
  ['returned',[],['Schedule','Mark Ready','Confirm Checkout','Cancel','Confirm Return']],
  ['cancelled',[],['Schedule','Mark Ready','Confirm Checkout','Cancel','Confirm Return']]
]) {
  const html = context.reservationActions({id:1,status,pickup_at:'2026-10-28 09:00:00',requestable_copies:1});
  verify(html.includes('Details</button>'), status+' always retains details');
  for (const label of expected) verify(html.includes('>'+label+'</button>'), status+' shows '+label);
  for (const label of excluded) verify(!html.includes('>'+label+'</button>'), status+' hides '+label);
}
verify(context.reservationActions({id:1,status:'waiting',pickup_at:null,requestable_copies:0}).includes('disabled'), 'no stock/schedule disables Mark Ready');
verify(/<button[^>]*disabled[^>]*>Confirm Checkout/.test(context.reservationActions({id:1,status:'waiting',pickup_at:null,requestable_copies:0})), 'waiting checkout disabled without unallocated stock');
verify(/<button[^>]*disabled[^>]*>Confirm Checkout/.test(context.reservationActions({id:1,status:'ready',pickup_at:'2026-10-28 09:00:00',available_copies:0})), 'ready checkout disabled without a physical copy');
console.log('Completed '+checks+' Phase 4 JavaScript checks.');
