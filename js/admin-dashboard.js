
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

  // ---- Real data: Total Users, pulled from the same source All Users uses ----
  fetch('backend/all-users-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      document.getElementById('totalUsersValue').textContent = (data.users || []).length.toLocaleString();
    })
    .catch(() => {
      document.getElementById('totalUsersValue').textContent = '—';
    });

  // ---- Real data: Total/Borrowed/Available Books, pulled from the books table ----
  fetch('backend/books-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      const books = data.books || [];
      const total = books.reduce((sum, b) => sum + Number(b.total_copies), 0);
      const borrowed = books.reduce((sum, b) => sum + Number(b.borrowed_copies), 0);
      document.getElementById('totalBooksValue').textContent = total.toLocaleString();
      document.getElementById('borrowedBooksValue').textContent = borrowed.toLocaleString();
      document.getElementById('availableBooksValue').textContent = (total - borrowed).toLocaleString();
    })
    .catch(() => {
      document.getElementById('totalBooksValue').textContent = '—';
      document.getElementById('borrowedBooksValue').textContent = '—';
      document.getElementById('availableBooksValue').textContent = '—';
    });

  // ---- Real data: Monthly book stats for chart ----
  fetch('backend/book-monthly-stats.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      renderBookInventoryChart(data);
    })
    .catch(() => {
      document.getElementById('bookInventoryChart').parentElement.innerHTML = '<p class="empty-msg">Could not load chart data.</p>';
    });

  // ---- Real data: Active and recent Reservations ----
  fetch('backend/reservations-data.php', { credentials: 'same-origin' })
    .then(res => res.ok ? res.json() : Promise.reject())
    .then(data => {
      const reservations = data.reservations || [];
      const active = reservations.filter(r => ['waiting', 'ready'].includes(r.status));
      document.getElementById('activeReservationsValue').textContent = active.length.toLocaleString();

      const recent = reservations.slice(0, 6);
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
          <td><span class="status-pill ${r.status}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></td>
        </tr>
      `).join('');
      })
    .catch(() => {
      document.getElementById('activeReservationsValue').textContent = '—';
      document.getElementById('reservationBody').innerHTML =
        '<tr><td colspan="5" style="text-align:center; padding:24px; color:#888;">Could not load reservations.</td></tr>';
    });

  // ---- Real data: 6 most recent Borrow Requests ----
  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
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
          <td><span class="status-pill ${r.status}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></td>
        </tr>
      `).join('');
    })
    .catch(() => {
      document.getElementById('borrowRequestBody').innerHTML =
        '<tr><td colspan="4" style="text-align:center; padding:24px; color:#888;">Could not load borrow requests.</td></tr>';
    });

  // ---- Real data: Announcements ----
  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }
  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

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
  function renderBookInventoryChart(stats) {
    const months = stats.months || [];
    const totalData = stats.total || [];
    const borrowedData = stats.borrowed || [];
    const availableData = stats.available || [];
    const ctx = document.getElementById('bookInventoryChart');
    if (!ctx) return;
    if (bookInventoryChart) bookInventoryChart.destroy();
    bookInventoryChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: months,
        datasets: [
          {
            label: 'Total',
            data: totalData,
            backgroundColor: 'rgba(47, 143, 224, 0.75)',
            borderColor: 'rgb(47, 143, 224)',
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
          },
          {
            label: 'Borrowed',
            data: borrowedData,
            backgroundColor: 'rgba(224, 48, 58, 0.75)',
            borderColor: 'rgb(224, 48, 58)',
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
          },
          {
            label: 'Available',
            data: availableData,
            backgroundColor: 'rgba(47, 184, 74, 0.75)',
            borderColor: 'rgb(47, 184, 74)',
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
          duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 900,
          easing: 'easeOutQuart',
        },
        interaction: {
          mode: 'index',
          intersect: false,
        },
        plugins: {
          legend: {
            position: 'top',
            labels: { font: { size: 11 }, color: '#333' }
          },
          tooltip: {
            backgroundColor: '#3d0a0a',
            titleColor: '#fff',
            bodyColor: '#fff',
            padding: 12,
            cornerRadius: 8,
            callbacks: {
              label: context => `${context.dataset.label}: ${context.formattedValue} ${Number(context.raw) === 1 ? 'book' : 'books'}`,
            },
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: '#f0eeec' },
            ticks: { color: '#666', font: { size: 11 } }
          },
          x: {
            grid: { display: false },
            ticks: { color: '#333', font: { size: 11 } }
          }
        }
      }
    });
  }
