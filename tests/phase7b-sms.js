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
 verify(get('smsMode').textContent.includes('no Semaphore requests'),'mode banner explains no real send');
 context.fetch=async()=>({ok:false,status:403,json:async()=>({error:'denied'})});await api.loadLogs();
 verify(get('logsTable').hidden && get('loadingMsg').textContent.includes('administrator or librarian'),'unauthorized reload hides data');
 verify(get('logsBody').innerHTML==='','failed reload clears stale logs');
 context.fetch=async()=>{throw new Error('offline');};await api.loadLogs();verify(!get('refreshBtn').disabled && get('loadingMsg').textContent==='offline','network failure allows retry');
 const ids=new Set([...fs.readFileSync('sms-logs.html','utf8').matchAll(/id="([^"]+)"/g)].map(match=>match[1]));
 for(const match of fs.readFileSync('js/sms-logs.js','utf8').matchAll(/getElementById\('([^']+)'\)/g))verify(ids.has(match[1]),'HTML control exists: '+match[1]);
 console.log(`Completed ${checks} Phase 7B JavaScript checks.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
