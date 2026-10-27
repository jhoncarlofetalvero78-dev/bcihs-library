
  let allLogs = [];

  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => response.json())
    .then(data => {
      if (!data.loggedIn) return;
      const name = data.username || 'Library Staff';
      document.getElementById('staffName').textContent = name;
      document.getElementById('staffRole').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
      document.getElementById('staffInitial').textContent = name.charAt(0).toUpperCase();
    });

  document.querySelectorAll('[data-soon]').forEach(button => {
    button.addEventListener('click', () => {
      window.alert(`${button.dataset.soon} isn't wired up yet — coming soon.`);
    });
  });

  function escapeHtml(value) {
    const element = document.createElement('span');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime())
      ? String(value)
      : date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function renderLogs() {
    const query = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const filtered = allLogs.filter(log => {
      if (status && log.status !== status) return false;
      return !query || [log.full_name, log.phone, log.message, log.response]
        .filter(Boolean).join(' ').toLowerCase().includes(query);
    });
    const body = document.getElementById('logsBody');
    if (filtered.length === 0) {
      body.innerHTML = '<tr><td colspan="6" class="empty-msg">No SMS log entries match.</td></tr>';
    } else {
      body.innerHTML = filtered.map(log => {
        const state = ['sent', 'failed', 'skipped'].includes(log.status) ? log.status : 'unknown';
        return `<tr>
          <td>${escapeHtml(formatDateTime(log.created_at))}</td>
          <td>${escapeHtml(log.full_name || '—')}</td>
          <td>${escapeHtml(log.phone)}</td>
          <td class="message-cell">${escapeHtml(log.message)}</td>
          <td><span class="status-pill ${state}">${escapeHtml(state.toUpperCase())}</span></td>
          <td class="response-cell">${escapeHtml(log.response || '—')}</td>
        </tr>`;
      }).join('');
    }
    const countBadge = document.getElementById('countBadge');
    countBadge.textContent = `Showing ${filtered.length} of ${allLogs.length} recent log entr${allLogs.length === 1 ? 'y' : 'ies'} (up to 300).`;
    countBadge.hidden = false;
  }

  async function loadLogs() {
    const loading = document.getElementById('loadingMsg');
    const table = document.getElementById('logsTable');
    const refresh = document.getElementById('refreshBtn');
    document.getElementById('countBadge').hidden = true;
    loading.className = 'loading-msg';
    loading.textContent = 'Loading SMS logs…';
    loading.hidden = false;
    table.hidden = true;
    refresh.disabled = true;
    try {
      const response = await fetch('backend/sms-logs-data.php', { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok) {
        if (response.status === 401 || response.status === 403) {
          throw new Error('Sign in with an administrator or librarian account to view SMS logs.');
        }
        throw new Error(data.error || 'Could not load SMS logs.');
      }
      if (!Array.isArray(data.logs)) {
        throw new Error('The server returned an invalid SMS logs response.');
      }
      allLogs = data.logs;
      loading.hidden = true;
      table.hidden = false;
      renderLogs();
    } catch (error) {
      loading.className = 'error-msg';
      loading.textContent = error.message || 'Could not reach the server. Please try again.';
    } finally {
      refresh.disabled = false;
    }
  }

  document.getElementById('searchInput').addEventListener('input', renderLogs);
  document.getElementById('statusFilter').addEventListener('change', renderLogs);
  document.getElementById('refreshBtn').addEventListener('click', loadLogs);
  loadLogs();
