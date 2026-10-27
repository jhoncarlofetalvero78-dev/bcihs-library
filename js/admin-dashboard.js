
  // ---- Guard: only admins and approved librarians may view this page ----
  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) {
        window.location.href = 'admin-login.html';
        return;
      }
      const name = data.username || 'Admin';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
      document.getElementById('adminRole').textContent =
        data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
    })
    .catch(() => { /* backend unreachable — leave the generic Admin User label */ });

  // One snapshot supplies cards and the chart from the same reporting definitions.
  fetch('backend/dashboard-data.php', { credentials:'same-origin', cache:'no-store' })
    .then(response => response.ok ? response.json() : Promise.reject())
    .then(data => {
      const current=data.current;
      document.getElementById('totalUsersValue').textContent=current.users.toLocaleString();
      document.getElementById('totalBooksValue').textContent=current.copies.toLocaleString();
      document.getElementById('borrowedBooksValue').textContent=current.borrowed_copies.toLocaleString();
      document.getElementById('availableBooksValue').textContent=current.available_copies.toLocaleString();
      document.getElementById('activeReservationsValue').textContent=(current.waiting+current.ready).toLocaleString();
      document.getElementById('availableBooksHint').textContent=`${current.requestable_copies} available for requests · ${current.held_copies} held for pickup`;
      document.getElementById('dashboardScope').textContent=`Current snapshot · ${data.generatedAt} PHT · ${current.titles} catalog titles · ${current.accepted_students} accepted students`;
      document.getElementById('circulationSummary').textContent=`${current.pending} pending requests · ${current.awaiting_pickup} approved / awaiting pickup · ${current.overdue} overdue loans · Reservations: ${current.waiting} waiting, ${current.ready} ready · Today: ${current.attendance_today.visits} attendance visits, ${current.attendance_today.registered_students} unique registered students · Occupancy: ${current.occupancy === null ? 'not tracked' : current.occupancy}`;
      document.getElementById('dashboardWarning').hidden=current.inventory_matches_loans && !current.undated_loan_records;
      renderBookInventoryChart(current);
    }).catch(() => {
      ['totalUsersValue','totalBooksValue','borrowedBooksValue','availableBooksValue','activeReservationsValue'].forEach(id=>{document.getElementById(id).textContent='—';});
      document.getElementById('dashboardScope').textContent='Could not load current statistics. Reload to retry.';
      document.getElementById('circulationSummary').textContent='';
      document.getElementById('availableBooksHint').textContent='Current capacity unavailable.';
    });

  // ---- Real data: Active and recent Reservations ----
  fetch('backend/reservations-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      const reservations = data.reservations || [];

      const recent = reservations.sort((a,b) => String(b.reserved_at).localeCompare(String(a.reserved_at)) || Number(b.id)-Number(a.id)).slice(0, 6);
      const body = document.getElementById('reservationBody');
      if (recent.length === 0) {
        body.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#888;">No reservations yet.</td></tr>';
        return;
      }
      body.innerHTML = recent.map(r => `
        <tr>
          <td>${escapeHtml(r.student_name)}</td>
          <td>${escapeHtml(r.book_title)}</td>
          <td>${r.queue_position ? `#${r.queue_position}` : '—'}</td>
          <td>${formatDate(r.reserved_at)}</td>
          <td><span class="status-pill ${r.status}">${borrowing.label(r.status)}</span></td>
        </tr>
      `).join('');
      })
    .catch(() => {
      document.getElementById('reservationBody').innerHTML =
        '<tr><td colspan="5" style="text-align:center; padding:24px; color:#888;">Could not load reservations.</td></tr>';
    });

  // ---- Real data: 6 most recent Borrow Requests ----
  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = borrowing.date(mysqlDatetime);
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });
  }
  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  fetch('backend/borrow-requests-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      const recent = (data.requests || []).slice(0, 6);
      const body = document.getElementById('borrowRequestBody');
      if (recent.length === 0) {
        body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#888;">No borrow requests yet.</td></tr>';
        return;
      }
      body.innerHTML = recent.map(r => `
        <tr>
          <td>${escapeHtml(r.student_name)}</td>
          <td>${escapeHtml(r.book_title)}</td>
          <td>${formatDate(r.requested_at)}</td>
          <td><span class="status-pill ${r.status}">${borrowing.label(r.status)}</span></td>
        </tr>
      `).join('');
    })
    .catch(() => {
      document.getElementById('borrowRequestBody').innerHTML =
        '<tr><td colspan="4" style="text-align:center; padding:24px; color:#888;">Could not load borrow requests.</td></tr>';
    });

  // ---- Real data: Announcements ----
  fetch('backend/announcements-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      const list = (data.announcements || []).slice(0, 5);
      const holder = document.getElementById('announcementList');
      if (list.length === 0) {
        holder.innerHTML = '<p style="color:#888; font-size:0.85rem; text-align:center; padding:12px;">No announcements yet.</p>';
        return;
      }
      holder.innerHTML = list.map(a => `
        <div class="announcement-item">
          <h4>${escapeHtml(a.title)}</h4>
          <div class="date">${formatDate(a.created_at)}</div>
          ${a.image_path ? `<img class="announcement-photo" src="${escapeHtml(a.image_path)}" alt="Photo for ${escapeHtml(a.title)}">` : ''}
          <p>${escapeHtml(a.body)}</p>
        </div>
      `).join('');
    })
    .catch(() => {
      document.getElementById('announcementList').innerHTML =
        '<p style="color:#888; font-size:0.85rem; text-align:center; padding:12px;">Could not load announcements.</p>';
    });

  // ---- "Coming soon" toast for nav items / actions with no backend yet ----
  const toast = document.getElementById('toast');
  let toastTimer;
  function showToast(label) {
    toast.textContent = `${label} isn't wired up yet — coming soon.`;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
  }
  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => showToast(el.dataset.soon));
  });

  // Book chart rendering
  let bookInventoryChart = null;
  function renderBookInventoryChart(current) {
    const ctx=document.getElementById('bookInventoryChart');
    if (!ctx) return;
    if (typeof Chart==='undefined') {
      ctx.parentElement.innerHTML='<p class="empty-msg">The chart library is unavailable. Current totals remain available above and in Reports.</p>';return;
    }
    if (bookInventoryChart) bookInventoryChart.destroy();
    bookInventoryChart=new Chart(ctx,{
      type:'bar',data:{labels:['Borrowed','Held for pickup','Available for requests'],datasets:[{label:'Current copies',
        data:[current.borrowed_copies,current.held_copies,current.requestable_copies],backgroundColor:['#8b2525','#bd7d31','#637d58'],borderRadius:6}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
    });
  }
