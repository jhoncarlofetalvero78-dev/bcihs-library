(() => {
  let open = false;
  window.checkoutDue = {
    choose({source, id, csrfToken, label}) {
      if (open) return Promise.resolve(null);
      open = true;
      const previous = document.activeElement;
      const dialog = document.createElement('dialog');
      dialog.className = 'checkout-due-dialog';
      dialog.setAttribute('aria-labelledby', 'checkoutDueTitle');
      // Static controls; borrower/book names and server responses use textContent below.
      dialog.innerHTML = `<h2 id="checkoutDueTitle">Confirm checkout and due date</h2>
        <p id="checkoutDueBorrower"></p><p>Confirm only after verifying the borrower and handing over the physical book.</p>
        <label for="checkoutDueMode">Borrowing period</label><select id="checkoutDueMode">
          <option value="default">Use current default</option><option value="days">Custom number of days</option><option value="date">Custom due date</option>
        </select>
        <label id="checkoutDaysLabel" for="checkoutDays" hidden>Calendar days (1–365)<input id="checkoutDays" type="number" min="1" max="365" step="1" value="3"></label>
        <label id="checkoutDateLabel" for="checkoutDate" hidden>Due date (through end of day, PHT)<input id="checkoutDate" type="date"></label>
        <p id="checkoutDueResult" role="status" aria-live="polite">Loading borrowing policy…</p>
        <p class="checkout-due-help">Use an approved school borrowing period. The displayed deadline is saved exactly; review again if confirmation expires.</p>
        <div class="checkout-due-actions"><button type="button" id="checkoutDueCancel">Cancel</button><button type="button" id="checkoutDueConfirm" disabled>Confirm Checkout</button></div>`;
      document.body.append(dialog);
      const el = name => dialog.querySelector('#' + name);
      el('checkoutDueBorrower').textContent = label;
      const mode = el('checkoutDueMode'), days = el('checkoutDays'), date = el('checkoutDate');
      let preview = null, version = 0, closed = false, policyReady = false;
      return new Promise(resolve => {
        function finish(value) {
          if (closed) return;
          closed = true; dialog.close(); dialog.remove(); open = false;
          if (previous?.isConnected) previous.focus();
          resolve(value);
        }
        async function loadPreview() {
          const current = ++version;
          preview = null; el('checkoutDueConfirm').disabled = true;
          el('checkoutDaysLabel').hidden = mode.value !== 'days';
          el('checkoutDateLabel').hidden = mode.value !== 'date';
          if (!policyReady) return;
          if ((mode.value === 'days' && (!Number.isInteger(Number(days.value)) || Number(days.value) < 1 || Number(days.value) > 365))
            || (mode.value === 'date' && !date.value)) {
            el('checkoutDueResult').textContent = 'Choose a valid borrowing period or due date.'; return;
          }
          el('checkoutDueResult').textContent = 'Calculating the exact due date…';
          try {
            const response = await fetch('backend/checkout-due-preview.php', {method:'POST', credentials:'same-origin', cache:'no-store',
              headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
              body:JSON.stringify({source, id, due_mode:mode.value, days:days.value, due_date:date.value})});
            const data = await response.json();
            if (closed || current !== version) return;
            if (!response.ok) throw new Error(data.error || 'Could not preview the due date.');
            preview = data;
            el('checkoutDueResult').textContent = 'Due: ' + data.due_at + ' PHT';
            el('checkoutDueConfirm').disabled = false;
          } catch (error) {
            if (!closed && current === version) el('checkoutDueResult').textContent = error.message;
          }
        }
        mode.addEventListener('change', loadPreview);
        days.addEventListener('input', loadPreview); date.addEventListener('change', loadPreview);
        el('checkoutDueCancel').addEventListener('click', () => finish(null));
        dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });
        el('checkoutDueConfirm').addEventListener('click', () => {
          if (preview && !el('checkoutDueConfirm').disabled) finish({due_preview_token:preview.due_preview_token, due_at:preview.due_at});
        });
        dialog.showModal(); el('checkoutDueCancel').focus();
        fetch('backend/borrowing-policy.php', {credentials:'same-origin', cache:'no-store'})
          .then(async response => { const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Borrowing policy unavailable.'); return data; })
          .then(data => {
            if (closed) return;
            mode.options[0].textContent = `Use current default (${data.days} calendar days)`;
            date.min = data.server_date; date.max = data.max_date;
            policyReady = true; loadPreview();
          }).catch(error => { if (!closed) el('checkoutDueResult').textContent = error.message; });
      });
    }
  };
})();
