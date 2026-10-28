const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');let checks=0;
function check(value,label){assert.ok(value,label);checks++;}
const parser=`import json,sys
from html.parser import HTMLParser
class P(HTMLParser):
 def __init__(self): super().__init__();self.tags=[]
 def handle_starttag(self,t,a): self.tags.append([t,dict(a)])
p=P();p.feed(sys.stdin.read());print(json.dumps(p.tags))`;
for(const file of ['browse-books','profile','admin-dashboard','manage-announcements','announcements','index']){
 const source=fs.readFileSync('js/'+file+'.js','utf8');const match=source.match(/function escapeHtml\([^)]*\)\s*\{[\s\S]*?\n\s*\}/);assert.ok(match,file+' escape function');
 const ctx=vm.createContext({document:{createElement(){return{textContent:'',get innerHTML(){return String(this.textContent).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');}};}}});
 vm.runInContext(match[0]+';this.escape=escapeHtml;',ctx);
 for(const value of ['Ordinary title',`Readers' "Corner" & café 日本語 <special>`,`" onerror="x" autofocus="`, `'><script>x</script><img src=x onerror=x>`]){
  const escaped=ctx.escape(value);
  for(const markup of [`<img src="safe.jpg" alt="${escaped}">`,`<input value="${escaped}">`]){
   const tags=JSON.parse(execFileSync('python3',['-c',parser],{input:markup,encoding:'utf8'}));
   check(tags.length===1 && Object.keys(tags[0][1]).every(key=>['src','alt','value'].includes(key)),file+' prevents attribute/element injection');
   check((tags[0][1].alt??tags[0][1].value)===value,file+' preserves literal Unicode text');
  }
 }
}
function node(tag='div') {return {tag,textContent:'',children:[],listeners:{},isConnected:true,append(...nodes){this.children.push(...nodes);},setAttribute(){},addEventListener(e,f){this.listeners[e]=f;},showModal(){this.open=true;},close(){this.open=false;},remove(){this.isConnected=false;},focus(){this.focused=true;}};}
const body=node('body'),previous=node('button');const context=vm.createContext({window:{},document:{activeElement:previous,body,readyState:'loading',addEventListener(){},createElement:node}});
vm.runInContext(fs.readFileSync('js/library-ui.js','utf8'),context);
function guestHarness(file, forms=[]) {
 const nodes=new Map(),calls=[];
 const get=id=>{if(!nodes.has(id)){const n=node();n.value='';n.style={};n.disabled=false;n.appendChild=child=>n.children.push(child);nodes.set(id,n);}return nodes.get(id);};
 const ctx=vm.createContext({window:{location:{search:'?role=teacher'}},URLSearchParams,setTimeout:()=>0,
  document:{getElementById:get,createElement:node,querySelector:()=>get('roleError'),querySelectorAll:selector=>selector==='[data-registration-form]'?forms:[]},
  fetch:(url,options)=>new Promise(resolve=>calls.push({url,options,resolve}))});
 vm.runInContext(fs.readFileSync(file,'utf8'),ctx);
 return {get,calls,ctx};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 const promise=context.window.libraryUI.confirm('<script>literal</script>',{danger:true,confirmLabel:'Delete book'});
 const dialog=body.children.at(-1),actions=dialog.children[2];
 check(dialog.open && dialog.children[1].textContent==='<script>literal</script>','confirmation uses literal text in a centered native dialog');
 check(actions.children[0].focused && actions.children[1].className==='ui-danger','safe initial focus and destructive styling');
 check(await context.window.libraryUI.confirm('duplicate')===false,'duplicate confirmation rejected');
 actions.children[0].listeners.click();check(await promise===false && previous.focused,'cancel restores focus and does not approve');
 const accepted=context.window.libraryUI.confirm('Checkout');body.children.at(-1).children[2].children[1].listeners.click();check(await accepted===true,'confirmation resolves approval once');
 const escape=context.window.libraryUI.confirm('Cancel');let prevented=false;body.children.at(-1).listeners.cancel({preventDefault(){prevented=true;}});check(await escape===false && prevented,'Escape cancels safely');
 for(const file of fs.readdirSync('js').filter(f=>f.endsWith('.js'))){const source=fs.readFileSync('js/'+file,'utf8');check(!/(?:\bwindow\.)?(?<![.\w])(?:alert|confirm|prompt)\s*\(/.test(source),file+' contains no native browser dialogs');}
 const registration=fs.readFileSync('select-role.html','utf8');check(!registration.includes('Generated automatically') && !fs.readFileSync('js/select-role.js','utf8').includes('generateId'),'school IDs are entered, never fabricated');
 check(!fs.readFileSync('js/profile.js','utf8').includes("{ key: 'email', label: 'Email Address', type: 'email' },"),'school-account email is not offered as a discarded edit');
 for (const file of ['manage-books','borrow-requests','reservations','transactions']) {
  for (const role of ['librarian','admin']) {
   const accountRole={textContent:'ADMINISTRATOR'},accountName={textContent:''};
   const header=fs.readFileSync('js/'+file+'.js','utf8').split('.catch(() => {});')[0]+'.catch(() => {});';
   const headerContext=vm.createContext({window:{location:{}},document:{getElementById:()=>accountName,querySelector:()=>accountRole},fetch:async()=>({json:async()=>({loggedIn:true,username:'Fixture staff',role})})});
   vm.runInContext(header,headerContext);await tick();
   check(accountRole.textContent===(role==='librarian'?'LIBRARIAN':'ADMINISTRATOR'),file+' header displays the authenticated role accurately');
  }
 }
 const feedbackDialog=node('dialog');feedbackDialog.querySelector=()=>feedbackDialog.children.find(n=>n.className==='dialog-feedback');
 context.document.querySelector=()=>feedbackDialog;
 context.window.libraryUI.dialogFeedback('<script>error stays text</script>');
 check(feedbackDialog.children.length===1 && feedbackDialog.children[0].textContent==='<script>error stays text</script>','active dialog receives literal error feedback in its own accessible region');
 context.window.libraryUI.dialogFeedback('Updated error');check(feedbackDialog.children.length===1 && feedbackDialog.children[0].textContent==='Updated error','dialog feedback is updated rather than duplicated');
 context.document.querySelector=()=>null;context.window.libraryUI.dialogFeedback('No dialog');check(feedbackDialog.children.length===1,'closed dialogs do not receive stale feedback');
 const recovery=guestHarness('js/forgot-password.js');
 recovery.get('phone').value='09170000000';
 const submit=()=>({preventDefault(){}});
 recovery.get('requestForm').listeners.submit(submit());
 recovery.get('requestForm').listeners.submit(submit());
 recovery.get('resendBtn').listeners.click();
 check(recovery.calls.length===1 && recovery.get('resendBtn').disabled,'request and resend cannot duplicate an in-flight recovery request');
 recovery.calls[0].resolve({ok:true,text:async()=>JSON.stringify({message:'Simulation mode sends no SMS.'})});await tick();
 check(recovery.get('resetForm').style.display==='block' && recovery.get('resetMsg').textContent.includes('Simulation'),'successful request displays accurate feedback in visible reset form');
 recovery.get('resendBtn').listeners.click();recovery.get('resendBtn').listeners.click();
 check(recovery.calls.length===2,'rapid resends submit once');
 recovery.calls[1].resolve({ok:false,text:async()=>JSON.stringify({error:'Wait before resending.'})});await tick();
 check(recovery.get('resetMsg').textContent==='Wait before resending.' && recovery.get('resetMsg').className==='msg error','resend failure is shown in visible reset form');
 recovery.get('code').value='123456';recovery.get('newPassword').value='Synthetic-password';recovery.get('confirmPassword').value='Synthetic-password';
 recovery.get('resetForm').listeners.submit(submit());recovery.get('resetForm').listeners.submit(submit());recovery.get('resendBtn').listeners.click();
 check(recovery.calls.length===3 && recovery.get('resendBtn').disabled,'reset and resend cannot race or double-submit');
 recovery.calls[2].resolve({ok:false,text:async()=>JSON.stringify({error:'Invalid code.'})});await tick();
 check(!recovery.get('resetBtn').disabled && !recovery.get('resendBtn').disabled,'failed reset restores controls');
 const formControls=new Map();const form=node('form');form.dataset={registrationForm:'teacher'};form.hidden=false;form.appendChild=child=>form.children.push(child);
 form.querySelector=selector=>{if(!formControls.has(selector))formControls.set(selector,{value:'',disabled:false,textContent:''});return formControls.get(selector);};
 let submitListeners=0;form.addEventListener=(event,fn)=>{form.listeners[event]=fn;if(event==='submit')submitListeners++;};
 const registrationUi=guestHarness('js/select-role.js',[form]);
 check(submitListeners===1,'registration has one authoritative submit listener');
 prevented=false;form.listeners.submit({preventDefault(){prevented=true;}});check(prevented,'registration fails closed until secure token is ready');
 registrationUi.calls.find(c=>c.url.includes('registration-token')).resolve({ok:true,json:async()=>({csrfToken:'synthetic-csrf'})});await tick();
 check(form.children.length===1 && form.children[0].name==='csrf_token','secure registration token appended once');
 form.querySelector('[name="password"]').value='one';form.querySelector('[name="confirm_password"]').value='two';prevented=false;
 form.listeners.submit({preventDefault(){prevented=true;}});check(prevented && !form.querySelector('button[type="submit"]').disabled,'mismatch prevents registration without locking form');
 form.querySelector('[name="confirm_password"]').value='one';prevented=false;form.listeners.submit({preventDefault(){prevented=true;}});
 check(!prevented && form.querySelector('button[type="submit"]').disabled,'valid registration submits once with loading state');
 form.listeners.submit({preventDefault(){prevented=true;}});check(prevented,'repeated registration submission prevented');
 console.log('Completed '+checks+' Phase 8 UI/security JavaScript checks.');
})().catch(error=>{console.error(error);process.exitCode=1;});
