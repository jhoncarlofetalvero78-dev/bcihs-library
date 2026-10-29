const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
let checks = 0;
const verify = (ok, label) => { assert.ok(ok, label); checks++; };
const nodes = new Map();
function node() {
  return {textContent:'',checked:false,hidden:false,disabled:false,children:[],listeners:{},clicks:0,
    addEventListener(type, fn) { this.listeners[type] = fn; },
    replaceChildren() { this.children = []; }, appendChild(child) { this.children.push(child); },
    click() { this.clicks++; }};
}
const get = id => { if (!nodes.has(id)) nodes.set(id, node()); return nodes.get(id); };
const enabled = {permission:{available:true,allowed:false,version:1},can_manage:true,csrfToken:'synthetic-csrf',
  provider:'unisms',delivery_mode:'Disabled',configuration_message:'Simulation only.',audit:[]};
let response = enabled, calls = [], confirmed = true, confirms = 0, fail = false;
const context = vm.createContext({document:{getElementById:get,createElement:node},
  window:{libraryUI:{confirm:async()=>{confirms++;return confirmed;}}},
  fetch:async(url, options)=>{ calls.push({url,options});if(fail)throw new Error('offline');return {ok:true,json:async()=>response}; }});
const source = fs.readFileSync('js/sms-settings.js','utf8');
vm.runInContext(source, context);
const tick = () => new Promise(resolve => setImmediate(resolve));
const submit = () => get('smsPermissionForm').listeners.submit({preventDefault(){}});
async function main() {
  await tick();
  verify(get('smsPermissionLabel').textContent==='OFF','OFF clearly displayed');
  verify(!get('saveSmsPermission').hidden && !get('allowSmsNotifications').disabled,'administrator can edit');
  verify(get('smsSelectedProvider').textContent==='UniSMS','provider displayed without credentials');
  verify(get('smsEffectiveMode').textContent==='Disabled','effective delivery distinct from permission');
  get('allowSmsNotifications').checked=true;confirmed=false;calls=[];
  await submit();verify(calls.length===0 && !get('allowSmsNotifications').checked,'cancel confirmation sends no POST and restores checkbox');
  confirmed=true;response={...enabled,permission:{available:true,allowed:true,version:2},delivery_mode:'Simulation'};
  get('allowSmsNotifications').checked=true;calls=[];await submit();
  verify(calls.length===1 && calls[0].options.method==='POST','confirmation permits exactly one change request');
  const payload=JSON.parse(calls[0].options.body);
  verify(payload.allowed===true && payload.version===1 && payload.confirm_enable===true,'exact boolean, previous revision and confirmation sent');
  verify(calls[0].options.headers['X-CSRF-Token']==='synthetic-csrf','CSRF token included');
  verify(!calls[0].options.body.includes('live_enabled') && !calls[0].options.body.includes('provider'),'UI cannot set provider or live gates');
  verify(get('smsEffectiveMode').textContent==='Simulation','ON does not pretend to be Live');
  verify(get('refreshBtn').clicks===1,'saved permission reloads local logs');
  const before=confirms;response={...enabled,permission:{available:true,allowed:false,version:3}};
  get('allowSmsNotifications').checked=false;await submit();verify(confirms===before,'OFF does not require paid-delivery confirmation');
  response={...enabled,can_manage:false};await get('refreshSmsSettings').listeners.click();
  verify(get('allowSmsNotifications').disabled && get('saveSmsPermission').hidden,'librarian controls disabled and save hidden');
  calls=[];await submit();verify(calls.length===0,'librarian submit handler cannot mutate');
  response={...enabled,permission:{available:false,allowed:false,version:null}};await get('refreshSmsSettings').listeners.click();
  verify(get('allowSmsNotifications').disabled && get('saveSmsPermission').disabled,'missing storage fails closed in UI');
  verify(get('smsPermissionLabel').textContent.includes('setup required'),'missing setup explained');
  response={...enabled,audit:[{admin_id:'<img onerror=x>',previous_allowed:0,new_allowed:1,setting_version:2,changed_at:'2026-10-28 12:00:00'}]};
  await get('refreshSmsSettings').listeners.click();
  verify(get('smsSettingsAudit').children[0].textContent.includes('<img onerror=x>'),'audit rendered through inert textContent');
  fail=true;await get('refreshSmsSettings').listeners.click();
  verify(get('allowSmsNotifications').disabled && get('saveSmsPermission').disabled,'network error leaves changes blocked');
  verify(get('smsEffectiveMode').textContent==='Unknown' && get('smsConfigReadiness').textContent.includes('last saved server permission may still apply'),'network failure does not falsely claim server permission changed');
  verify(get('smsSettingsFeedback').textContent==='offline' && !get('refreshSmsSettings').disabled,'network error allows explicit refresh');
  fail=false;response=enabled;await get('refreshSmsSettings').listeners.click();
  // Duplicate submits cannot stack confirmation dialogs or HTTP changes.
  let resolveConfirmation;
  context.window.libraryUI.confirm=()=>new Promise(resolve=>{resolveConfirmation=resolve;});
  get('allowSmsNotifications').checked=true;calls=[];const pending=submit();await submit();
  verify(calls.length===0 && get('saveSmsPermission').disabled,'pending confirmation blocks repeated submit');
  resolveConfirmation(true);response={...enabled,permission:{available:true,allowed:true,version:2}};await pending;
  verify(calls.length===1,'repeated clicks result in one request');
  const html=fs.readFileSync('sms-logs.html','utf8'),ids=new Set([...html.matchAll(/id="([^"]+)"/g)].map(m=>m[1]));
  for(const match of source.matchAll(/getElementById\('([^']+)'\)/g))verify(ids.has(match[1]),'settings control exists: '+match[1]);
  verify(html.includes('aria-describedby="smsPermissionHelp"') && html.includes('aria-live="polite"'),'accessible explanation and live feedback');
  console.log(`Completed ${checks} SMS settings JavaScript checks.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
