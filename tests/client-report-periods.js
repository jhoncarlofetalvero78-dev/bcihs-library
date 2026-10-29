const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let checks = 0;
const verify = (ok, label) => { assert.ok(ok, label); checks++; };
function node(tag = 'div') {
  return {tag, value:'', textContent:'', disabled:false, hidden:false, children:[], listeners:{}, attributes:{},
    classList:{add(){},remove(){}},
    append(...items){this.children.push(...items);}, appendChild(item){this.children.push(item);},
    replaceChildren(...items){this.children=items;}, get lastChild(){return this.children.at(-1);},
    setAttribute(key,value){this.attributes[key]=value;}, addEventListener(event,callback){this.listeners[event]=callback;}, click(){}};
}
const elements = new Map();
const el = id => { if (!elements.has(id)) elements.set(id,node()); return elements.get(id); };
el('officialMonths').value='3';
el('officialOutput').parentNode=node();
const requests=[];
let answer={monthLabel:'January 2026',schoolYear:'2025-2026',total:2,legacy:0,undated:0,ranking:'Competition ranks',metric:'Checkout transactions',
  labels:['November 2025','December 2025','January 2026'],rows:[{key:'500',classification:'Natural Science',chart_label:'500-599',quantity:2,rank:1,months:[0,1,2]}],
  settings:{prepared_name:'',prepared_title:'',noted_name:'',noted_title:'',approved_name:'',approved_title:''},csrfToken:'synthetic'};
let resolvePending=null;
const context=vm.createContext({Date,URLSearchParams,Blob,
  document:{getElementById:el,createElement:node,createElementNS:(_,tag)=>node(tag),querySelectorAll:()=>[],body:{append(){},classList:{add(){},remove(){}}}},
  window:{location:{},addEventListener(){},print(){}},
  URL:{createObjectURL:()=>'',revokeObjectURL(){}},setTimeout(){},
  fetch:async url=>{requests.push(url);return{ok:true,json:async()=>resolvePending?await new Promise(resolve=>{resolvePending=resolve;}):structuredClone(answer)};}});
vm.runInContext(fs.readFileSync('js/statistical-report.js','utf8'),context);
function choose(month, count, year='2025-2026') {
  el('officialMonth').value=month;el('officialMonths').value=String(count);el('officialYear').value=year;
  el('officialMonth').listeners.input();
}
const submit=()=>el('officialFilters').listeners.submit({preventDefault(){}});
(async()=>{
  choose('2026-01',3);
  verify(el('officialPeriodPreview').textContent.includes('Table: January 2026 only.'),'table is one selected month');
  verify(el('officialPeriodPreview').textContent.includes('November 2025 to January 2026 (3 months)'),'chart includes report month and preceding months across year boundary');
  await submit();
  verify(requests[0].includes('month=2026-01&months=3&school_year=2025-2026'),'request retains exact existing parameter semantics');
  verify(el('officialTableMonth').textContent==='January 2026 (BOOKS)' && el('officialChartMonth').textContent==='January 2026 (BOOKS)','table and chart headings match generated month');
  verify(el('officialTotal').textContent===2 && el('officialRows').children[0].children[1].textContent===2,'rendered quantities remain API-derived');
  verify(!el('officialExcel').disabled && !el('officialPrint').disabled,'valid loaded report can export and print');
  el('officialExcel').listeners.click();
  verify(context.window.location.href.includes('month=2026-01&months=3') && context.window.location.href.endsWith('format=xlsx'),'Excel uses generated period');
  choose('2026-02',1);
  verify(el('officialPeriodPreview').textContent.includes('Chart: February 2026 (1 month)'),'single month comparison explained accurately');
  verify(el('officialOutput').hidden && el('officialExcel').disabled && el('officialPrint').disabled,'editing selections hides stale output and disables exports');
  const before=requests.length;
  for (const [month,count,year] of [['2026-13',3,'2025-2026'],['2026-01',0,'2025-2026'],['2026-01',13,'2025-2026'],['2026-01',1.5,'2025-2026'],['2026-01',3,'2026-2026']]) {
    choose(month,count,year);await submit();
    verify(requests.length===before,'invalid period is rejected before a request');
  }
  choose('2026-01',12);
  verify(el('officialPeriodPreview').textContent.includes('February 2025 to January 2026 (12 months)'),'twelve-month comparison includes selected month exactly once');
  choose('2026-01',3);
  resolvePending=true;
  const waiting=submit();await new Promise(setImmediate);
  choose('2026-02',3);
  resolvePending(structuredClone(answer));resolvePending=null;await waiting;
  verify(el('officialOutput').hidden && el('officialExcel').disabled,'late response cannot restore a report for obsolete selected months');
  const html=fs.readFileSync('reports.html','utf8');
  verify(html.includes('for="officialMonth">Report month') && html.includes('for="officialMonths">Months in comparison chart'),'plain labels retain explicit input associations');
  verify(html.includes('aria-describedby="officialMonthHelp"') && html.includes('aria-describedby="officialMonthsHelp"'),'helpers are associated with their controls');
  verify(!html.includes('Ending / table month') && !html.includes('>Comparison months '),'confusing old labels removed');
  console.log(`Completed ${checks} client report-period JavaScript checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
