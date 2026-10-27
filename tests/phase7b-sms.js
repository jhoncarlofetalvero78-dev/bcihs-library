const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
process.env.TZ='Pacific/Honolulu';let checks=0;
function verify(ok,label){assert.ok(ok,label);checks++;}
const nodes=new Map();
function node(){return {textContent:'',value:'',hidden:false,disabled:false,style:{},className:'',listeners:{},
 addEventListener(event,fn){this.listeners[event]=fn;},get innerHTML(){return this._html??String(this.textContent).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');},set innerHTML(value){this._html=value;}};}
const get=id=>{if(!nodes.has(id))nodes.set(id,node());return nodes.get(id);};
const context=vm.createContext({document:{getElementById:get,createElement:node,querySelectorAll:()=>[]},window:{},Date,console,fetch:()=>new Promise(()=>{})});
vm.runInContext(fs.readFileSync('js/sms-logs.js','utf8').replace(/\n  loadLogs\(\);\s*$/,''),context);
vm.runInContext('window.test={renderLogs,loadLogs,formatDateTime}',context);const api=context.window.test;
const entries=[{created_at:'2026-10-28 00:01:00',full_name:'<img onerror=x>',phone:'*******6789',message:'A <script>literal</script> & café',event:'borrow_ready',status:'simulated',reason:'simulation_no_network_no_credits'},
 {created_at:'2026-10-28 00:01:00',full_name:'Borrower',phone:'*******6789',message:'Checked out',event:'checkout',status:'accepted',reason:'provider_network_accepted_not_handset_delivery'},
 {status:'legacy_sent',event:'legacy',reason:'historical_unverified_status'}];
vm.runInContext('allLogs='+JSON.stringify(entries),context);api.renderLogs();
verify(get('logsBody').innerHTML.includes('&lt;script&gt;') && !get('logsBody').innerHTML.includes('<script>'),'message HTML remains literal');
verify(!get('logsBody').innerHTML.includes('<img'),'recipient HTML remains literal');
verify(get('logsBody').innerHTML.includes('Simulated (no credits)'),'simulation clearly labelled');
verify(get('logsBody').innerHTML.includes('Provider accepted (delivery unconfirmed)'),'acceptance is not labelled delivered');
verify(get('logsBody').innerHTML.includes('Legacy sent (unverified)'),'historical sent is labelled unverified');
verify(api.formatDateTime('2026-10-28 00:01:00').includes('28'),'PHT date does not shift with Honolulu device timezone');
get('statusFilter').value='simulated';api.renderLogs();verify(get('countBadge').textContent.includes('1 of 3'),'canonical status filter works');
get('statusFilter').value='';get('searchInput').value='checkout with due date';api.renderLogs();verify(get('countBadge').textContent.includes('1 of 3'),'search includes event labels');
get('searchInput').value='missing';api.renderLogs();verify(get('logsBody').innerHTML.includes('No SMS log entries match'),'empty state clear');
get('searchInput').value='';
async function main(){
 context.fetch=async()=>({ok:true,json:async()=>({logs:entries,mode:'simulate'})});await api.loadLogs();
 verify(!get('logsTable').hidden && get('loadingMsg').hidden && !get('refreshBtn').disabled,'successful reload reveals table and restores refresh');
 verify(get('smsMode').textContent.includes('no provider requests'),'mode banner explains no real send');
 const uniLogs=[{...entries[0],provider:'unisms',reference:'msg_mock-123',status:'retrying',reason:'provider_retrying',request_attempted:true,http_status:201},
 {...entries[1],provider:'unisms',reference:'<img src=x onerror=x>',status:'sent',reason:'provider_sent_not_handset_delivery',request_attempted:false}];
 context.fetch=async()=>({ok:true,json:async()=>({logs:uniLogs,mode:'simulate',provider:'unisms'})});await api.loadLogs();
 verify(get('smsMode').textContent.includes('Selected provider: UniSMS'),'mode banner identifies selected provider safely');
 verify(get('logsBody').innerHTML.includes('Provider retrying (no app resend)'),'provider retry is distinct from application resend');
 verify(get('logsBody').innerHTML.includes('Provider reports sent (handset unconfirmed)'),'UniSMS sent never claims handset delivery');
 verify(get('logsBody').innerHTML.includes('API request attempted (HTTP 201)') && get('logsBody').innerHTML.includes('No API request'),'request metadata is clear');
 verify(get('logsBody').innerHTML.includes('&lt;img src=x onerror=x&gt;') && !get('logsBody').innerHTML.includes('<img'),'provider reference remains inert text');
 get('statusFilter').value='retrying';api.renderLogs();verify(get('countBadge').textContent.includes('1 of 2'),'UniSMS retrying filter');
 get('statusFilter').value='sent';api.renderLogs();verify(get('countBadge').textContent.includes('1 of 2'),'UniSMS sent filter');
 get('statusFilter').value='';get('searchInput').value='msg_mock-123';api.renderLogs();verify(get('countBadge').textContent.includes('1 of 2'),'reference ID search');
 get('searchInput').value='UniSMS';api.renderLogs();verify(get('countBadge').textContent.includes('2 of 2'),'provider search');get('searchInput').value='';
 const blockedLog={...entries[0],provider:'unisms',status:'skipped',reason:'live_test_consumed',request_attempted:false};
 context.fetch=async()=>({ok:true,json:async()=>({logs:[blockedLog],mode:'simulate',provider:'unisms'})});await api.loadLogs();
 verify(get('logsBody').innerHTML.includes('one live-test attempt is already consumed') && get('logsBody').innerHTML.includes('No API request'),'consumed guard is a safe skipped result with no request');
 context.fetch=async()=>({ok:true,json:async()=>({logs:[{...blockedLog,reason:'live_test_restore_failed'}],mode:'simulate',provider:'unisms'})});await api.loadLogs();
 verify(get('logsBody').innerHTML.includes('Safe configuration reset failed. No API request was made'),'reset failure is explained without claiming a submission');
 context.fetch=async()=>({ok:false,status:403,json:async()=>({error:'denied'})});await api.loadLogs();
 verify(get('logsTable').hidden && get('loadingMsg').textContent.includes('administrator or librarian'),'unauthorized reload hides data');
 verify(get('logsBody').innerHTML==='','failed reload clears stale logs');
 context.fetch=async()=>{throw new Error('offline');};await api.loadLogs();verify(!get('refreshBtn').disabled && get('loadingMsg').textContent==='offline','network failure allows retry');
 const pageHtml=fs.readFileSync('sms-logs.html','utf8');
 verify(pageHtml.includes('does not poll UniSMS or Semaphore') && pageHtml.includes('Refresh local logs'),'UI explicitly distinguishes local refresh from provider synchronization');
 verify(pageHtml.includes('Status may lag behind the provider dashboard') && !vm.runInContext("Object.hasOwn(statusLabels,'delivered')",context),'UI warns about stale status and has no invented Delivered state');
 const ids=new Set([...pageHtml.matchAll(/id="([^"]+)"/g)].map(match=>match[1]));
 for(const match of fs.readFileSync('js/sms-logs.js','utf8').matchAll(/getElementById\('([^']+)'\)/g))verify(ids.has(match[1]),'HTML control exists: '+match[1]);
 console.log(`Completed ${checks} Phase 7B JavaScript checks.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
