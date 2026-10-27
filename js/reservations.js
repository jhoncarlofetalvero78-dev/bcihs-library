
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
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
  let calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
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
    const today = new Date();
    const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const counts = {};

    allReservations.forEach(reservation => {
      const key = reservationDateKey(reservation.reserved_at);
      if (key) counts[key] = (counts[key] || 0) + 1;
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
      grid.insertAdjacentHTML('beforeend', `
        <button type="button" class="${classes.join(' ')}" data-date="${key}" aria-pressed="${key === selectedDate}" aria-label="${dateLabel}${count ? `, ${count} reservation${count === 1 ? '' : 's'}` : ', no reservations'}">
          ${day}${count ? `<span class="calendar-day-count">${count} res.</span>` : ''}
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
      ? `Showing reservations from ${selected.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })}.`
      : 'Showing reservations from all dates.';
    document.getElementById('clearCalendarDate').hidden = !selectedDate;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = borrowing.date(mysqlDatetime);
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function renderRows(reservations) {
    const body = document.getElementById('reservationsBody');
    if (reservations.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="empty-msg">No reservations match.</td></tr>';
      return;
    }
    body.innerHTML = reservations.map(r => `
      <tr>
        <td>${escapeHtml(r.student_name)}</td>
        <td>${escapeHtml(r.book_title)}</td>
        <td>${r.queue_position ? `<span class="queue-pos">#${r.queue_position}</span>` : '—'}</td>
        <td>${formatDate(r.reserved_at)}</td>
        <td>${r.pickup_at ? formatDate(r.pickup_at) : 'Not scheduled'}</td>
        <td><span class="status-pill ${r.status}">${borrowing.label(r.status)}</span></td>
        <td>
          <button class="action-btn ready" title="${!r.pickup_at && r.status === 'waiting' ? 'User must choose a pickup time first' : 'Mark Ready'}" ${r.status !== 'waiting' || !r.pickup_at ? 'disabled' : ''} onclick="act(${r.id}, 'ready')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
          </button>
          <button class="action-btn fulfill" title="Confirm physical checkout" aria-label="Confirm Checkout" ${!['waiting','ready'].includes(r.status) ? 'disabled' : ''} onclick="act(${r.id}, 'fulfill')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
          </button>
          <button class="action-btn fulfill" title="Confirm physical return" aria-label="Confirm Return" ${r.status !== 'fulfilled' ? 'disabled' : ''} onclick="act(${r.id}, 'return')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h9a5 5 0 0 1 5 5v1"/></svg>
          </button>
          <button class="action-btn cancel" title="Cancel" ${!['waiting','ready'].includes(r.status) ? 'disabled' : ''} onclick="act(${r.id}, 'cancel')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M6 6l12 12M18 6L6 18"/></svg>
          </button>
        </td>
      </tr>
    `).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const filtered = allReservations.filter(r => {
      if (selectedDate && reservationDateKey(r.reserved_at) !== selectedDate) return false;
      if (status && r.status !== status) return false;
      if (!q) return true;
      return [r.student_name, r.book_title].join(' ').toLowerCase().includes(q);
    });
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allReservations.length} reservation${allReservations.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function loadReservations() {
    return fetch('backend/reservations-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
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

  window.act = function(id, action) {
    if (activeActions.has(id)) return;
    const prompts = {ready:'Hold an available copy for this reservation? This does not check it out.', fulfill:'Confirm physical handover and start this loan now?', return:'Confirm the physical book has been returned?', cancel:'Cancel this reservation and release any hold?'};
    if (!prompts[action] || !confirm(prompts[action])) return;
    activeActions.add(id);
    const labels = { ready: 'marked ready', fulfill: 'fulfilled', cancel: 'cancelled', return: 'marked returned' };
    fetch('backend/reservation-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not update that reservation.');
        return data;
      })
      .then(() => {
        showToast(`Reservation ${labels[action]}.`);
        loadReservations();
      })
      .catch(err => showToast(err.message))
      .finally(() => activeActions.delete(id));
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
