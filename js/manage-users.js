
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

  let allUsers = [];

  function findUser(id) {
    return allUsers.find(user => String(user.id) === String(id));
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function roleGroup(role) {
    if (role === 'employee') return 'teacher';
    return role;
  }
  const roleLabels = { student: 'Student', teacher: 'Teacher', librarian: 'Librarian', visitor: 'Visitor' };
  const rolePrefix = { student: 'STU', teacher: 'TCH', librarian: 'LIB', visitor: 'VIS' };

  function detailsFor(u) {
    if (u.role === 'student') return u.year_section || '—';
    if (u.role === 'teacher' || u.role === 'employee' || u.role === 'librarian') return u.department || '—';
    return u.email || 'Guest Access';
  }

  function accountStatusLabel(status) {
    return status === 'accepted' ? 'Approved' : status;
  }

  function renderRows(users) {
    const body = document.getElementById('usersBody');
    if (users.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="empty-msg">No users found.</td></tr>';
      return;
    }
    body.innerHTML = users.map(u => {
      const group = roleGroup(u.role);
      const userId = (u.student_id || '') + (u.id_number || '');
      return `
        <tr>
          <td>${userId || '—'}</td>
          <td>${escapeHtml(u.full_name)}</td>
          <td><span class="type-pill ${escapeHtml(u.role)}">${escapeHtml(roleLabels[group] || u.role)}</span></td>
          <td>${escapeHtml(detailsFor(u))}</td>
          <td>${escapeHtml(u.phone) || '—'}</td>
          <td><span class="status-pill ${escapeHtml(u.account_status)}">${escapeHtml(accountStatusLabel(u.account_status).toUpperCase())}</span></td>
          <td>
            ${u.account_status !== 'accepted' ? `<button class="action-btn approve" title="Approve account" onclick="setAccountStatus(${u.id}, 'accepted')">Approve</button>` : ''}
            <button class="action-btn reject" title="Reject account" onclick="setAccountStatus(${u.id}, 'rejected')" ${u.account_status === 'accepted' || u.account_status === 'rejected' ? 'disabled' : ''}>Reject</button>
            <button class="action-btn view" title="View" onclick="viewUser(${u.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <button class="action-btn delete" title="Delete" onclick="deleteUser(${u.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
            </button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const role = document.getElementById('roleFilter').value;
    const filtered = allUsers.filter(u => {
      if (role && roleGroup(u.role) !== role) return false;
      if (!q) return true;
      const haystack = [u.full_name, (u.student_id || '') + (u.id_number || '')]
        .filter(Boolean).join(' ').toLowerCase();
      return haystack.includes(q);
    });
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allUsers.length} user${allUsers.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function formatDateTime(mysqlDatetime) {
    if (!mysqlDatetime) return 'Never';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  window.viewUser = function(id) {
    const u = findUser(id);
    if (!u) return;
    const group = roleGroup(u.role);
    const userId = (u.student_id || '') + (u.id_number || '');

    let rows = [
      ['User ID', userId || '—'],
      ['Full Name', u.full_name || '—'],
      ['Role', roleLabels[group] || u.role],
    ];

    if (group === 'student') {
      rows.push(['Year/Section', u.year_section || '—']);
    } else if (group === 'teacher' || group === 'librarian') {
      rows.push(['Department', u.department || '—']);
    } else {
      rows.push(['Email', u.email || '—']);
    }

    rows.push(
      ['Phone', u.phone || '—'],
      ['Account Status', accountStatusLabel(u.account_status) || '—'],
      ['Last Login', formatDateTime(u.last_login)],
      ['Member Since', formatDate(u.created_at)]
    );

    document.getElementById('modalBody').innerHTML = rows.map(([label, value]) => `
      <div class="detail-row"><span class="label">${label}</span><span class="value">${escapeHtml(value)}</span></div>
    `).join('');
    document.getElementById('modalOverlay').classList.add('show');
  };

  document.getElementById('modalCloseBtn').addEventListener('click', () => {
    document.getElementById('modalOverlay').classList.remove('show');
  });
  document.getElementById('modalOverlay').addEventListener('click', (e) => {
    if (e.target.id === 'modalOverlay') e.target.classList.remove('show');
  });

  window.deleteUser = function(id) {
    const u = findUser(id);
    if (!u) return;
    if (!confirm(`Delete "${u.full_name}"? This also removes their borrow request history and can't be undone.`)) return;

    fetch('backend/user-delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id })
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) { showToast('User deleted.'); loadUsers(); }
        else { showToast(data.error || 'Could not delete that user.'); }
      })
      .catch(() => showToast('Could not reach the server.'));
  };

  window.setAccountStatus = function(id, status) {
    const user = findUser(id);
    if (!user || !['accepted', 'rejected'].includes(status)) return;
    if (status === 'rejected' && !confirm(`Reject ${user.full_name}'s account? They will not be able to sign in.`)) return;

    fetch('backend/user-approval-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, status })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not update the account.');
        return data;
      })
      .then(data => {
        if (!data.success) throw new Error(data.error || 'Could not update the account.');
        if (data.status !== status) throw new Error('Account status was not updated.');
        showToast(`Account ${status === 'accepted' ? 'approved' : status}.`);
        loadUsers();
      })
      .catch(err => showToast(err.message || 'Could not reach the server.'));
  };

  // ---- SVG bar chart: new registrations per month, grouped by role ----
  function renderChart(users) {
    const colors = { student: '#2f8fe0', teacher: '#e8792f', librarian: '#6b2fd0', visitor: '#a52fe0' };
    const months = [];
    const now = new Date();
    for (let i = 5; i >= 0; i--) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      months.push({ key: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'), label: d.toLocaleDateString(undefined, { month: 'short' }) });
    }

    const counts = { student: months.map(() => 0), teacher: months.map(() => 0), librarian: months.map(() => 0), visitor: months.map(() => 0) };
    users.forEach(u => {
      const group = roleGroup(u.role);
      if (!counts[group]) return;
      const key = (u.created_at || '').slice(0, 7);
      const idx = months.findIndex(m => m.key === key);
      if (idx !== -1) counts[group][idx]++;
    });

    const maxVal = Math.max(1, ...Object.values(counts).flat());
    const roles = Object.keys(counts);

    const svgW = 460, svgH = 210;
    const padL = 42, padB = 34, padT = 14, padR = 12;
    const plotW = svgW - padL - padR, plotH = svgH - padT - padB;
    const groupCount = months.length;
    const slotW = plotW / groupCount;
    const barW = slotW / (roles.length + 2);

    const gridLines = [0, 0.25, 0.5, 0.75, 1].map(f => {
      const y = padT + plotH - f * plotH;
      const val = Math.round(f * maxVal);
      return `<line x1="${padL}" y1="${y}" x2="${svgW - padR}" y2="${y}" stroke="#00000014" stroke-width="1"/>
              <text x="${padL - 6}" y="${y + 4}" font-size="9" fill="#666" text-anchor="end">${val}</text>`;
    }).join('');

    const monthLabels = months.map((m, i) => {
      const x = padL + i * slotW + slotW / 2;
      return `<text x="${x}" y="${svgH - 6}" font-size="9" fill="#666" text-anchor="middle">${m.label}</text>`;
    }).join('');

    const bars = months.map((m, i) => {
      const startX = padL + i * slotW + barW;
      return roles.map((role, j) => {
        const val = counts[role][i];
        const h = (val / maxVal) * plotH;
        const x = startX + j * barW;
        const y = padT + plotH - h;
        const tooltip = `${role[0].toUpperCase() + role.slice(1)} · ${m.label}: ${val} registration${val === 1 ? '' : 's'}`;
        return `<rect x="${x}" y="${y}" width="${barW * 0.85}" height="${h === 0 ? 0 : Math.max(h, 1)}" fill="${colors[role]}" rx="3" ry="3" ${val > 0 ? `data-tooltip="${tooltip}" tabindex="0" aria-label="${tooltip}" style="--bar-delay:${(i * roles.length + j) * 35}ms"` : ''}/>`;
      }).join('');
    }).join('');

    document.getElementById('chartHolder').innerHTML = `
      <svg viewBox="0 0 ${svgW} ${svgH}" style="width:100%; height:auto;">
        ${gridLines}
        ${bars}
        ${monthLabels}
      </svg>
      <div id="registrationChartTooltip" role="status" hidden></div>
    `;

    const chartHolder = document.getElementById('chartHolder');
    const tooltip = chartHolder.querySelector('#registrationChartTooltip');
    const showTooltip = (bar, x, y) => {
      tooltip.textContent = bar.dataset.tooltip;
      tooltip.hidden = false;
      const padding = 12;
      tooltip.style.left = `${Math.max(padding, Math.min(x + padding, window.innerWidth - tooltip.offsetWidth - padding))}px`;
      tooltip.style.top = `${Math.max(padding, Math.min(y + padding, window.innerHeight - tooltip.offsetHeight - padding))}px`;
    };
    chartHolder.querySelectorAll('rect[data-tooltip]').forEach(bar => {
      bar.addEventListener('mouseenter', event => showTooltip(bar, event.clientX, event.clientY));
      bar.addEventListener('mousemove', event => showTooltip(bar, event.clientX, event.clientY));
      bar.addEventListener('mouseleave', () => { tooltip.hidden = true; });
      bar.addEventListener('focus', () => {
        const rect = bar.getBoundingClientRect();
        showTooltip(bar, rect.left + rect.width / 2, rect.top + rect.height / 2);
      });
      bar.addEventListener('blur', () => { tooltip.hidden = true; });
    });
  }

  function loadUsers() {
    return fetch('backend/all-users-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
        allUsers = data.users || [];

        const group = { student: 0, teacher: 0, librarian: 0, visitor: 0 };
        let active = 0;
        allUsers.forEach(u => {
          const g = roleGroup(u.role);
          if (group[g] !== undefined) group[g]++;
          if (u.last_login) active++;
        });
        document.getElementById('countStudent').textContent = group.student;
        document.getElementById('countTeacher').textContent = group.teacher;
        document.getElementById('countLibrarian').textContent = group.librarian;
        document.getElementById('countVisitor').textContent = group.visitor;
        document.getElementById('countActive').textContent = active;

        renderChart(allUsers);

        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('usersTable').style.display = 'table';
        document.getElementById('countBadge').style.display = 'block';
        applyFilters();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') { window.location.href = 'admin-login.html'; return; }
        document.getElementById('loadingMsg').textContent = 'Something went wrong loading users.';
      });
  }

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('roleFilter').addEventListener('change', applyFilters);

loadUsers();
