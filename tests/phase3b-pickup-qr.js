// Node.js only; no browser automation or additional test packages.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let checks = 0;
function verify(ok, label) { assert.ok(ok,label); checks++; }
const token = 'a'.repeat(64);
const url = 'http://192.0.2.10:8888/bcihs-library/pickup-verification.html?token=' + token;
function harness(file, search, allowProfileHtml=false) {
  const elements = new Map(), calls = [], draws = [], events = {};
  function node(tag='div') {
    return {tag, children:[], attributes:{}, listeners:{}, dataset:{}, hidden:false, disabled:false, textContent:'',
      style:{}, classList:{add(){},remove(){}}, setAttribute(k,v){this.attributes[k]=v;},
      append(...nodes){this.children.push(...nodes);}, appendChild(child){this.children.push(child);},
      replaceChildren(...nodes){this.children=nodes;}, focus(){},
      getContext(){return {fillRect(...rect){draws.push(rect);},fillStyle:''};},
      addEventListener(event,handler){this.listeners[event]=handler;},
      get innerHTML(){return this.html ?? String(this.textContent).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');},
      set innerHTML(value){if(!allowProfileHtml)throw new Error('Pickup rendering must use safe DOM APIs.');this.html=value;}
    };
  }
  const get=id=>{if(!elements.has(id))elements.set(id,node());return elements.get(id);};
  const context=vm.createContext({window:{location:{search,href:''},addEventListener:(event,callback)=>events[event]=callback},document:{getElementById:get,createElement:node,hidden:false,
    addEventListener:(event,callback)=>events['document:'+event]=callback,
    querySelectorAll(selector){
      if(selector!=='.tab-btn')return [];
      return ['personalInfo','borrowingHistory','reservationHistory'].map(name=>{const tab=get('tab'+name[0].toUpperCase()+name.slice(1));tab.dataset.tab=name;return tab;});
    }},
    localStorage:{getItem:()=>null,setItem(){},removeItem(){}},
    URLSearchParams,URL,Date,console,
    fetch:(...args)=>{calls.push(args);return context.respond(...args);},
    respond:()=>new Promise(()=>{})});
  if(allowProfileHtml){vm.runInContext(fs.readFileSync('js/borrowing-status.js','utf8'),context);context.borrowing=context.window.borrowing;}
  vm.runInContext(fs.readFileSync('js/vendor/qrcodegen.js','utf8'),context);
  vm.runInContext(fs.readFileSync(file,'utf8').replace(/\nloadPickup(?:Codes)?\(\);\s*$/,''),context);
  return {context,get,calls,draws,events};
}
const request={id:8,status:'awaiting_pickup',full_name:'Student <img onerror=x>',student_id:'STU8',year_section:'Grade 10 — Peace',title:'Book " café & <script>',author:'Author',call_number:'500 A1',requested_at:'2026-10-27 08:00:00',approved_at:'2026-10-27 09:00:00',account_status:'accepted'};
const data={request,eligible:true,csrfToken:'test-csrf'};
function response(body,status=200){return {ok:status>=200&&status<300,status,json:async()=>body};}
async function main(){
  const ui=harness('js/pickup-verification.js','?token='+token);
  vm.runInContext('window.test={loadPickup,renderPickup,pickupDate}',ui.context);const api=ui.context.window.test;
  ui.context.respond=async()=>response(data);
  await api.loadPickup();
  verify(ui.calls.length===1 && !ui.calls[0][1].method,'opening verification performs GET only');
  verify(!ui.get('checkoutButton').hidden && !ui.get('pickupDetails').hidden,'eligible request displays handover action');
  verify(ui.get('pickupFields').children.some(n=>n.textContent===request.full_name),'student text remains literal without HTML interpretation');
  verify(ui.get('pickupFields').children.some(n=>n.textContent===request.title),'book title remains literal Unicode text');
  await ui.get('checkoutButton').listeners.click();
  verify(!ui.get('checkoutConfirmation').hidden && ui.calls.length===1,'first click opens confirmation without checkout');
  ui.get('cancelHandover').listeners.click();verify(ui.get('checkoutConfirmation').hidden,'cancel preserves the loan');
  let posted,release;
  ui.context.respond=(endpoint,options)=>{
    if(options.method==='POST') {posted=options;return new Promise(resolve=>release=resolve);}
    return Promise.resolve(response({...data,request:{...request,status:'checked_out'},eligible:false}));
  };
  const saving=ui.get('confirmHandover').listeners.click();
  const count=ui.calls.length;
  await ui.get('confirmHandover').listeners.click();
  verify(ui.calls.length===count && ui.get('confirmHandover').disabled,'in-flight checkout suppresses repeated submission');
  verify(JSON.parse(posted.body).pickup_token===token && JSON.parse(posted.body).action==='checkout','checkout uses the existing endpoint and exact token');
  verify(posted.headers['X-CSRF-Token']==='test-csrf','checkout transmits session CSRF');
  release(response({success:true}));await saving;
  verify(ui.get('checkoutButton').hidden && ui.get('pickupMessage').textContent.includes('Checkout confirmed'),'success reloads used status and hides checkout');
  ui.context.respond=async()=>response({error:'Only library staff'},403);await api.loadPickup();
  verify(ui.get('pickupDetails').hidden && ui.get('pickupMessage').textContent==='Only library staff','unauthorized access hides previous details');
  ui.context.respond=async()=>response({error:'not_logged_in'},401);await api.loadPickup();
  verify(ui.context.window.location.href==='admin-login.html?pickup='+token,'signed-out verification preserves only validated QR token');
  ui.context.respond=async()=>{throw new Error('offline');};await api.loadPickup();
  verify(ui.get('pickupDetails').hidden && ui.get('pickupMessage').textContent==='offline','network failure does not leave checkout active');
  api.renderPickup(data);
  let posts=0;
  ui.context.respond=async(endpoint,options)=>{
    if(options.method==='POST'){posts++;return response({error:'Already checked out'},409);}
    return response({...data,eligible:false,request:{...request,status:'checked_out'}});
  };
  await ui.get('confirmHandover').listeners.click();
  verify(posts===1 && ui.get('checkoutButton').hidden && ui.get('pickupMessage').textContent.includes('Already checked out'),'conflict rechecks current status');
  const invalid=harness('js/pickup-verification.js','?token=https://evil.invalid/');vm.runInContext('window.test={loadPickup}',invalid.context);await invalid.context.window.test.loadPickup();
  verify(invalid.calls.length===0 && invalid.get('pickupMessage').textContent.includes('Invalid'),'invalid token never enters a fetch or redirect');
  const newline=harness('js/pickup-verification.js','?token='+token+'%0A');vm.runInContext('window.test={loadPickup}',newline.context);await newline.context.window.test.loadPickup();
  verify(newline.calls.length===0,'trailing newline cannot bypass exact token length validation');
  const student=harness('js/pickup-qr.js','');vm.runInContext('window.test={loadPickupCodes}',student.context);
  student.context.respond=async(endpoint)=>response(endpoint.includes('my-pickup')?{requests:[request],csrfToken:'owner-csrf'}:{url});
  await student.context.window.test.loadPickupCodes();
  const card=student.get('pickupCodes').children[0];
  verify(card.children[0].textContent===request.title,'student card uses safe title text');
  verify(student.calls.length===1,'loading student list does not automatically issue a token');
  await card.children[2].listeners.click();
  const canvas=card.children[4].children[0],link=card.children[4].children[1];
  verify(canvas.tag==='canvas' && canvas.width===canvas.height && student.draws.length>100,'QR is rendered locally as a square canvas');
  verify(link.href===url && canvas.attributes['aria-label']==='Pickup QR for '+request.title,'QR link and accessible label preserve correct values');
  verify(student.calls.every(([endpoint])=>!/^https?:/.test(endpoint)),'QR rendering contacts no remote QR service');
  verify(JSON.parse(student.calls[1][1].body).id===request.id && student.calls[1][1].headers['X-CSRF-Token']==='owner-csrf','owner QR issuance uses exact request and CSRF');
  student.context.respond=async()=>response({error:'No longer awaiting pickup'},409);
  await card.children[2].listeners.click();
  verify(card.children[4].children.length===0 && card.children[3].textContent==='No longer awaiting pickup','used request removes displayed QR on recheck');
  student.context.respond=async()=>response({requests:[],csrfToken:'owner-csrf'});await student.context.window.test.loadPickupCodes();
  verify(student.get('pickupCodes').textContent.includes('No approved requests'),'student empty state is clear');
  const profile=harness('js/profile.js','',true);
  vm.runInContext(fs.readFileSync('js/pickup-qr.js','utf8').replace(/\nloadPickupCodes\(\);\s*$/,''),profile.context);
  let approved=false;
  profile.context.respond=async(endpoint)=>{
    if(endpoint.includes('my-pickup'))return response({requests:approved?[request]:[],csrfToken:'owner-csrf'});
    if(endpoint.includes('borrowing-history'))return response({requests:[{...request,book_title:request.title,status:approved?'awaiting_pickup':'pending'}]});
    if(endpoint.includes('my-reservations'))return response({reservations:[]});
    return response({url});
  };
  profile.context.loadBorrowingHistory();await new Promise(setImmediate);
  verify(profile.get('pickupCodes').textContent.includes('No approved requests') && profile.get('historyContent').innerHTML.includes('Pending'),'pending history and pickup section initially agree');
  approved=true;profile.context.document.hidden=true;
  let before=profile.calls.length;profile.events['document:visibilitychange']();await new Promise(setImmediate);
  verify(profile.calls.length===before,'hidden page does not refresh borrowing data');
  profile.context.document.hidden=false;profile.events['document:visibilitychange']();await new Promise(setImmediate);
  verify(profile.get('pickupCodes').children.length===1 && profile.get('historyContent').innerHTML.includes('Approved / Awaiting Pickup'),'returning after approval refreshes history and pickup section together');
  const approvedCard=profile.get('pickupCodes').children[0];await approvedCard.children[2].listeners.click();
  profile.events['document:visibilitychange']();await new Promise(setImmediate);
  verify(profile.get('pickupCodes').children[0]===approvedCard && approvedCard.children[4].children[0].tag==='canvas','unchanged refresh preserves the displayed QR for phone scanning');
  before=profile.calls.length;profile.get('tabBorrowingHistory').listeners.click();await new Promise(setImmediate);
  verify(profile.calls.slice(before).some(([endpoint])=>endpoint.includes('borrowing-history')) && profile.calls.slice(before).some(([endpoint])=>endpoint.includes('my-pickup')),'opening Borrowing History refreshes both datasets');
  approved=false;profile.events['document:visibilitychange']();await new Promise(setImmediate);
  verify(!profile.get('pickupCodes').children.length,'ineligible request removes the old QR on refresh');
  const race=harness('js/pickup-qr.js','');vm.runInContext('window.test={loadPickupCodes}',race.context);
  let oldResponse;race.context.respond=()=>new Promise(resolve=>oldResponse=resolve);
  const oldLoad=race.context.window.test.loadPickupCodes();
  race.context.respond=async()=>response({requests:[request],csrfToken:'owner-csrf'});await race.context.window.test.loadPickupCodes();
  oldResponse(response({requests:[],csrfToken:'owner-csrf'}));await oldLoad;
  verify(race.get('pickupCodes').children.length===1,'an older empty response cannot overwrite a newer eligible request');
  race.context.respond=async()=>{throw new Error('offline');};await race.context.window.test.loadPickupCodes();
  verify(!race.get('pickupCodes').children.length && race.get('pickupCodes').textContent==='offline','failed refresh removes stale pickup actions');
  race.context.respond=async()=>response({requests:[request],csrfToken:'owner-csrf'});await race.context.window.test.loadPickupCodes();
  verify(race.get('pickupCodes').children.length===1,'successful retry restores pickup controls');
  for(const candidate of [token,'https://evil.invalid/',token+'\n']) {
    const login=harness('js/admin-login.js','?pickup='+encodeURIComponent(candidate));
    const valid=candidate===token;
    verify(login.get('adminLoginForm').children.some(n=>n.name==='pickup' && n.value===token)===valid,'staff login form retains only an exact valid token');
    login.context.respond=async()=>response({loggedIn:true});login.events.DOMContentLoaded();
    await new Promise(setImmediate);
    verify(login.context.window.location.href===(valid?'pickup-verification.html?token='+token:'admin-dashboard.html'),'existing staff session returns only to a fixed internal destination');
  }
  for(const [script,html] of [['js/pickup-verification.js','pickup-verification.html'],['js/pickup-qr.js','profile.html']]){
    const ids=new Set([...fs.readFileSync(html,'utf8').matchAll(/id="([^"]+)"/g)].map(m=>m[1]));
    for(const match of fs.readFileSync(script,'utf8').matchAll(/getElementById\('([^']+)'\)/g))verify(ids.has(match[1]),'referenced control exists: '+match[1]);
  }
  console.log(`Completed ${checks} Phase 3B JavaScript checks.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
