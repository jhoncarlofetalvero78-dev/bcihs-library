
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      document.querySelector('.account-role').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
    })
    .catch(() => {});

  const toast = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }
  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => showToast(`${el.dataset.soon} isn't wired up yet — coming soon.`));
  });

  let allReservations = [];
  let csrfToken = '';
  const activeActions = new Set();
  let calendarMonth = new Date(reservationScheduling.today().slice(0, 7) + '-01T12:00:00');
  let pickupSettings = {daily_limit:null, slot_limit:null};
  let selectedDate = '';

  function reservationDateKey(datetime) {
    return datetime ? String(datetime).slice(0, 10) : '';
  }

  function renderCalendar() {
    const monthLabel = calendarMonth.toLocaleDateString(undefined, { year: 'numeric', month: 'long' });
    document.getElementById('calendarMonth').textContent = monthLabel;

    const grid = document.getElementById('calendarGrid');
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const firstWeekday = calendarMonth.getDay();
    const daysInMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 0).getDate();
    const todayKey = reservationScheduling.today();
    const basis = document.getElementById('calendarBasis').value;
    const scheduled = {};
    const counts = {};

    allReservations.forEach(reservation => {
      const key = reservationDateKey(reservation[basis]);
      if (key) counts[key] = (counts[key] || 0) + 1;
      if (['waiting','ready'].includes(reservation.status) && reservation.pickup_at) {
        const pickup = reservationDateKey(reservation.pickup_at);
        scheduled[pickup] = (scheduled[pickup] || 0) + 1;
      }
    });

    grid.innerHTML = weekdays.map(day => `<span class="calendar-weekday" aria-hidden="true">${day}</span>`).join('');
    for (let blank = 0; blank < firstWeekday; blank += 1) {
      grid.insertAdjacentHTML('beforeend', '<button class="calendar-day" type="button" disabled aria-hidden="true"></button>');
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
      const key = `${calendarMonth.getFullYear()}-${String(calendarMonth.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const count = counts[key] || 0;
      const dateLabel = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), day)
        .toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
      const classes = ['calendar-day'];
      if (key === todayKey) classes.push('today');
      if (key === selectedDate) classes.push('selected');
      const weekday = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), day).getDay();
      if (weekday === 0 || weekday === 6) classes.push('closed');
      const full = basis === 'pickup_at' && pickupSettings.daily_limit !== null && (scheduled[key] || 0) >= pickupSettings.daily_limit;
      if (full) classes.push('full');
      grid.insertAdjacentHTML('beforeend', `
        <button type="button" class="${classes.join(' ')}" data-date="${key}" aria-pressed="${key === selectedDate}" aria-label="${dateLabel}${count ? `, ${count} reservation${count === 1 ? '' : 's'}` : ', no reservations'}">
          ${day}${count ? `<span class="calendar-day-count">${count} res.</span>` : ''}${full ? '<span class="calendar-day-count">Full day</span>' : ''}
        </button>
      `);
    }

    grid.querySelectorAll('[data-date]').forEach(button => {
      button.addEventListener('click', () => {
        selectedDate = selectedDate === button.dataset.date ? '' : button.dataset.date;
        renderCalendar();
        applyFilters();
      });
    });

    const selected = selectedDate ? new Date(`${selectedDate}T00:00:00`) : null;
    document.getElementById('calendarSelection').textContent = selected
      ? `Showing ${basis === 'pickup_at' ? 'pickups' : 'submissions'} for ${selected.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}.`
      : `Showing reservations for all ${basis === 'pickup_at' ? 'pickup' : 'submission'} dates.`;
    document.getElementById('clearCalendarDate').hidden = !selectedDate;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    return reservationScheduling.format(mysqlDatetime);
  }

  function reservationActions(r) {
    if (activeActions.has(Number(r.id))) return '<span role="status">Saving…</span>';
    const canCheckout = r.status === 'ready' ? r.available_copies > 0 : r.requestable_copies > 0;
    const buttons = [`<button class="action-btn view" onclick="showReservationDetails(${r.id})">Details</button>`];
    if (r.status === 'waiting') {
      buttons.push(`<button class="action-btn view" onclick="scheduleReservation(${r.id})">Schedule</button>`);
      buttons.push(`<button class="action-btn ready" ${!r.pickup_at || r.requestable_copies < 1 ? 'disabled' : ''} onclick="act(${r.id}, 'ready')">Mark Ready</button>`);
    }
    if (['waiting','ready'].includes(r.status)) {
      buttons.push(`<button class="action-btn fulfill" ${canCheckout ? '' : 'disabled title="No unallocated physical copy is available"'} onclick="act(${r.id}, 'fulfill')">Confirm Checkout</button>`);
      buttons.push(`<button class="action-btn cancel" onclick="act(${r.id}, 'cancel')">Cancel</button>`);
    }
    if (r.status === 'fulfilled') buttons.push(`<button class="action-btn fulfill" onclick="act(${r.id}, 'return')">Confirm Return</button>`);
    return buttons.join('');
  }

  function renderRows(reservations) {
    const body = document.getElementById('reservationsBody');
    if (reservations.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="empty-msg">No reservations match.</td></tr>';
      return;
    }
    body.innerHTML = reservations.map(r => `
      <tr>
        <td><strong>${escapeHtml(r.student_name)}</strong><small class="reservation-meta">${escapeHtml(borrowing.roleLabel(r.borrower_role))} · ${escapeHtml(r.student_id || 'No school ID')} · ${escapeHtml(r.year_section || '—')}</small></td>
        <td>${escapeHtml(r.book_title)}<small class="reservation-meta">${r.requestable_copies} available for new requests · ${r.held_copies} held</small></td>
        <td>${r.queue_position ? `<span class="queue-pos">#${r.queue_position}</span>` : '—'}</td>
        <td>${formatDate(r.reserved_at)}</td>
        <td>${r.pickup_at ? formatDate(r.pickup_at) : 'Not scheduled'}</td>
        <td><span class="status-pill ${r.status}">${borrowing.label(r.status)}</span></td>
        <td><div class="reservation-actions">${reservationActions(r)}</div>
        </td>
      </tr>
    `).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const basis = document.getElementById('calendarBasis').value;
    const filtered = allReservations.filter(r => {
      if (selectedDate && reservationDateKey(r[basis]) !== selectedDate) return false;
      if (status && r.status !== status) return false;
      if (!q) return true;
      return [r.student_name, r.book_title].join(' ').toLowerCase().includes(q);
    });
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allReservations.length} reservation${allReservations.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function loadReservations() {
    return Promise.all([fetch('backend/reservations-data.php', { credentials:'same-origin', cache:'no-store' }),
      fetch('backend/reservation-settings.php', { credentials:'same-origin', cache:'no-store' })])
      .then(async responses => {
        if (responses.some(response => response.status === 401)) throw new Error('not_logged_in');
        if (responses.some(response => !response.ok)) throw new Error('failed');
        return Promise.all(responses.map(response => response.json()));
      })
      .then(([data, policy]) => {
        pickupSettings = policy.settings;
        document.getElementById('dailyLimit').value = pickupSettings.daily_limit ?? '';
        document.getElementById('slotLimit').value = pickupSettings.slot_limit ?? '';
        if (!data || !Array.isArray(data.reservations)) {
          throw new Error('invalid_response');
        }
        allReservations = data.reservations;
        csrfToken = data.csrfToken || '';
        renderCalendar();
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('retryReservations').hidden = true;
        document.getElementById('reservationsTable').style.display = 'table';
        document.getElementById('countBadge').style.display = 'block';
        applyFilters();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') { window.location.href = 'admin-login.html'; return; }
        const loadingMsg = document.getElementById('loadingMsg');
        loadingMsg.textContent = 'Something went wrong loading reservations. Check your connection and try again.';
        loadingMsg.classList.add('error-msg');
        loadingMsg.style.display = 'block';
        document.getElementById('retryReservations').hidden = false;
        document.getElementById('reservationsTable').style.display = 'none';
        document.getElementById('countBadge').style.display = 'none';
      });
  }

  window.act = async function(id, action) {
    if (activeActions.has(id)) return;
    const prompts = {ready:'Hold an available copy for this reservation? This does not check it out.', fulfill:'Confirm physical handover and start this loan now?', return:'Confirm the physical book has been returned?', cancel:'Cancel this reservation and release any hold?'};
    if (!prompts[action]) return;
    let due = null;
    if (action === 'fulfill') {
      const row = allReservations.find(item => Number(item.id) === Number(id));
      if (!row || !window.checkoutDue) { showToast('Reload to review the checkout due date.'); return; }
      due = await window.checkoutDue.choose({source:'reservation', id:Number(id), csrfToken, label:row.student_name + ' — ' + row.book_title});
      if (!due) return;
    } else if (!await window.libraryUI.confirm(prompts[action], {danger:action === 'cancel',confirmLabel:({ready:'Mark Ready',return:'Confirm return',cancel:'Cancel reservation'})[action]})) return;
    activeActions.add(id);
    applyFilters();
    const labels = { ready: 'marked ready', fulfill: 'fulfilled', cancel: 'cancelled', return: 'marked returned' };
    fetch('backend/reservation-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action, ...(due ? {due_preview_token:due.due_preview_token} : {}) })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not update that reservation.');
        return data;
      })
      .then(() => {
        showToast(`Reservation ${labels[action]}.`);
        return loadReservations();
      })
      .catch(err => { showToast(err.message); return loadReservations(); })
      .finally(() => { activeActions.delete(id); applyFilters(); });
  };

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('statusFilter').addEventListener('change', applyFilters);
  document.getElementById('previousMonth').addEventListener('click', () => {
    calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
    renderCalendar();
  });
  document.getElementById('nextMonth').addEventListener('click', () => {
    calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
    renderCalendar();
  });
  document.getElementById('clearCalendarDate').addEventListener('click', () => {
    selectedDate = '';
    renderCalendar();
    applyFilters();
  });
  document.getElementById('retryReservations').addEventListener('click', () => {
    const loadingMsg = document.getElementById('loadingMsg');
    loadingMsg.textContent = 'Loading reservations…';
    loadingMsg.classList.remove('error-msg');
    document.getElementById('retryReservations').hidden = true;
    loadReservations();
  });

  renderCalendar();
  loadReservations();

  document.getElementById('calendarBasis').addEventListener('change', () => { selectedDate = ''; renderCalendar(); applyFilters(); });
  const staffPickupPicker = reservationScheduling.createPicker(async (target, pickupAt) => {
    const response = await fetch('backend/reservation-action.php', {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrfToken},
      body:JSON.stringify({id:target.reservation.id, action:'schedule', pickup_at:pickupAt})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Could not change the schedule.');
    showToast('Preferred pickup updated. A copy is held only after Mark Ready.');
    loadReservations();
  });
  window.scheduleReservation = id => {
    const reservation = allReservations.find(row => Number(row.id) === id);
    if (reservation?.status === 'waiting') staffPickupPicker.open({reservation}, 'Set preferred pickup', 'Save schedule');
  };
  window.showReservationDetails = id => {
    const row = allReservations.find(item => Number(item.id) === id);
    if (!row) return;
    const details = [['Borrower',row.student_name],['School ID',row.student_id || '—'],['Book',row.book_title],
      ['Author',row.author],['Accession',row.accession_number || '—'],['Status',borrowing.label(row.status)],
      ['Submitted',formatDate(row.reserved_at)],['Pickup',formatDate(row.pickup_at)],['Ready',formatDate(row.ready_at)],
      ['Checked out',formatDate(row.fulfilled_at)],['Due',formatDate(row.due_date)],['Returned',formatDate(row.returned_at)]];
    document.getElementById('reservationDetailsBody').innerHTML = details.map(([label,value]) =>
      `<dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd>`).join('');
    document.getElementById('reservationDetailsDialog').showModal();
  };
  document.getElementById('closeReservationDetails').addEventListener('click', () => document.getElementById('reservationDetailsDialog').close());
  document.getElementById('pickupSettingsForm').addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('savePickupSettings');
    if (button.disabled || !await window.libraryUI.confirm('Save these pickup capacity limits for the library? Blank means unlimited.', {title:'Save capacity settings',confirmLabel:'Save settings'})) return;
    button.disabled = true;
    const message = document.getElementById('pickupSettingsMessage');
    try {
      const response = await fetch('backend/reservation-settings.php', {method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrfToken}, body:JSON.stringify({
          daily_limit:document.getElementById('dailyLimit').value, slot_limit:document.getElementById('slotLimit').value})});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not save pickup capacity.');
      message.textContent = 'Pickup capacity saved. Existing schedules and inventory are unchanged.';
      await loadReservations();
    } catch (exception) { message.textContent = exception.message; }
    finally { button.disabled = false; }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !document.querySelector('dialog[open]')) loadReservations(); });
