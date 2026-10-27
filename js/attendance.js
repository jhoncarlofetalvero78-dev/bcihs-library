(() => {
  const el = id => document.getElementById(id);
  let csrfToken = '', formToken = '', serverToday = '', records = [], loadVersion = 0;
  let ready = false, departureId = null;
  function escapeHtml(value) {
    const span = document.createElement('span');
    span.textContent = value == null ? '' : String(value);
    return span.innerHTML;
  }
  function message(id, text, error = false) {
    el(id).textContent = text;
    el(id).classList.toggle('error', error);
  }
  function status(record) {
    return Number(record.tracks_departure) ? (record.time_out ? 'Completed' : 'Inside') : 'Time In only';
  }
  function duration(record) {
    if (record.duration_seconds == null) return 'Not tracked';
    const minutes = Math.floor(Number(record.duration_seconds) / 60);
    return `${Math.floor(minutes / 60)}h ${minutes % 60}m${record.time_out ? '' : ' so far'}`;
  }
  async function request(endpoint, data) {
    const options = { credentials: 'same-origin', cache: 'no-store' };
    if (data !== undefined) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken };
      options.body = JSON.stringify(data);
    }
    const [path, query] = endpoint.split('?');
    const response = await fetch(`backend/${path}.php${query ? '?' + query : ''}`, options);
    const result = await response.json();
    if (!response.ok) {
      const error = new Error(result.error || 'Could not complete the request.');
      error.code = result.code;
      throw error;
    }
    return result;
  }
  function resetRepeat() {
    el('repeatLabel').hidden = true;
    el('confirmRepeat').checked = false;
  }
  function setMode() {
    const manual = el('visitorType').value === 'manual';
    el('manualFields').hidden = !manual;
    el('registeredFields').hidden = manual;
    el('studentLookup').hidden = manual;
    el('studentAccount').required = !manual;
    ['studentName', 'yearLevel', 'studentSection'].forEach(id => { el(id).required = manual; });
    message('lookupMessage', '');
    resetRepeat();
  }
  function limitText(count, limit) {
    return limit == null ? `${count} · unlimited` : `${count} / ${limit} · ${Math.max(0, Number(limit) - count)} remaining`;
  }
  async function loadAttendance(updateSettings = false) {
    const version = ++loadVersion;
    departureId = null; el('departureConfirmation').hidden = true;
    el('exportAttendance').disabled = true;
    el('attendanceBody').innerHTML = '<tr><td colspan="6" class="empty-msg">Loading records…</td></tr>';
    const params = new URLSearchParams({ view: el('attendanceView').value, search: el('historySearch').value.trim() });
    if (el('attendanceDate').value) params.set('date', el('attendanceDate').value);
    try {
      const data = await request(`attendance-data?${params}`);
      if (version !== loadVersion) return;
      csrfToken = data.csrfToken; formToken = data.formToken; serverToday = data.serverToday;
      ready = true;
      el('recordButton').disabled = false;
      el('saveSettings').disabled = false;
      el('attendanceDate').value = data.date;
      records = data.records;
      el('visitTotal').textContent = data.summary.visits;
      el('studentTotal').textContent = data.summary.registered_students;
      el('walkInTotal').textContent = data.summary.walk_in_visits;
      const tracking = Number(data.settings.track_departures) === 1;
      el('insideTotal').textContent = tracking ? data.inside : 'Not tracked';
      el('capacitySummary').textContent = `Today (${serverToday}, PHT): visits ${limitText(data.today.visits, data.settings.daily_visit_limit)}. ` +
        (tracking ? `Occupancy: ${limitText(data.inside, data.settings.occupancy_limit)}. Apply filters to update live counts.` : 'Departure tracking is off. Daily visits are not current occupancy.');
      if (updateSettings) {
        el('dailyLimit').value = data.settings.daily_visit_limit ?? '';
        el('occupancyLimit').value = data.settings.occupancy_limit ?? '';
        el('trackDepartures').checked = tracking;
      }
      const allDates = el('attendanceView').value === 'inside';
      el('recordCount').textContent = `${records.length} matching visit(s) · ${allDates ? 'Currently inside across all dates' : data.date + ' (PHT)'}. Date summaries include all visits on the selected date.`;
      message('historyMessage', '');
      el('attendanceBody').innerHTML = records.length ? records.map(record => `
        <tr><td><strong>${escapeHtml(record.student_name)}</strong><small>#${Number(record.id)} · ${record.user_id ? 'Registered · ' + escapeHtml(record.student_identifier || 'Account #' + record.user_id) : 'Manual walk-in'}</small></td>
        <td>${escapeHtml(record.year_section_snapshot || [record.year_level, record.section].filter(Boolean).join(' / ') || 'Not provided')}</td>
        <td>${escapeHtml(record.time_in)}</td><td>${escapeHtml(record.time_out || '—')}</td>
        <td><span class="visit-status">${status(record)}</span><small>${duration(record)}</small></td>
        <td>${Number(record.tracks_departure) && !record.time_out ? `<button class="primary-btn" type="button" data-time-out="${Number(record.id)}">Confirm Time Out</button>` : '—'}</td></tr>`).join('') : '<tr><td colspan="6" class="empty-msg">No visits match these filters.</td></tr>';
      el('exportAttendance').disabled = records.length === 0;
    } catch (error) {
      if (version !== loadVersion) return;
      records = [];
      el('recordCount').textContent = 'Attendance could not be loaded.';
      el('attendanceBody').innerHTML = '<tr><td colspan="6" class="empty-msg">Could not load attendance. Apply filters to retry.</td></tr>';
      ['visitTotal','studentTotal','walkInTotal','insideTotal'].forEach(id => { el(id).textContent = '—'; });
      el('capacitySummary').textContent = 'Current counts are unavailable. Refresh before relying on capacity figures.';
      message('historyMessage', error.message, true);
    }
  }
  el('visitorType').addEventListener('change', setMode);
  el('studentAccount').addEventListener('change', () => {
    resetRepeat();
    const select = el('studentAccount');
    el('selectedStudent').textContent = select.value ? select.options[select.selectedIndex].textContent : '';
  });
  el('studentLookup').addEventListener('submit', async event => {
    event.preventDefault();
    el('searchButton').disabled = true;
    el('studentAccount').innerHTML = '<option value="">Choose a student</option>';
    el('selectedStudent').textContent = '';
    resetRepeat();
    message('lookupMessage', 'Searching…');
    try {
      const data = await request(`attendance-students?search=${encodeURIComponent(el('studentSearch').value.trim())}`);
      data.students.forEach(student => {
        const option = document.createElement('option');
        option.value = student.id;
        option.textContent = `${student.full_name} · ${student.student_id || 'Account #' + student.id} · ${student.year_section || 'Year/section not provided'}`;
        el('studentAccount').appendChild(option);
      });
      message('lookupMessage', data.students.length ? `${data.students.length} account(s) found (up to 20). Select the correct ID before confirming.` : 'No accepted student accounts found. Try a different name or student ID.');
    } catch (error) { message('lookupMessage', error.message, true); }
    finally { el('searchButton').disabled = false; }
  });
  el('attendanceForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || el('recordButton').disabled) return;
    el('recordButton').disabled = true;
    const data = { mode: el('visitorType').value, submission_key: formToken, confirm_repeat: el('confirmRepeat').checked };
    if (data.mode === 'registered') data.user_id = el('studentAccount').value;
    else Object.assign(data, { student_name: el('studentName').value.trim(), year_level: el('yearLevel').value.trim(), section: el('studentSection').value.trim() });
    try {
      const result = await request('attendance-add', data);
      formToken = result.formToken;
      message('formMessage', `${result.replayed ? 'Already saved' : 'Time In recorded'} for ${result.student_name} at ${result.time_in} PHT (visit #${result.id}).`);
      el('attendanceForm').reset(); resetRepeat();
      el('selectedStudent').textContent = '';
      el('attendanceDate').value = serverToday;
      el('attendanceView').value = 'all'; el('historySearch').value = '';
      await loadAttendance();
    } catch (error) {
      if (error.code === 'repeat_visit_confirmation_required') el('repeatLabel').hidden = false;
      message('formMessage', error.message, true);
    } finally { el('recordButton').disabled = false; }
  });
  el('attendanceBody').addEventListener('click', event => {
    const button = event.target.closest('[data-time-out]');
    if (!button || button.disabled) return;
    const record = records.find(row => Number(row.id) === Number(button.dataset.timeOut));
    if (!record) return;
    departureId = record.id;
    el('departurePrompt').textContent = `Has ${record.student_name} left the library (visit #${record.id})? Confirm only after departure.`;
    el('departureConfirmation').hidden = false;
    el('confirmDeparture').focus();
  });
  el('cancelDeparture').addEventListener('click', () => {
    departureId = null; el('departureConfirmation').hidden = true;
  });
  el('confirmDeparture').addEventListener('click', async () => {
    if (!departureId || el('confirmDeparture').disabled) return;
    const id = departureId;
    el('confirmDeparture').disabled = true;
    el('cancelDeparture').disabled = true;
    try {
      await request('attendance-time-out', { id, confirm_departure: true });
      await loadAttendance();
      message('historyMessage', `Time Out recorded for visit #${id}.`);
    } catch (error) { message('historyMessage', error.message, true); }
    finally { el('confirmDeparture').disabled = false; el('cancelDeparture').disabled = false; }
  });
  el('historyFilters').addEventListener('submit', event => { event.preventDefault(); loadAttendance(); });
  el('settingsForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || el('saveSettings').disabled) return;
    el('saveSettings').disabled = true;
    try {
      await request('attendance-settings', { daily_visit_limit: el('dailyLimit').value, occupancy_limit: el('occupancyLimit').value, track_departures: el('trackDepartures').checked });
      await loadAttendance(true);
      message('settingsMessage', 'Attendance settings saved. New visits use this policy.');
    } catch (error) { message('settingsMessage', error.message, true); }
    finally { el('saveSettings').disabled = false; }
  });
  function csvCell(value) {
    let text = String(value ?? '');
    if (/^[\s]*[=+@-]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  }
  el('exportAttendance').addEventListener('click', () => {
    const rows = [['Visit','Visitor type','Name','Student ID','Year / section','Time In (PHT)','Time Out (PHT)','Status','Duration seconds'],
      ...records.map(record => [record.id, record.user_id ? 'Registered' : 'Manual walk-in', record.student_name, record.student_identifier,
        record.year_section_snapshot || [record.year_level, record.section].filter(Boolean).join(' / '), record.time_in, record.time_out, status(record), record.duration_seconds])];
    const url = URL.createObjectURL(new Blob(['\uFEFF' + rows.map(row => row.map(csvCell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a'); link.href = url;
    link.download = `attendance-${el('attendanceView').value === 'inside' ? 'currently-inside' : el('attendanceDate').value}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });
  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => response.json()).then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      el('adminName').textContent = name; el('adminInitial').textContent = name.charAt(0).toUpperCase();
      document.querySelector('.account-role').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
    }).catch(() => message('historyMessage', 'Could not verify the staff session. Reload to retry.', true));
  setMode();
  loadAttendance(true);
})();
