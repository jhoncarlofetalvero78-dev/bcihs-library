// Shared report presentation keeps on-screen, CSV and print values consistent.
window.reporting = {
  labels: {all:'All statuses',pending:'Pending',awaiting_pickup:'Approved / Awaiting Pickup',approved:'Checked Out (legacy)',checked_out:'Checked Out',
    returned:'Returned',rejected:'Rejected',waiting:'Waiting',ready:'Ready for Pickup',fulfilled:'Fulfilled / Checked Out',cancelled:'Cancelled',
    accepted:'Accepted'},
  label(value) { return this.labels[value] || value || '—'; },
  csvCell(value) {
    let text=String(value ?? '');
    if (/^[\s]*[=+@-]/.test(text)) text="'"+text;
    return '"'+text.replace(/"/g,'""')+'"';
  },
  columns(type) {
    const loan=[['record_key','Record'],['source','Origin'],['borrower_name','Borrower'],['book_title','Book title'],['borrowed_at','Checked out (PHT)'],['due_date','Due date'],['returned_at','Returned (PHT)'],['status','Status'],['overdue','Overdue now'],['legacy_checkout','Legacy checkout date']];
    const definitions={
      inventory:[['title','Book title'],['author','Author'],['isbn','ISBN'],['accession_number','Accession'],['ddc','DDC'],['category','Category'],['call_number','Call number'],['total_copies','Physical copies'],['borrowed_copies','Borrowed copies'],['held_copies','Held for pickup'],['requestable_copies','Available for requests']],
      loans:loan,returns:loan,overdue:loan,
      requests:[['record_key','Record'],['borrower_name','Borrower'],['book_title','Book title'],['created_at','Requested (PHT)'],['approved_at','Approved (PHT)'],['borrowed_at','Checked out (PHT)'],['due_date','Due date'],['returned_at','Returned (PHT)'],['status','Current status']],
      reservations:[['record_key','Record'],['borrower_name','Borrower'],['book_title','Book title'],['created_at','Created (PHT)'],['pickup_at','Scheduled pickup (PHT)'],['approved_at','Ready (PHT)'],['borrowed_at','Checked out (PHT)'],['due_date','Due date'],['returned_at','Returned (PHT)'],['status','Current status']],
      attendance:[['id','Visit'],['visitor_type','Visitor type'],['student_name','Name'],['student_identifier','School ID'],['year_section','Year / section or department'],['time_in','Time In (PHT)'],['repeat_visit','Same-day repeat (registered)']],
      users:[['role','Role'],['status','Current approval status'],['accounts','Accounts']]
    };
    return definitions[type] || [];
  },
  value(row,field) {
    if (field==='status') return this.label(row[field]);
    if (['overdue','legacy_checkout','repeat_visit'].includes(field)) return row[field]?'Yes':'No';
    return row[field] ?? '';
  },
  csv(data,title,search,status) {
    const columns=this.columns(data.report);
    const lines=[['Report',title],['Period',['inventory','overdue'].includes(data.report)?'Current snapshot':data.range.from+' to '+data.range.to],['Scope',data.scope],['Generated (PHT)',data.generatedAt],['Search',search],['Status filter',this.label(status)]];
    Object.entries(data.summary).forEach(([label,value])=>lines.push([this.label(label),value]));
    lines.push([],columns.map(column=>column[1]));
    data.records.forEach(row=>lines.push(columns.map(([field])=>this.value(row,field))));
    if (data.report==='attendance') {
      lines.push([],['Time In date (PHT)','Matching visits']);
      Object.entries(data.daily).forEach(([day,total])=>lines.push([day,total]));
    }
    return '\uFEFF'+lines.map(row=>row.map(value=>this.csvCell(value)).join(',')).join('\r\n');
  }
};
