const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
process.env.TZ='Pacific/Honolulu';let checks=0;
function verify(ok,label){assert.ok(ok,label);checks++;}
function harness(file,expose,transform=source=>source){
 const nodes=new Map();const get=id=>{if(!nodes.has(id))nodes.set(id,node());return nodes.get(id);};
 function node(){return {value:'',textContent:'',hidden:false,disabled:false,style:{},files:[],dataset:{},listeners:{},classList:{add(){},remove(){},toggle(){}},focus(){},reset(){},appendChild(){},addEventListener(e,f){this.listeners[e]=f;},querySelector(){return node();},querySelectorAll(){return[];},get innerHTML(){return this._html??this.textContent.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');},set innerHTML(v){this._html=v;}};}
 const context=vm.createContext({window:{location:{}},document:{getElementById:get,createElement:node,querySelector:node,querySelectorAll:()=>[]},fetch:()=>new Promise(()=>{}),setTimeout:()=>0,clearTimeout(){},Date,URL,FormData:class{},console});
 vm.runInContext(transform(fs.readFileSync(file,'utf8')).replace(/\nloadUsers\(\);/g,'').replace(/\nloadAnnouncements\(\);/g,''),context);
 vm.runInContext('window.test={'+expose+'}',context);return{context,get,api:context.window.test};
}
const users=harness('js/manage-users.js','renderRows,applyFilters,formatDate,formatDateTime,renderChart,loadUsers');
vm.runInContext("serverDate='2026-10-27';",users.context);
users.api.renderChart([{role:'student',created_at:'2026-10-27 09:00:00'}]);
const axisLabels=[...users.get('chartHolder').innerHTML.matchAll(/text-anchor="end">(\d+)<\/text>/g)].map(match=>Number(match[1]));
verify(axisLabels.join(',')==='0,1,2,3,4','small registration counts have distinct integer chart labels');
vm.runInContext(`allUsers=[{id:1,full_name:'<img onerror=x>',student_id:'<script>x</script>',role:'student',account_status:'pending',has_history:0},{id:2,full_name:'Teacher',id_number:'TCH2',role:'teacher',account_status:'accepted',has_history:1},{id:3,full_name:'Rejected',email:'visitor@example.invalid',role:'visitor',account_status:'rejected',has_history:0}];`,users.context);
users.api.applyFilters();
verify(users.get('countBadge').textContent.includes('3 of 3'),'all users counted');
verify(!users.get('usersBody').innerHTML.includes('<script>') && users.get('usersBody').innerHTML.includes('&lt;script&gt;'),'user identifier is escaped');
verify(!users.get('usersBody').innerHTML.includes('<img'),'user name is escaped');
verify(users.get('usersBody').innerHTML.includes('History linked — deletion unavailable'),'history restriction explained');
users.get('statusFilter').value='pending';users.api.applyFilters();verify(users.get('countBadge').textContent.includes('1 of 3'),'approval filter works');
users.get('roleFilter').value='teacher';users.api.applyFilters();verify(users.get('usersBody').innerHTML.includes('No users found'),'combined role/status empty state');
users.get('statusFilter').value='accepted';users.api.applyFilters();verify(users.get('usersBody').innerHTML.includes('Teacher'),'approved teacher filter');
users.get('roleFilter').value='';users.get('statusFilter').value='';users.get('searchInput').value='visitor@example';users.api.applyFilters();verify(users.get('countBadge').textContent.includes('1 of 3'),'email identifier search supported');
users.get('searchInput').value='TCH2';users.api.applyFilters();verify(users.get('usersBody').innerHTML.includes('Teacher'),'staff ID search');
verify(users.api.formatDate('2026-10-27 00:01:00').includes('27'),'Philippine date independent of Honolulu device');
verify(users.api.formatDateTime('2026-10-27 00:01:00').includes('27'),'Philippine profile date/time');
users.context.window.setAccountStatus(2,'accepted');verify(users.get('accountActionPrompt').textContent.includes('Approve Teacher'),'account action requires explicit confirmation');
users.get('cancelAccountAction').listeners.click();verify(users.get('accountConfirmation').hidden,'cancel account action hides confirmation');
const announcements=harness('js/manage-announcements.js','renderCards,applyFilters,formatDate,loadAnnouncements');
const publicAnnouncements=harness('js/announcements.js','render');
const homepage=harness('js/index.js','renderAnnouncements,buildBookMarkup');
// Parse actual generated markup. Unlike the old fake DOM, text-node serialization
// deliberately leaves quotes untouched; production must encode attribute values.
function parseMarkup(markup) {
 const parser=`import sys,json
from html.parser import HTMLParser
class Inspect(HTMLParser):
 def __init__(self):
  super().__init__(convert_charrefs=True); self.elements=[]; self.text=[]
 def handle_starttag(self,tag,attrs): self.elements.append({'tag':tag,'attrs':dict(attrs)})
 def handle_data(self,data): self.text.append(data)
p=Inspect();p.feed(sys.stdin.read());print(json.dumps({'elements':p.elements,'text':''.join(p.text)}))`;
 return JSON.parse(execFileSync('python3',['-c',parser],{input:markup,encoding:'utf8'}));
}
const titles=[
 'Ordinary library notice',
 'The "Readers\' Corner" & café — 日本語 <special> &quot;',
 'Notice" onload="window.phase7aXss=true" data-unexpected="injected',
 "Notice' onerror='window.phase7aXss=true",
 '\"><script>window.phase7aXss=true</script><img src=x onerror="window.phase7aXss=true">'
];
for (const title of titles) {
 const row={id:1,title,body:'Body <strong>literal</strong> & café',category:'Notice',image_path:'images/school-entrance.png',created_at:'2026-10-27 09:00:00'};
 for(const [surface,render,getMarkup] of [
  ['staff',()=>announcements.api.renderCards([row]),()=>announcements.get('cardList').innerHTML],
  ['public',()=>publicAnnouncements.api.render([row]),()=>publicAnnouncements.get('announceList').innerHTML],
  ['homepage',()=>homepage.api.renderAnnouncements([row]),()=>homepage.get('announceList').innerHTML]
 ]) {
  render();const parsed=parseMarkup(getMarkup());const images=parsed.elements.filter(el=>el.tag==='img');
  verify(images.length===1 && !parsed.elements.some(el=>['script','iframe','object'].includes(el.tag)),surface+' title cannot create unexpected elements');
  verify(!parsed.elements.some(el=>Object.keys(el.attrs).some(name=>(/^on/i.test(name) && !(el.tag==='button' && name==='onclick' && /^(openEditModal|deleteAnnouncement)\(1\)$/.test(el.attrs[name]))) || name==='data-unexpected')),surface+' title cannot create event-handler attributes');
  verify(images[0].attrs.alt==='Photo for '+title && images[0].attrs.src===row.image_path,surface+' image attributes preserve exact title and image path');
  verify(parsed.text.includes(title) && parsed.text.includes(row.body),surface+' title and body remain literal Unicode text');
 }
}
const cover=parseMarkup(homepage.api.buildBookMarkup({title:titles[2],image_path:'images/school-entrance.png'}));
verify(cover.elements.find(el=>el.tag==='img').attrs.alt==='Cover of '+titles[2] && !cover.elements.some(el=>'onload' in el.attrs),'shared homepage escaping protects book cover attributes too');
const unsafe=harness('js/manage-announcements.js','renderCards',source=>source.replace(/return div\.innerHTML\.replaceAll[^\n]+/, 'return div.innerHTML;'));
unsafe.api.renderCards([{id:1,title:titles[2],body:'Test',category:'Notice',image_path:'images/school-entrance.png'}]);
verify(parseMarkup(unsafe.get('cardList').innerHTML).elements.some(el=>el.tag==='img' && 'onload' in el.attrs),'corrected harness detects attribute injection when the production fix is removed');
vm.runInContext(`allAnnouncements=[{id:1,title:'<script>title</script>',body:'Long <img>\\ntext',category:'Notice',created_at:'2026-10-27 00:01:00'},{id:2,title:'Other',body:'Event details',category:'Event',created_at:'2026-10-26 23:00:00'}];`,announcements.context);
announcements.api.applyFilters();
verify(!announcements.get('cardList').innerHTML.includes('<script>') && announcements.get('cardList').innerHTML.includes('&lt;script&gt;'),'announcement title escaped');
verify(!announcements.get('cardList').innerHTML.includes('<img>') && announcements.get('cardList').innerHTML.includes('Long &lt;img&gt;'),'announcement body escaped');
announcements.get('categoryFilter').value='Event';announcements.api.applyFilters();verify(announcements.get('countBadge').textContent.includes('1 of 2'),'announcement category filtering');
announcements.get('searchInput').value='missing';announcements.api.applyFilters();verify(announcements.get('cardList').innerHTML.includes('No announcements found'),'announcement empty state');
verify(announcements.api.formatDate('2026-10-27 00:01:00').includes('27'),'announcement PHT date');
announcements.context.window.openEditModal('1');verify(announcements.get('announcementId').value===1,'edit handles string/numeric API IDs consistently');
announcements.context.window.deleteAnnouncement(2);verify(!announcements.get('deleteConfirmation').hidden && announcements.get('deletePrompt').textContent.includes('Other'),'removal requires named confirmation');
announcements.get('cancelDelete').listeners.click();verify(announcements.get('deleteConfirmation').hidden,'cancel announcement deletion preserves record');
announcements.get('saveBtn').disabled=true;let prevented=false;announcements.get('announcementForm').listeners.submit({preventDefault(){prevented=true;}});verify(prevented,'in-flight submit guard prevents repeat processing');
for(const [script,html] of [['js/manage-users.js','manage-users.html'],['js/manage-announcements.js','manage-announcements.html']]) {
 const ids=new Set([...fs.readFileSync(html,'utf8').matchAll(/id="([^"]+)"/g)].map(match=>match[1]));
 for(const match of fs.readFileSync(script,'utf8').matchAll(/getElementById\('([^']+)'\)/g)) verify(ids.has(match[1]),script+' control exists: '+match[1]);
}
async function verifyFailures() {
 users.context.fetch=async()=>({status:500,ok:false});
 await users.api.loadUsers();
 verify(users.get('loadingMsg').textContent.includes('Reload to retry'),'user load failure explains recovery');
 verify(users.get('usersTable').style.display==='none' && users.get('usersBody').innerHTML==='','user load failure removes stale rows');
 announcements.context.fetch=async()=>({ok:false,json:async()=>({error:'Could not load announcements.'})});
 await announcements.api.loadAnnouncements();
 verify(announcements.get('loadingMsg').textContent==='Could not load announcements.','announcement load failure is visible');
 verify(announcements.get('addBtn').disabled && announcements.get('countBadge').style.display==='none','failed announcement initialization disables publishing');
 announcements.api.applyFilters();
 verify(announcements.get('cardList').innerHTML.includes('No announcements found'),'search cannot restore stale notices after a load failure');
 console.log(`Completed ${checks} Phase 7A JavaScript checks.`);
}
verifyFailures().catch(error=>{console.error(error);process.exitCode=1;});
