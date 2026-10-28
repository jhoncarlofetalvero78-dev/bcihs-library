(() => {
  const el = id => document.getElementById(id);
  let report = null, loading = false;
  const now = new Date();
  el('officialMonth').value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
  el('officialYear').value = `${now.getFullYear()}-${now.getFullYear()+1}`;
  const parameters = () => new URLSearchParams({month:el('officialMonth').value, months:el('officialMonths').value, school_year:el('officialYear').value});
  let loadedParameters = '';
  const signatories = [['prepared','Prepared by'],['noted','Noted by'],['approved','Approved by']];
  for (const [key,label] of signatories) for (const field of ['name','title']) {
    const wrapper = document.createElement('label'), input = document.createElement('input');
    wrapper.textContent = `${label} ${field}`; input.id = `signatory_${key}_${field}`; input.maxLength = 80; wrapper.append(input); el('signatoryForm').append(wrapper);
  }
  const save = document.createElement('button'); save.type = 'submit'; save.className = 'apply-filter-btn'; save.textContent = 'Save signatories'; el('signatoryForm').append(save); save.disabled = true;
  function chart(data) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
    const width = 1050, height = 450, left = 70, top = 90, bottom = 320, plotWidth = 950;
    svg.classList.add('official-chart-svg');
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Grouped vertical columns: monthly checkout counts by classification. Full classification names appear in the table.');
    const node = (tag, attrs, text) => {
      const item = document.createElementNS(svg.namespaceURI, tag);
      for (const [key, value] of Object.entries(attrs)) item.setAttribute(key, String(value));
      if (text !== undefined) item.textContent = text;
      svg.append(item);
      return item;
    };
    const colors = ['#333333','#777777','#bbbbbb','#4c6474','#72513b','#3f6b58','#764b6b','#666c30','#385072','#8a4e3e','#4b7c7c','#665b85'];
    const maximum = Math.max(1, ...data.rows.flatMap(row => row.months));
    const unit = Math.max(1, Math.ceil(maximum / 5)), axisMaximum = unit * 5;
    data.labels.forEach((label, index) => {
      const x = 20 + (index % 4) * 255, y = 20 + Math.floor(index / 4) * 20;
      node('rect', {x, y:y-10, width:12, height:12, fill:colors[index]});
      node('text', {x:x+18, y, 'font-size':12}, label);
    });
    for (let tick = 0; tick <= 5; tick++) {
      const value = unit * tick, y = bottom - value / axisMaximum * (bottom-top);
      node('line', {x1:left, x2:left+plotWidth, y1:y, y2:y, stroke:'#ddd'});
      node('text', {x:left-12, y:y+4, 'text-anchor':'end', 'font-size':12}, value);
    }
    node('text', {x:14, y:top-15, 'font-size':12}, 'Checkouts');
    const groupWidth = plotWidth / data.rows.length;
    data.rows.forEach((row, index) => {
      const groupLeft = left + index * groupWidth, barWidth = groupWidth * .78 / data.labels.length;
      const labelX = groupLeft + groupWidth / 2;
      node('text', {x:labelX, y:bottom+18, transform:`rotate(-45 ${labelX} ${bottom+18})`, 'text-anchor':'end', 'font-size':12}, row.chart_label);
      row.months.forEach((count, monthIndex) => {
        const barHeight = count / axisMaximum * (bottom-top);
        const x = groupLeft + groupWidth * .11 + monthIndex * barWidth;
        node('rect', {x, y:bottom-barHeight, width:barWidth*.9, height:barHeight, fill:colors[monthIndex]});
        const bar = svg.lastChild;
        const title = document.createElementNS(svg.namespaceURI, 'title');
        title.textContent = `${row.classification}: ${data.labels[monthIndex]} — ${count}`;
        bar.append(title);
      });
    });
    node('text', {x:left+plotWidth/2, y:height-18, 'text-anchor':'middle', 'font-size':13}, 'Classification');
    el('officialChart').replaceChildren(svg);
  }
  function render(data) {
    el('officialTableMonth').textContent=data.monthLabel+' (BOOKS)';el('officialChartMonth').textContent=data.monthLabel+' (BOOKS)';
    document.querySelectorAll('.officialSchoolYear').forEach(node=>node.textContent='S.Y. '+data.schoolYear);
    el('officialRows').replaceChildren();for(const row of data.rows){const tr=document.createElement('tr');if(row.key==='SIP')tr.className='sip-row';for(const value of [row.classification,row.quantity,row.rank??'—']){const td=document.createElement('td');td.textContent=value;tr.append(td);}el('officialRows').append(tr);}
    el('officialTotal').textContent=data.total;el('officialRanking').textContent=data.ranking;el('officialMetric').textContent=data.metric;
    document.querySelectorAll('.official-signatories').forEach(holder=>{holder.replaceChildren();for(const [key,label] of signatories){const p=document.createElement('p');p.textContent=`${label}: ${data.settings[key+'_name']||'________________'} — ${data.settings[key+'_title']||'________________'}`;holder.append(p);}});
    for(const [key,value] of Object.entries(data.settings))el('signatory_'+key).value=value;
    chart(data);el('officialOutput').hidden=false;
  }
  el('officialFilters').addEventListener('submit',async event=>{
    event.preventDefault();if(loading)return;loading=true;report=null;el('officialLoad').disabled=true;el('officialMessage').textContent='Loading circulation statistics…';
    ['officialExcel','officialCsv','officialPrint'].forEach(id=>el(id).disabled=true);el('officialOutput').hidden=true;
    try{const params=parameters().toString();const response=await fetch('backend/statistical-report.php?'+params,{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok)throw new Error(data.error||'Could not load statistics.');report=data;loadedParameters=params;render(data);save.disabled=false;['officialExcel','officialCsv','officialPrint'].forEach(id=>el(id).disabled=false);el('officialMessage').textContent=`${data.total} checkout(s) in ${data.monthLabel}. ${data.legacy} legacy dated loan(s) included in comparison; ${data.undated} undated loan record(s) excluded. ${data.rows.some(row=>row.key==='UNMAPPED')?'Unmapped circulation is included separately; review catalog classifications.':''}`;
    }catch(error){el('officialMessage').textContent=error.message;}finally{loading=false;el('officialLoad').disabled=false;}
  });
  el('signatoryForm').addEventListener('submit',async event=>{event.preventDefault();if(!report||save.disabled)return;save.disabled=true;try{const body={};for(const key of Object.keys(report.settings))body[key]=el('signatory_'+key).value;const response=await fetch('backend/statistical-report.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':report.csrfToken},body:JSON.stringify(body)});const data=await response.json();if(!response.ok)throw new Error(data.error||'Could not save signatories.');report.settings=data.settings;render(report);el('signatoryMessage').textContent='Signatories saved for future report exports.';}catch(error){el('signatoryMessage').textContent=error.message;}finally{save.disabled=false;}});
  el('officialExcel').addEventListener('click',()=>{if(report)window.location.href='backend/statistical-report.php?'+loadedParameters+'&format=xlsx';});
  el('officialCsv').addEventListener('click',()=>{if(!report)return;const cell=value=>'"'+String(value??'').replace(/^[\s]*[=+@-]/,"'$&").replaceAll('"','""')+'"';const rows=[['Classification',...report.labels],...report.rows.map(row=>[row.classification,...row.months])];const url=URL.createObjectURL(new Blob(['\uFEFF'+rows.map(row=>row.map(cell).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='circulation-'+report.month+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
  const output = el('officialOutput');
  const outputParent = output.parentNode;
  function restorePrintLayout() {
    outputParent.append(output);
    document.body.classList.remove('official-print');
  }
  el('officialPrint').addEventListener('click', () => {
    if (!report) return;
    // Keep dashboard wrappers out of the paginated report flow.
    document.body.append(output);
    document.body.classList.add('official-print');
    try { window.print(); } finally { restorePrintLayout(); }
  });
  window.addEventListener('afterprint', restorePrintLayout);
})();
