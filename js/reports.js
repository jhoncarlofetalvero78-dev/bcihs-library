(() => {
  const el=id=>document.getElementById(id);
  const titles={inventory:'Book Inventory',loans:'Checkouts',returns:'Returns',requests:'Borrow Requests',overdue:'Overdue Loans',reservations:'Reservations',attendance:'Library Attendance',users:'User Statistics'};
  const statuses={inventory:[],loans:['checked_out','returned'],returns:['returned'],requests:['pending','awaiting_pickup','approved','checked_out','returned','rejected'],overdue:['checked_out'],reservations:['waiting','ready','fulfilled','returned','cancelled'],attendance:[],users:['accepted','pending','rejected']};
  let loaded=null, version=0, loadedSearch='', loadedStatus='all';
  function escapeHtml(value) {
    const span=document.createElement('span');span.textContent=String(value??'');return span.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }
  function controls() {
    const type=el('reportType').value;
    const snapshot=['inventory','overdue'].includes(type);
    el('reportPreset').disabled=snapshot;
    ['reportDateFrom','reportDateTo'].forEach(id=>{el(id).disabled=snapshot || el('reportPreset').value!=='custom';el(id).required=!el(id).disabled;});
    el('reservationBasisField').hidden=type!=='reservations';
  }
  function resetStatus() {
    el('reportStatus').innerHTML='<option value="all">All statuses</option>';
    statuses[el('reportType').value].forEach(status=>{
      const option=document.createElement('option');option.value=status;option.textContent=reporting.label(status);el('reportStatus').appendChild(option);
    });
    el('reportStatus').disabled=statuses[el('reportType').value].length===0;
    controls();
  }
  async function load() {
    const currentVersion=++version;
    loaded=null;el('exportReport').disabled=true;el('printBtn').disabled=true;
    el('reportContent').hidden=true;el('loadingMsg').hidden=false;el('loadingMsg').textContent='Loading report…';el('filterError').hidden=true;
    const query=new URLSearchParams({report:el('reportType').value,period:el('reportPreset').value,status:el('reportStatus').value,
      from:el('reportDateFrom').value,to:el('reportDateTo').value,search:el('reportSearch').value.trim(),basis:el('reportBasis').value});
    const search=el('reportSearch').value.trim(),status=el('reportStatus').value;
    try {
      const response=await fetch('backend/reports-data.php?'+query,{credentials:'same-origin',cache:'no-store'});
      const data=await response.json();
      if (currentVersion!==version) return;
      if (!response.ok) {
        if (response.status===401) {window.location.href='admin-login.html';return;}
        throw new Error(data.error || 'Could not load this report.');
      }
      loaded=data;loadedSearch=search;loadedStatus=status;
      el('reportDateFrom').value=data.range.from;el('reportDateTo').value=data.range.to;
      el('reportTitle').textContent=titles[data.report];
      el('reportPeriod').textContent=(['inventory','overdue'].includes(data.report)?'Current snapshot':data.range.from+' to '+data.range.to+' (PHT)')+
        ` · Search: ${search || 'All'} · Status: ${reporting.label(status)}`;
      el('reportScope').textContent=data.scope;
      el('reportGenerated').textContent=`Generated ${data.generatedAt} PHT · Generic library report; official statistical outputs are available separately below.`;
      el('reportWarning').hidden=data.current.inventory_matches_loans && !data.current.undated_loan_records;
      el('reportWarning').textContent='Some inventory balances differ from timestamped outstanding loans, or loan records lack checkout dates. Ask the librarian to review source records; this report has not changed them.';
      el('reportSummary').innerHTML=Object.entries(data.summary).map(([label,value])=>`<div class="stat-card"><span class="stat-label">${escapeHtml(reporting.label(label))}</span><strong class="stat-value">${escapeHtml(value)}</strong></div>`).join('');
      const columns=reporting.columns(data.report);
      el('reportHead').innerHTML='<tr>'+columns.map(([field,label])=>`<th data-field="${field}">${escapeHtml(label)}</th>`).join('')+'</tr>';
      el('reportBody').innerHTML=data.records.length?data.records.map(row=>'<tr>'+columns.map(([field])=>`<td data-field="${field}">${escapeHtml(reporting.value(row,field)==='' ? '—' : reporting.value(row,field))}</td>`).join('')+'</tr>').join(''):
        `<tr><td colspan="${columns.length}" class="empty-msg">No records match these filters.</td></tr>`;
      el('attendanceDaily').hidden=data.report!=='attendance';
      el('topBooksPanel').hidden=data.report!=='loans';
      const ranked=new Map();
      data.records.forEach(row=>{if(data.report==='loans'){const book=ranked.get(row.book_id)||{title:row.book_title,count:0};book.count++;ranked.set(row.book_id,book);}});
      el('topBooksList').innerHTML=[...ranked.values()].sort((a,b)=>b.count-a.count || a.title.localeCompare(b.title)).slice(0,8)
        .map(book=>`<li>${escapeHtml(book.title)}: <strong>${book.count}</strong> checkout(s)</li>`).join('') || '<li>No checkouts in this selection.</li>';
      el('dailyTotals').innerHTML=Object.entries(data.daily).map(([day,total])=>`<span>${escapeHtml(day)}: <strong>${Number(total)}</strong> visits</span>`).join('') || '<p>No visits in this selection.</p>';
      el('reportContent').hidden=false;el('loadingMsg').hidden=true;
      el('exportReport').disabled=false;el('printBtn').disabled=false;
    } catch (error) {
      if (currentVersion!==version) return;
      el('loadingMsg').hidden=true;el('filterError').hidden=false;el('filterError').textContent=error.message || 'Could not load the report. Show Report to retry.';
    }
  }
  el('reportType').addEventListener('change',resetStatus);
  el('reportPreset').addEventListener('change',controls);
  el('reportFilters').addEventListener('submit',event=>{event.preventDefault();load();});
  el('printBtn').addEventListener('click',()=>{if(loaded)window.print();});
  el('exportReport').addEventListener('click',()=>{
    if (!loaded) return;
    const csv=reporting.csv(loaded,titles[loaded.report],loadedSearch,loadedStatus);
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    const link=document.createElement('a');link.href=url;link.download=`report-${loaded.report}-${loaded.serverToday}.csv`;link.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  fetch('backend/admin-session-status.php',{credentials:'same-origin',cache:'no-store'}).then(response=>response.json()).then(data=>{
    if (!data.loggedIn) {window.location.href='admin-login.html';return;}
    const name=data.username || 'Admin';el('adminName').textContent=name;el('adminInitial').textContent=name.charAt(0).toUpperCase();
    document.querySelector('.account-role').textContent=data.role==='librarian'?'LIBRARIAN':'ADMINISTRATOR';
  }).catch(()=>{});
  resetStatus();load();
})();
