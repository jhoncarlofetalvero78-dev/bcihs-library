(() => {
  const el = id => document.getElementById(id);
  let version = 0, csrfToken = '', saving = false;
  function render(data) {
    version = data.version; csrfToken = data.csrfToken;
    el('borrowingPolicyStatus').textContent = `Current default: ${data.days} calendar days. Changes apply to future checkouts only.`;
    el('borrowingPolicyForm').hidden = !data.can_manage;
    el('defaultBorrowingDays').value = data.days;
    el('borrowingPolicyAudit').replaceChildren();
    for (const row of data.audit) {
      const item = document.createElement('li');
      item.textContent = `${row.changed_at} PHT · Administrator #${row.admin_id}: ${row.previous_days} to ${row.new_days} days — ${row.reason}`;
      el('borrowingPolicyAudit').append(item);
    }
  }
  fetch('backend/borrowing-policy.php', {credentials:'same-origin',cache:'no-store'})
    .then(async response => {const data=await response.json();if(!response.ok)throw new Error(data.error||'Borrowing policy unavailable.');return data;})
    .then(render).catch(error => {el('borrowingPolicyStatus').textContent=error.message;});
  el('borrowingPolicyForm').addEventListener('submit', async event => {
    event.preventDefault(); if (saving) return;
    if (!await window.libraryUI.confirm('Apply this approved default to future checkouts only? Existing loan deadlines will stay unchanged.', {confirmLabel:'Save borrowing policy'})) return;
    saving=true;el('saveBorrowingPolicy').disabled=true;
    try {
      const response=await fetch('backend/borrowing-policy.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
        body:JSON.stringify({days:el('defaultBorrowingDays').value,version,reason:el('borrowingPolicyReason').value.trim(),confirm_change:true})});
      const data=await response.json();if(!response.ok)throw new Error(data.error||'Could not save policy.');
      render(data);el('borrowingPolicyReason').value='';
    } catch(error) {el('borrowingPolicyStatus').textContent=error.message;}
    finally {saving=false;el('saveBorrowingPolicy').disabled=false;}
  });
})();
