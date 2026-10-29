const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
process.env.TZ = 'Pacific/Honolulu';
let checks = 0;
function verify(ok, label) { assert.ok(ok, label); checks++; }
const elements = new Map();
function element(id = '') {
  return { id, value: '', textContent: '', disabled: false, hidden: false, checked: false, required: false,
    listeners: {}, children: [], classList: { toggle() {} },
    addEventListener(event, callback) { this.listeners[event] = callback; },
    appendChild(child) { this.children.push(child); }, focus() {}, click() {}, reset() {},
    get innerHTML() { return this._html ?? this.textContent.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;'); },
    set innerHTML(value) { this._html = value; this.children = []; } };
}
const get = id => { if (!elements.has(id)) elements.set(id, element(id)); return elements.get(id); };
get('visitorType').value = 'registered';
const initial = { records: [], date: '2026-10-27', serverToday: '2026-10-27', csrfToken: 'csrf', formToken: 'form', summary: { visits: 0, registered_students: 0, walk_in_visits: 0 }, today: { visits: 0 }, settings: { daily_visit_limit: null } };
let next = structuredClone(initial), fail = false;
const requests = [], blobs = [];
const context = vm.createContext({ document: {getElementById:get,createElement:()=>element(),querySelector:()=>element()},
  window: { location: {} }, setTimeout: callback => callback(), URLSearchParams, URL: {createObjectURL(blob) { blobs.push(blob); return 'blob:test'; },revokeObjectURL(){}}, Blob,
  fetch: async (url, options) => { requests.push([url,options]); return {ok:!fail,json:async()=>url.includes('admin-session')?{loggedIn:true,username:'Test'}:structuredClone(next)}; } });
let source = fs.readFileSync('js/attendance.js','utf8');
source = source.replace(/\}\)\(\);\s*$/, 'window.testAttendance = {escapeHtml,csvCell,limitText,loadAttendance,request};})();');
vm.runInContext(source, context);
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  await tick();
  const api = context.window.testAttendance;
  verify(get('attendanceDate').value === '2026-10-27', 'server date used even on Honolulu device');
  verify(!elements.has('insideTotal') && !elements.has('departureConfirmation'), 'Time In page never initializes retired departure controls');
  verify(get('studentAccount').required && !get('studentName').required, 'registered selection required by default');
  verify(get('manualFields').hidden, 'manual fields hidden by default');
  get('studentAccount').value='17'; get('studentAccount').selectedIndex=0;
  get('studentAccount').options=[{textContent:'Same Name · STUDENT-17 · Grade 10'}];
  get('studentAccount').listeners.change();
  verify(get('selectedStudent').textContent.includes('STUDENT-17'), 'full selected identity shown outside the narrow native select');
  get('recordButton').disabled=true;
  const beforeDuplicate=requests.length;
  await get('attendanceForm').listeners.submit({preventDefault(){}});
  verify(requests.length===beforeDuplicate, 'disabled submit prevents duplicate in-flight requests');
  get('recordButton').disabled=false;
  get('visitorType').value = 'manual'; get('visitorType').listeners.change();
  verify(get('studentName').required && get('yearLevel').required && !get('studentSection').required, 'walk-in name/type required; purpose remains optional');
  verify(!get('studentAccount').required && get('studentLookup').hidden, 'hidden registered controls do not block manual validation');
  verify(api.escapeHtml('<img onerror="x">').includes('&lt;img'), 'visitor HTML escaped before table rendering');
  for (const text of ['=SUM(1,2)','+cmd','-1+2','@SUM(A1)','  =1']) verify(api.csvCell(text).startsWith('"\''), 'spreadsheet formula prefix neutralized');
  verify(api.csvCell('a,"b"') === '"a,""b"""', 'CSV quotes and commas escaped');
  verify(api.csvCell(null) === '""', 'missing CSV values safe');
  verify(api.limitText(2,null).includes('unlimited'), 'unlimited limit label');
  verify(api.limitText(5,4).includes('0 remaining'), 'remaining count cannot become negative');
  next={...structuredClone(initial), records:[{id:7,user_id:5,student_name:'<script>alert(1)</script>',student_identifier:'TEST-1',year_section_snapshot:'Grade 10',tracks_departure:1,time_in:'2026-10-27 09:00:00',time_out:null,duration_seconds:60}],settings:{daily_visit_limit:5},summary:{visits:4,registered_students:2,walk_in_visits:1},today:{visits:4}};
  await api.loadAttendance(true);
  verify(requests.at(-1)[0].startsWith('backend/attendance-data.php?search='), 'query URL retains .php before query string');
  verify(!get('attendanceBody').innerHTML.includes('<script>') && get('attendanceBody').innerHTML.includes('&lt;script&gt;'), 'malicious visitor name remains escaped table text');
  verify(get('visitTotal').textContent===4 && get('studentTotal').textContent===2, 'daily visits and unique people remain distinct');
  verify(!get('attendanceBody').innerHTML.includes('data-time-out') && !get('attendanceBody').innerHTML.includes('so far'), 'legacy tracked records never restore departure UI');
  verify(get('dailyLimit').value===5, 'saved settings populate controls');
  verify(get('recordCount').textContent.includes('2026-10-27'), 'records explain their selected Time In date');
  await get('exportAttendance').listeners.click();
  const csv=await blobs[0].text();verify(csv.includes('Time In (PHT)') && csv.includes('TEST-1') && !csv.includes('Time Out') && !csv.includes('Duration'), 'filtered export includes arrival details without departure fields');
  await api.request('attendance-settings',{daily_visit_limit:5});
  verify(requests.at(-1)[1].headers['X-CSRF-Token']==='csrf' && requests.at(-1)[1].method==='POST', 'write uses shared CSRF and POST');
  next={error:'This is another visit.',code:'repeat_visit_confirmation_required'};fail=true;
  await get('attendanceForm').listeners.submit({preventDefault(){}});
  verify(!get('repeatLabel').hidden && get('formMessage').textContent==='This is another visit.', 'repeat response exposes explicit confirmation without silently saving');
  verify(!get('recordButton').disabled, 'failed save restores submit control');
  await api.loadAttendance();
  verify(get('exportAttendance').disabled && get('visitTotal').textContent==='—', 'load failure clears export and stale summary counts');
  verify(get('attendanceBody').innerHTML.includes('Could not load attendance'), 'load failure offers visible retry guidance');
  const waiting=[];
  context.fetch=async()=>({ok:true,json:()=>new Promise(resolve=>waiting.push(resolve))});
  const earlier=api.loadAttendance(), later=api.loadAttendance();
  await tick();
  waiting[1]({...structuredClone(initial),summary:{visits:8,registered_students:2,walk_in_visits:1}});
  await later;
  waiting[0]({...structuredClone(initial),summary:{visits:1,registered_students:1,walk_in_visits:0}});
  await earlier;
  verify(get('visitTotal').textContent===8, 'late response cannot overwrite a newer filter result');
  console.log(`Completed ${checks} Phase 5 JavaScript checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
