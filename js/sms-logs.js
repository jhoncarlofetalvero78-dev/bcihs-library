
  let allLogs = [];

  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => response.json())
    .then(data => {
      if (!data.loggedIn) return;
      const name = data.username || 'Library Staff';
      document.getElementById('staffName').textContent = name;
      document.getElementById('staffRole').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
      document.getElementById('staffInitial').textContent = name.charAt(0).toUpperCase();
    }).catch(() => {});


  function escapeHtml(value) {
    const element = document.createElement('span');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + '+08:00');
    return Number.isNaN(date.getTime())
      ? String(value)
      : date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone:'Asia/Manila' });
  }

  const statusLabels = {simulated:'Simulated (no credits)',queued:'Provider queued',pending:'Pending (delivery unconfirmed)',
    accepted:'Provider accepted (delivery unconfirmed)',sent:'Provider reports sent (handset unconfirmed)',retrying:'Provider retrying (no app resend)',
    failed:'Failed / rejected',skipped:'Skipped',unknown:'Unknown — review before retry',legacy_sent:'Legacy sent (unverified)'};
  const providerLabels = {simulation:'Simulation',semaphore:'Semaphore',unisms:'UniSMS',legacy:'Historical provider'};
  const eventLabels = {account_approved:'Account approval',borrow_ready:'Borrowing ready for pickup',borrow_rejected:'Borrowing rejection',
    checkout:'Checkout with due date',reservation_ready:'Reservation ready',password_reset:'Password reset',legacy:'Historical / other'};
  const reasonLabels = {
    live_test_wrong_provider:'Live-test authorization cannot be used with another provider.',
    live_test_authorization_invalid:'Live-test authorization is missing, invalid or expired.',
    live_test_event_or_recipient_mismatch:'This notification is outside the authorized test scope.',
    live_test_message_rejected:'Live-test message differs from the approved single-segment text.',
    live_test_storage_unavailable:'Private persistent live-test storage is unavailable; sending blocked.',
    live_test_consumed:'The one live-test attempt is already consumed. No retry is allowed.',
    live_test_restore_failed:'Safe configuration reset failed. No API request was made; the test slot remains consumed.',
    simulation_no_network_no_credits:'Simulation only. No provider was contacted.',missing_or_invalid_phone:'No valid Philippine mobile number.',
    unsupported_provider:'Unknown provider; sending blocked.',unisms_network_not_verified:'UniSMS requires an owner-verified Globe or DITO recipient.',
    unisms_links_not_allowed:'Links are blocked for the UniSMS default sender.',unisms_message_too_long:'UniSMS messages are limited to 160 characters locally.',
    provider_validation_rejected:'Provider rejected recipient, content, sender, or account validation.',provider_credit_rejected:'Provider rejected the credit balance.',
    provider_network_rejected:'Provider rejected the recipient network.',provider_retrying:'Provider reports retrying. The application has not resent.',
    provider_sent_not_handset_delivery:'UniSMS reports sent. Handset receipt is not confirmed.',
    invalid_message:'Missing or oversized message.',live_sending_not_enabled:'Live sending is disabled.',missing_provider_credentials:'Provider credentials are not configured.',
    provider_authentication_rejected:'Provider rejected authentication.',provider_rate_limited:'Provider rate limit reached. No automatic retry.',
    provider_unavailable:'Provider unavailable. No automatic retry.',provider_http_rejected:'Provider rejected this HTTP request.',
    unsupported_provider_response:'Unsupported response; provider outcome unknown.',unsupported_provider_status:'Unsupported provider status; review required.',
    provider_queued:'Provider reports queued.',provider_in_transit:'Provider reports in transit.',provider_network_accepted_not_handset_delivery:'Accepted by the network; handset delivery is not established.',
    provider_message_rejected:'Provider rejected/refunded the message.',transport_interrupted_check_provider_before_retry:'Connection interrupted; acceptance is unknown. Check provider before retry.',
    attempt_reserved_not_delivery:'Attempt reserved. It may have been interrupted; no automatic retry.',historical_unverified_status:'Historical entry. Raw response is withheld; delivery is unverified.'
  };

  function renderLogs() {
    const query = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const filtered = allLogs.filter(log => {
      if (status && log.status !== status) return false;
      return !query || [log.full_name, log.phone, log.message, eventLabels[log.event], reasonLabels[log.reason], providerLabels[log.provider], log.reference]
        .filter(Boolean).join(' ').toLowerCase().includes(query);
    });
    const body = document.getElementById('logsBody');
    if (filtered.length === 0) {
      body.innerHTML = '<tr><td colspan="7" class="empty-msg">No SMS log entries match.</td></tr>';
    } else {
      body.innerHTML = filtered.map(log => {
        const state = Object.hasOwn(statusLabels,log.status) ? log.status : 'unknown';
        return `<tr>
          <td>${escapeHtml(formatDateTime(log.created_at))}</td>
          <td>${escapeHtml(log.full_name || '—')}</td>
          <td>${escapeHtml(log.phone)}</td>
          <td>${escapeHtml(eventLabels[log.event] || 'Historical / other')}</td>
          <td class="message-cell">${escapeHtml(log.message)}</td>
          <td><span class="status-pill ${state}">${escapeHtml(statusLabels[state])}</span></td>
          <td class="response-cell">${escapeHtml(reasonLabels[log.reason] || 'Status requires staff review.')}<br>${escapeHtml(providerLabels[log.provider] || 'Historical provider')}${log.reference ? '<br>' + escapeHtml(log.reference) : ''}<br>${log.request_attempted === true ? 'API request attempted' : log.request_attempted === false ? 'No API request' : 'Request history unverified'}${Number.isInteger(log.http_status) && log.http_status >= 100 && log.http_status <= 599 ? ' (HTTP ' + log.http_status + ')' : ''}</td>
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
    allLogs = []; document.getElementById('logsBody').innerHTML = '';
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
      document.getElementById('smsMode').textContent = data.mode === 'simulate'
        ? 'Simulation mode: no provider requests or SMS credits. Recipient numbers are masked. Selected provider: ' + (providerLabels[data.provider] || 'Semaphore') + '.'
        : 'Live/disabled configuration: review staff configuration. No delivery confirmation is implied.';
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
