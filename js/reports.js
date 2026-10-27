
  const loadingMsg = document.getElementById('loadingMsg');
  const reportContent = document.getElementById('reportContent');

  if (window.location.protocol === 'file:') {
    loadingMsg.textContent = 'Please open this page through your local web server (for example http://localhost/bcihs-library) so the admin session can load.';
    throw new Error('file_protocol_blocked');
  }

  function setAdminInfo(data) {
    const name = data.username || 'Admin';
    document.getElementById('adminName').textContent = name;
    document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
  }

  function handleNotLoggedIn() {
    window.location.href = 'admin-login.html';
  }

  function fetchJson(url, options = {}) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options })
      .then(async res => {
        const text = await res.text();
        let data = {};
        if (text) {
          try {
            data = JSON.parse(text);
          } catch (error) {
            throw new Error(text || 'Invalid JSON response');
          }
        }
        if (res.status === 401 || res.status === 403) {
          throw new Error('not_logged_in');
        }
        if (!res.ok) {
          throw new Error(data.error || 'Request failed');
        }
        return data;
      });
  }

  fetchJson('backend/admin-session-status.php')
    .then(data => {
      if (!data.loggedIn) { handleNotLoggedIn(); return; }
      setAdminInfo(data);
    })
    .catch(err => {
      if (err.message === 'not_logged_in') {
        handleNotLoggedIn();
        return;
      }
      if (err.message === 'file_protocol_blocked') {
        return;
      }
    });

  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => alert(`${el.dataset.soon} isn't wired up yet - coming soon.`));
  });

  document.getElementById('printBtn').addEventListener('click', () => window.print());

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function roleGroup(role) {
    if (role === 'employee') return 'teacher';
    return role;
  }

  function renderTopBooks(borrowings) {
    const countByBook = {};
    const titleByBook = {};
    borrowings.forEach(borrowing => {
      countByBook[borrowing.book_id] = (countByBook[borrowing.book_id] || 0) + 1;
      titleByBook[borrowing.book_id] = borrowing.book_title;
    });
    const ranked = Object.keys(countByBook)
      .map(bookId => ({ title: titleByBook[bookId], count: countByBook[bookId] }))
      .sort((a, b) => b.count - a.count)
      .slice(0, 8);

    const holder = document.getElementById('topBooksHolder');
    if (ranked.length === 0) {
      holder.innerHTML = '<p class="empty-msg">No books have been borrowed yet.</p>';
      return;
    }
    const maxCount = Math.max(...ranked.map(b => b.count));
    holder.innerHTML = ranked.map((b, index) => `
      <div class="bar-row">
        <div class="bar-label" title="${escapeHtml(b.title)}">${escapeHtml(b.title)}</div>
        <div class="bar-track"><div class="bar-fill" data-tooltip="${escapeHtml(b.title)}: ${b.count} borrow${b.count === 1 ? '' : 's'}" tabindex="0" aria-label="${escapeHtml(b.title)}: ${b.count} borrow${b.count === 1 ? '' : 's'}" style="width:${(b.count / maxCount) * 100}%; --bar-delay:${index * 90}ms"></div></div>
        <div class="bar-count">${b.count}</div>
      </div>
    `).join('') + '<div id="topBooksTooltip" role="status" hidden></div>';

    const tooltip = holder.querySelector('#topBooksTooltip');
    const showTooltip = (bar, x, y) => {
      tooltip.textContent = bar.dataset.tooltip;
      tooltip.hidden = false;
      const padding = 12;
      tooltip.style.left = `${Math.max(padding, Math.min(x + padding, window.innerWidth - tooltip.offsetWidth - padding))}px`;
      tooltip.style.top = `${Math.max(padding, Math.min(y + padding, window.innerHeight - tooltip.offsetHeight - padding))}px`;
    };
    holder.querySelectorAll('.bar-fill').forEach(bar => {
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

  function renderBorrowings(borrowings) {
    const body = document.getElementById('borrowingsBody');
    if (borrowings.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="empty-msg">No books were checked out during this date range.</td></tr>';
      return;
    }
    body.innerHTML = borrowings.map(borrowing => `
      <tr>
        <td>${escapeHtml(borrowing.borrower_name)}</td>
        <td>${escapeHtml(borrowing.book_title)}</td>
        <td>${formatDate(borrowing.borrowed_at)}</td>
        <td>${formatDate(borrowing.due_date)}</td>
        <td>${formatDate(borrowing.returned_at)}</td>
        <td>${formatBorrowingPeriod(borrowing.borrowed_at, borrowing.returned_at)}</td>
        <td><span class="borrowing-status ${borrowing.status}">${borrowing.status === 'returned' ? 'Returned' : 'Currently Borrowed'}</span></td>
      </tr>
    `).join('');
  }

  function renderOverdueBooks(borrowings) {
    const today = getLocalDate();
    const overdue = borrowings.filter(borrowing =>
      borrowing.status !== 'returned' &&
      borrowing.due_date &&
      String(borrowing.due_date).slice(0, 10) < today
    );
    const body = document.getElementById('overdueBody');
    if (overdue.length === 0) {
      body.innerHTML = '<tr><td colspan="5" class="empty-msg">No overdue books for this date range.</td></tr>';
      return;
    }
    body.innerHTML = overdue.map(borrowing => `
      <tr>
        <td>${escapeHtml(borrowing.borrower_name)}</td>
        <td>${escapeHtml(borrowing.book_title)}</td>
        <td>${formatDate(borrowing.borrowed_at)}</td>
        <td>${formatDate(borrowing.due_date)}</td>
        <td>${daysBetween(borrowing.due_date, today)} days</td>
      </tr>
    `).join('');
  }

  function renderUsers(users) {
    const body = document.getElementById('usersBody');
    if (users.length === 0) {
      body.innerHTML = '<tr><td colspan="4" class="empty-msg">No registered users for this date range.</td></tr>';
      return;
    }
    body.innerHTML = users.map(user => `
      <tr>
        <td>${escapeHtml(user.full_name)}</td>
        <td>${escapeHtml(roleGroup(user.role) || user.role)}</td>
        <td>${escapeHtml(user.year_section || '-')}</td>
        <td>${formatDate(user.created_at)}</td>
      </tr>
    `).join('');
  }

  function renderInventory(books) {
    const body = document.getElementById('inventoryBody');
    if (books.length === 0) {
      body.innerHTML = '<tr><td colspan="5" class="empty-msg">No books in the inventory.</td></tr>';
      return;
    }
    body.innerHTML = books.map(book => {
      const total = Number(book.total_copies) || 0;
      const borrowed = Number(book.borrowed_copies) || 0;
      return `
        <tr>
          <td>${escapeHtml(book.title)}</td>
          <td>${escapeHtml(book.author)}</td>
          <td>${total.toLocaleString()}</td>
          <td>${borrowed.toLocaleString()}</td>
          <td>${Math.max(0, total - borrowed).toLocaleString()}</td>
        </tr>
      `;
    }).join('');
  }

  function renderActivitySummary(requests) {
    const approved = requests.filter(r => r.status === 'approved').length;
    const returned = requests.filter(r => r.status === 'returned').length;
    const rejected = requests.filter(r => r.status === 'rejected').length;
    const pending = requests.filter(r => r.status === 'pending').length;
    const decided = approved + returned + rejected;
    const approvalRate = decided > 0 ? Math.round(((approved + returned) / decided) * 100) : 0;

    const durations = requests
      .filter(r => r.status === 'returned' && r.approved_at && r.returned_at)
      .map(r => Math.max(0, Math.round((new Date(r.returned_at) - new Date(r.approved_at)) / 86400000)));
    const avgDuration = durations.length > 0
      ? Math.round(durations.reduce((a, b) => a + b, 0) / durations.length)
      : null;

    const rows = [
      ['Pending Requests', pending],
      ['Approval Rate', decided > 0 ? approvalRate + '%' : '-'],
      ['Currently Borrowed', approved],
      ['Total Returned', returned],
      ['Total Rejected', rejected],
      ['Avg. Borrow Duration', avgDuration !== null ? avgDuration + ' day' + (avgDuration === 1 ? '' : 's') : '-'],
    ];

    document.getElementById('activitySummary').innerHTML = rows.map(([label, value]) => `
      <div class="summary-row"><span class="label">${label}</span><span class="value">${value}</span></div>
    `).join('');
  }

  function renderChart(users) {
    const colors = { student: '#2f8fe0', teacher: '#e8792f', librarian: '#6b2fd0', visitor: '#a52fe0' };
    const labels = { student: 'Students', teacher: 'Teachers', librarian: 'Librarian', visitor: 'Visitors' };
    const counts = { student: 0, teacher: 0, librarian: 0, visitor: 0 };
    users.forEach(u => {
      const group = roleGroup(u.role);
      if (group && Object.prototype.hasOwnProperty.call(counts, group)) counts[group]++;
    });

    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const chartHolder = document.getElementById('chartHolder');
    const chartLegend = document.getElementById('chartLegend');
    if (total === 0) {
      chartHolder.innerHTML = '<p class="empty-msg">No registered patrons to display.</p>';
      chartLegend.innerHTML = '';
      return;
    }

    const cx = 160, cy = 120, radius = 96;
    let angle = -Math.PI / 2;
    let selectedGroup = null;
    const visibleGroups = Object.keys(counts).filter(group => counts[group] > 0);
    const slices = visibleGroups.map(group => {
      const startAngle = angle;
      angle += (counts[group] / total) * Math.PI * 2;
      const endAngle = angle;
      const startX = cx + radius * Math.cos(startAngle);
      const startY = cy + radius * Math.sin(startAngle);
      const endX = cx + radius * Math.cos(endAngle);
      const endY = cy + radius * Math.sin(endAngle);
      const middleAngle = (startAngle + endAngle) / 2;
      const offsetX = Math.cos(middleAngle) * 14;
      const offsetY = Math.sin(middleAngle) * 14;

      let path;
      if (visibleGroups.length === 1) {
        path = `M ${cx} ${cy - radius} A ${radius} ${radius} 0 1 1 ${cx} ${cy + radius} A ${radius} ${radius} 0 1 1 ${cx} ${cy - radius} Z`;
      } else {
        const largeArc = endAngle - startAngle > Math.PI ? 1 : 0;
        path = `M ${cx} ${cy} L ${startX} ${startY} A ${radius} ${radius} 0 ${largeArc} 1 ${endX} ${endY} Z`;
      }

      const percentage = Math.round((counts[group] / total) * 100);
      return `<path data-role="${group}" data-offset-x="${offsetX}" data-offset-y="${offsetY}" d="${path}" fill="${colors[group]}" stroke="#fff" stroke-width="2"><title>${labels[group]}: ${percentage}% (${counts[group]} registered)</title></path>`;
    }).join('');

    chartHolder.innerHTML = `
      <svg viewBox="0 0 320 240" role="img" aria-label="Registered patrons by role" tabindex="0" style="width:min(100%, 320px); height:auto; margin:0 auto;">
        <g class="pie-chart-slices">${slices}</g>
      </svg>
      <div id="chartTooltip" role="status" hidden></div>
    `;

    chartLegend.innerHTML = Object.keys(colors).filter(g => counts[g] > 0).map(g => `
      <button type="button" class="role-filter-button" data-role="${g}" aria-pressed="false">
        <span style="width:10px; height:10px; border-radius:50%; background:${colors[g]}; display:inline-block;"></span>
        ${labels[g]}: ${Math.round((counts[g] / total) * 100)}% (${counts[g]} registered)
      </button>
    `).join('');

    const tooltip = chartHolder.querySelector('#chartTooltip');
    const showTooltip = (slice, x, y) => {
      const group = slice.dataset.role;
      tooltip.textContent = `${labels[group]}: ${Math.round((counts[group] / total) * 100)}% (${counts[group]} registered)`;
      tooltip.hidden = false;
      const padding = 12;
      tooltip.style.left = `${Math.min(x + padding, window.innerWidth - tooltip.offsetWidth - padding)}px`;
      tooltip.style.top = `${Math.min(y + padding, window.innerHeight - tooltip.offsetHeight - padding)}px`;
    };
    chartHolder.querySelectorAll('.pie-chart-slices path').forEach(slice => {
      slice.setAttribute('tabindex', '0');
      slice.setAttribute('aria-label', `${labels[slice.dataset.role]}: ${Math.round((counts[slice.dataset.role] / total) * 100)}%, ${counts[slice.dataset.role]} registered`);
      slice.addEventListener('mouseenter', event => showTooltip(slice, event.clientX, event.clientY));
      slice.addEventListener('mousemove', event => showTooltip(slice, event.clientX, event.clientY));
      slice.addEventListener('mouseleave', () => { tooltip.hidden = true; });
      slice.addEventListener('focus', () => {
        const rect = slice.getBoundingClientRect();
        showTooltip(slice, rect.left + rect.width / 2, rect.top + rect.height / 2);
      });
      slice.addEventListener('blur', () => { tooltip.hidden = true; });
    });

    chartLegend.querySelectorAll('.role-filter-button').forEach(button => {
      button.addEventListener('click', () => {
        selectedGroup = selectedGroup === button.dataset.role ? null : button.dataset.role;
        chartHolder.querySelectorAll('[data-role]').forEach(slice => {
          if (slice.dataset.role === selectedGroup) {
            slice.setAttribute('transform', `translate(${slice.dataset.offsetX} ${slice.dataset.offsetY})`);
          } else {
            slice.removeAttribute('transform');
          }
        });
        chartLegend.querySelectorAll('.role-filter-button').forEach(roleButton => {
          roleButton.setAttribute('aria-pressed', String(roleButton.dataset.role === selectedGroup));
        });
      });
    });
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '-';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function daysBetween(startDate, endDate) {
    const start = Date.parse(`${String(startDate).slice(0, 10)}T00:00:00Z`);
    const end = Date.parse(`${String(endDate).slice(0, 10)}T00:00:00Z`);
    if (Number.isNaN(start) || Number.isNaN(end)) return 0;
    return Math.max(0, Math.floor((end - start) / 86400000));
  }

  function getLocalDate() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function formatBorrowingPeriod(borrowedAt, returnedAt) {
    if (!borrowedAt) return '-';
    const days = daysBetween(borrowedAt, returnedAt || getLocalDate());
    return `${days} day${days === 1 ? '' : 's'}${returnedAt ? '' : ' (ongoing)'}`;
  }

  const reportDateFrom = document.getElementById('reportDateFrom');
  const reportDateTo = document.getElementById('reportDateTo');
  const reportFilters = document.getElementById('reportFilters');
  const filterError = document.getElementById('filterError');
  const reportPeriod = document.getElementById('reportPeriod');

  function filterByDateRange(items, dateField, fromDate, toDate) {
    if (!fromDate && !toDate) return items;
    return items.filter(item => {
      const date = String(item[dateField] || '').slice(0, 10);
      return (!fromDate || date >= fromDate) && (!toDate || date <= toDate);
    });
  }

  function formatSelectedDate(date) {
    const [year, month, day] = date.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  }

  function renderReport(data, fromDate, toDate) {
    const booksInRange = filterByDateRange(data.books, 'created_at', fromDate, toDate);
    const usersInRange = filterByDateRange(data.users, 'created_at', fromDate, toDate);
    const requestsInRange = filterByDateRange(data.requests, 'requested_at', fromDate, toDate);
    const borrowingsInRange = filterByDateRange(data.borrowings, 'borrowed_at', fromDate, toDate);
    const announcementsInRange = filterByDateRange(data.announcements, 'created_at', fromDate, toDate);

    document.getElementById('statBooks').textContent = booksInRange.length.toLocaleString();
    document.getElementById('statPatrons').textContent = usersInRange.length.toLocaleString();
    document.getElementById('statTransactions').textContent = requestsInRange.length.toLocaleString();
    document.getElementById('statAnnouncements').textContent = announcementsInRange.length.toLocaleString();

    renderTopBooks(borrowingsInRange);
    renderBorrowings(borrowingsInRange);
    renderOverdueBooks(borrowingsInRange);
    renderUsers(usersInRange);
    renderInventory(data.books);
    renderActivitySummary(requestsInRange);
    renderChart(usersInRange);

    if (fromDate && toDate) {
      reportPeriod.textContent = `Showing ${formatSelectedDate(fromDate)} to ${formatSelectedDate(toDate)}`;
    } else if (fromDate) {
      reportPeriod.textContent = `Showing from ${formatSelectedDate(fromDate)}`;
    } else if (toDate) {
      reportPeriod.textContent = `Showing through ${formatSelectedDate(toDate)}`;
    } else {
      reportPeriod.textContent = 'Showing all dates';
    }
  }

  function applyDateFilter(data) {
    const fromDate = reportDateFrom.value;
    const toDate = reportDateTo.value;
    if (fromDate && toDate && fromDate > toDate) {
      filterError.textContent = 'The start date must be on or before the end date.';
      filterError.hidden = false;
      return;
    }

    filterError.hidden = true;
    renderReport(data, fromDate, toDate);
  }

  Promise.all([
    fetchJson('backend/books-data.php').then(data => data.books || []),
    fetchJson('backend/all-users-data.php').then(data => data.users || []),
    fetchJson('backend/borrow-requests-data.php').then(data => data.requests || []),
    fetchJson('backend/borrowing-report-data.php').then(data => data.borrowings || []),
    fetchJson('backend/announcements-data.php').then(data => data.announcements || []),
  ])
    .then(([books, users, requests, borrowings, announcements]) => {
      const reportData = { books, users, requests, borrowings, announcements };
      document.getElementById('applyFilterBtn').addEventListener('click', () => applyDateFilter(reportData));
      applyDateFilter(reportData);
      document.getElementById('loadingMsg').style.display = 'none';
      reportFilters.style.display = 'flex';
      reportContent.style.display = 'block';
    })
    .catch(err => {
      if (err.message === 'not_logged_in') {
        handleNotLoggedIn();
        return;
      }
      loadingMsg.textContent = 'Something went wrong loading the report. Make sure you\'re logged in as Admin.';
    });
