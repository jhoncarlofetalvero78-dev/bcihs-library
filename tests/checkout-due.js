const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
let checks=0;
const verify=(ok,label)=>{assert.ok(ok,label);checks++;};
function harness(){
  const dialogs=[],requests=[];
  function control(id){return {value:id==='checkoutDueMode'?'default':id==='checkoutDays'?'3':'',textContent:'',hidden:false,disabled:id==='checkoutDueConfirm',listeners:{},options:[{textContent:''}],focus(){},addEventListener(event,callback){this.listeners[event]=callback;}};}
  const context=vm.createContext({
    document:{activeElement:null,body:{append(dialog){dialogs.push(dialog);}},createElement(){
      const controls=new Map();return {innerHTML:'',closed:false,attributes:{},listeners:{},setAttribute(key,value){this.attributes[key]=value;},
        querySelector(selector){if(!controls.has(selector))controls.set(selector,control(selector.slice(1)));return controls.get(selector);},
        addEventListener(event,callback){this.listeners[event]=callback;},showModal(){},close(){this.closed=true;},remove(){}};
    }},window:{},fetch:async(url,options)=>{requests.push([url,options]);return context.respond(url,options);}
  });
  context.respond=async(url,options)=>({ok:true,json:async()=>url.includes('borrowing-policy')?{days:14,server_date:'2026-10-29',max_date:'2027-10-29'}:
    {due_at:JSON.parse(options.body).due_mode==='days'?'2026-11-01 09:00:00':'2026-11-12 09:00:00',due_preview_token:'synthetic-preview'}});
  vm.runInContext(fs.readFileSync('js/checkout-due.js','utf8'),context);
  return {context,dialogs,requests};
}
const tick=()=>new Promise(setImmediate);
(async()=>{
  const ui=harness();
  const waiting=ui.context.window.checkoutDue.choose({source:'borrow',id:8,csrfToken:'csrf',label:'Borrower <script> — Book'});
  await tick();
  const dialog=ui.dialogs[0],el=id=>dialog.querySelector('#'+id);
  verify(el('checkoutDueBorrower').textContent==='Borrower <script> — Book' && !dialog.innerHTML.includes('Borrower <script>'),'borrower/book identity is text, not generated markup');
  verify(el('checkoutDueMode').options[0].textContent.includes('14 calendar days'),'current default comes from the server');
  verify(el('checkoutDueResult').textContent==='Due: 2026-11-12 09:00:00 PHT','exact server deadline shown before confirmation');
  verify(ui.requests.every(([url])=>!url.includes('borrow-request-action')&&!url.includes('reservation-action')),'preview cannot execute a checkout');
  verify(await ui.context.window.checkoutDue.choose({})===null && ui.dialogs.length===1,'a second click cannot open another checkout confirmation');
  el('checkoutDueMode').value='days';el('checkoutDays').value='0';
  const before=ui.requests.length;await el('checkoutDueMode').listeners.change();
  verify(el('checkoutDueConfirm').disabled && ui.requests.length===before,'invalid custom period blocks confirmation before requesting a preview');
  el('checkoutDays').value='3';await el('checkoutDays').listeners.input();
  const submitted=ui.requests.at(-1);
  verify(JSON.parse(submitted[1].body).days==='3' && JSON.parse(submitted[1].body).source==='borrow' && submitted[1].headers['X-CSRF-Token']==='csrf','three-day preview is staff/record/CSRF bound');
  verify(el('checkoutDueResult').textContent==='Due: 2026-11-01 09:00:00 PHT' && !el('checkoutDueConfirm').disabled,'custom deadline is reviewed before submit');
  el('checkoutDueConfirm').listeners.click();
  const selection=await waiting;
  verify(selection.due_preview_token==='synthetic-preview' && dialog.closed,'confirmation returns only the approved preview to the calling workflow');
  const cancelled=ui.context.window.checkoutDue.choose({source:'reservation',id:9,csrfToken:'csrf',label:'Reservation'});await tick();
  ui.dialogs[1].querySelector('#checkoutDueCancel').listeners.click();verify(await cancelled===null,'Cancel produces no checkout selection');
  const failed=harness();failed.context.respond=async()=>({ok:false,json:async()=>({error:'Policy unavailable'})});
  const failedSelection=failed.context.window.checkoutDue.choose({source:'borrow',id:8,csrfToken:'csrf',label:'Book'});await tick();
  const failedDialog=failed.dialogs[0];verify(failedDialog.querySelector('#checkoutDueResult').textContent==='Policy unavailable' && failedDialog.querySelector('#checkoutDueConfirm').disabled,'policy failure cannot fall back to an unreviewed date');
  failedDialog.listeners.cancel({preventDefault(){}});verify(await failedSelection===null,'Escape cancels safely');
  const race=harness();let releaseOld;
  race.context.respond=async(url,options)=>({ok:true,json:async()=>{
    if(url.includes('borrowing-policy'))return{days:14};
    if(JSON.parse(options.body).due_mode==='default')return await new Promise(resolve=>{releaseOld=resolve;});
    return{due_at:'2026-11-01 09:00:00',due_preview_token:'current-preview'};
  }});
  const raced=race.context.window.checkoutDue.choose({source:'borrow',id:8,csrfToken:'csrf',label:'Book'});await tick();
  const raceDialog=race.dialogs[0],raceEl=id=>raceDialog.querySelector('#'+id);
  raceEl('checkoutDueMode').value='days';await raceEl('checkoutDueMode').listeners.change();
  releaseOld({due_at:'2026-11-12 09:00:00',due_preview_token:'obsolete-preview'});await tick();
  verify(raceEl('checkoutDueResult').textContent==='Due: 2026-11-01 09:00:00 PHT','a slow default preview cannot replace a newer custom choice');
  raceEl('checkoutDueConfirm').listeners.click();verify((await raced).due_preview_token==='current-preview','only the current preview can be confirmed');
  for(const file of ['borrow-requests.html','reservations.html','pickup-verification.html']){
    const html=fs.readFileSync(file,'utf8');verify(html.includes('js/checkout-due.js')&&html.includes('css/checkout-due.css'),'all staff checkout surfaces load the same due-date controls');
  }
  console.log(`Completed ${checks} checkout due-date JavaScript checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
