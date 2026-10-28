
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


  let allTx = [];

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = borrowing.date(mysqlDatetime);
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function durationFor(tx) {
    if (tx.status === 'returned' && borrowing.loanStart(tx) && tx.returned_at) {
      const days = Math.max(0, Math.round((borrowing.date(tx.returned_at) - borrowing.date(borrowing.loanStart(tx))) / 86400000));
      return `<span class="duration-tag">${days} day${days === 1 ? '' : 's'}</span>`;
    }
    if (borrowing.isCheckedOut(tx.status) && borrowing.loanStart(tx)) {
      const days = Math.max(0, Math.round((Date.now() - borrowing.date(borrowing.loanStart(tx))) / 86400000));
      return `<span class="duration-tag ongoing">${days} day${days === 1 ? '' : 's'} (ongoing)</span>`;
    }
    return '<span class="duration-tag">—</span>';
  }

  function renderRows(rows) {
    const body = document.getElementById('txBody');
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="9" class="empty-msg">No transactions match.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(t => `
      <tr>
        <td>${escapeHtml(t.student_name)}</td>
        <td>${escapeHtml(t.book_title)}${t.source === 'reservation' ? '<small class="availability-detail">Reservation</small>' : ''}</td>
        <td>${formatDate(t.requested_at)}</td>
        <td>${formatDate(t.approved_at)}</td>
        <td>${formatDate(borrowing.loanStart(t))}</td>
        <td>${formatDate(t.due_date)}</td>
        <td>${formatDate(t.returned_at)}</td>
        <td><span class="status-pill ${t.status}">${borrowing.label(t.status)}</span></td>
        <td>${durationFor(t)}</td>
      </tr>
    `).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const from = document.getElementById('dateFrom').value;
    const to = document.getElementById('dateTo').value;

    const filtered = allTx.filter(t => {
      if (status === 'checked_out' && !borrowing.isCheckedOut(t.status)) return false;
      if (status && status !== 'checked_out' && t.status !== status) return false;
      if (q && !([t.student_name, t.book_title].join(' ').toLowerCase().includes(q))) return false;
      const reqDate = (t.requested_at || '').slice(0, 10);
      if (from && reqDate < from) return false;
      if (to && reqDate > to) return false;
      return true;
    });

    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allTx.length} transaction${allTx.length === 1 ? '' : 's'}`;
    renderRows(filtered);
    return filtered;
  }

  function exportCsv() {
    const rows = applyFilters();
    const header = ['Student Name', 'Book Title', 'Requested', 'Approved', 'Checked Out', 'Due Date', 'Returned', 'Status'];
    const lines = [header.join(',')];
    rows.forEach(t => {
      const cells = [t.student_name, t.book_title, t.requested_at || '', t.approved_at || '', borrowing.loanStart(t) || '', t.due_date || '', t.returned_at || '', borrowing.label(t.status)]
        .map(v => {
          let text = String(v);
          if (/^[\s\u0000-\u001f]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = `'${text}`;
          return `"${text.replace(/"/g, '""')}"`;
        });
      lines.push(cells.join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `transactions-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  function loadTransactions() {
    return fetch('backend/transactions-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
        allTx = data.requests || [];

        document.getElementById('countTotal').textContent = allTx.length.toLocaleString();
        document.getElementById('countBorrowed').textContent = allTx.filter(t => borrowing.isCheckedOut(t.status)).length.toLocaleString();
        document.getElementById('countReturned').textContent = allTx.filter(t => t.status === 'returned').length.toLocaleString();
        document.getElementById('countRejected').textContent = allTx.filter(t => t.status === 'rejected').length.toLocaleString();

        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('txTable').style.display = 'table';
        document.getElementById('countBadge').style.display = 'block';
        applyFilters();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') { window.location.href = 'admin-login.html'; return; }
        document.getElementById('loadingMsg').textContent = 'Something went wrong loading transactions.';
      });
  }

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('statusFilter').addEventListener('change', applyFilters);
  document.getElementById('dateFrom').addEventListener('change', applyFilters);
  document.getElementById('dateTo').addEventListener('change', applyFilters);
  document.getElementById('exportBtn').addEventListener('click', exportCsv);

loadTransactions();
