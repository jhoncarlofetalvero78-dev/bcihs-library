'use strict';
const pickupToken = new URLSearchParams(window.location.search).get('token') || '';
const validPickupToken = pickupToken.length === 64 && /^[a-f0-9]{64}$/.test(pickupToken);
const pickupMessage = document.getElementById('pickupMessage');
const pickupDetails = document.getElementById('pickupDetails');
const checkoutButton = document.getElementById('checkoutButton');
const refreshPickup = document.getElementById('refreshPickup');
const staffSignin = document.getElementById('staffSignin');
let pickupRequest = null, pickupCsrf = '', pickupBusy = false;

function pickupDate(value) {
  if (!value) return '—';
  const date = new Date(value.replace(' ', 'T') + '+08:00');
  return isNaN(date) ? '—' : date.toLocaleString('en-PH', {timeZone:'Asia/Manila'});
}

function renderPickup(data) {
  pickupRequest = data.request; pickupCsrf = data.csrfToken;
  const request = data.request;
  const fields = [
    ['Borrower', request.full_name], ['Identifier', request.student_id || request.id_number || request.email],
    ['Year / section', request.year_section], ['Book', request.title], ['Author', request.author],
    ['Call number', request.call_number], ['Request', '#' + request.id], ['Requested', pickupDate(request.requested_at)],
    ['Approved', pickupDate(request.approved_at)], ['Account approval', request.account_status === 'accepted' ? 'Approved' : request.account_status],
    ['Borrowing status', request.status.replaceAll('_',' ')], ['Checked out', pickupDate(request.checked_out_at)],
    ['Due', pickupDate(request.due_date)], ['Returned', pickupDate(request.returned_at)]
  ];
  const holder = document.getElementById('pickupFields'); holder.replaceChildren();
  fields.forEach(([label,value]) => {
    const term = document.createElement('dt'), detail = document.createElement('dd');
    term.textContent = label; detail.textContent = value || '—'; holder.append(term,detail);
  });
  document.getElementById('pickupEligibility').textContent = data.eligible
    ? 'Awaiting pickup. Verify the borrower and book before confirming physical handover.'
    : 'This QR cannot be used for checkout. It is already used, no longer awaiting pickup, or the account is not approved.';
  checkoutButton.hidden = !data.eligible; pickupDetails.hidden = false;
}

async function loadPickup() {
  if (pickupBusy) return;
  pickupRequest = null; pickupCsrf = ''; document.getElementById('pickupFields').replaceChildren(); pickupDetails.hidden = true; checkoutButton.hidden = true; staffSignin.hidden = true;
  if (!validPickupToken) { pickupMessage.textContent = 'Invalid pickup QR. Ask the borrower to reopen their approved request.'; return; }
  pickupMessage.textContent = 'Checking pickup QR…'; refreshPickup.disabled = true;
  try {
    const response = await fetch('backend/pickup-verification-data.php?token=' + pickupToken, {credentials:'same-origin',cache:'no-store'});
    const data = await response.json();
    if (response.status === 401 || response.status === 403) { window.location.href = 'admin-login.html?pickup=' + pickupToken; return; }
    if (!response.ok) throw new Error(data.error || 'Could not verify this QR.');
    renderPickup(data); pickupMessage.textContent = 'Pickup details verified. All times are Philippine time.';
  } catch (error) {
    pickupMessage.textContent = error.message || 'Could not reach the library. Recheck status to retry.';
    staffSignin.href = 'admin-login.html?pickup=' + pickupToken; staffSignin.hidden = false;
  } finally { refreshPickup.disabled = false; }
}

refreshPickup.addEventListener('click', loadPickup);
checkoutButton.addEventListener('click', async () => {
  if (pickupBusy || !pickupRequest || checkoutButton.hidden) return;
  pickupBusy = true; checkoutButton.disabled = true; refreshPickup.disabled = true;
  let due = null;
  try {
    if (!window.checkoutDue) throw new Error('Reload to load the due-date confirmation.');
    due = await window.checkoutDue.choose({source:'borrow', id:Number(pickupRequest.id), csrfToken:pickupCsrf,
      label:pickupRequest.full_name + ' — ' + pickupRequest.title});
    if (!due) return;
    const response = await fetch('backend/borrow-request-action.php', {
      method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':pickupCsrf},
      body:JSON.stringify({id:pickupRequest.id, action:'checkout', pickup_token:pickupToken, due_preview_token:due.due_preview_token})
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Checkout was not completed. Recheck status before retrying.');
    pickupMessage.textContent = 'Checkout confirmed.' + (data.due_date ? ' Due: ' + pickupDate(data.due_date) + '.' : '');
  } catch (error) { pickupMessage.textContent = (error.message || 'Connection interrupted.') + ' Recheck status before trying again.'; }
  finally {
    pickupBusy = false; checkoutButton.disabled = false; refreshPickup.disabled = false;
    if (due) {
      // The backend may have committed even if its response was interrupted.
      const outcome = pickupMessage.textContent;
      await loadPickup();
      pickupMessage.textContent = outcome + ' ' + pickupMessage.textContent;
    }
  }
});
loadPickup();
