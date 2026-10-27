(() => {
  const today = new Date();
  const localDate = [
    today.getFullYear(),
    String(today.getMonth() + 1).padStart(2, '0'),
    String(today.getDate()).padStart(2, '0')
  ].join('-');

  const studentName = document.getElementById('studentName');
  const yearLevel = document.getElementById('yearLevel');
  const studentSection = document.getElementById('studentSection');
  const dateInput = document.getElementById('attendanceDate');
  const form = document.getElementById('attendanceForm');
  const formMessage = document.getElementById('formMessage');
  const recordButton = document.getElementById('recordButton');
  const body = document.getElementById('attendanceBody');
  const recordCount = document.getElementById('recordCount');

  dateInput.value = localDate;

  function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value == null ? '' : String(value);
    return element.innerHTML;
  }

  function formatTime(mysqlDatetime) {
    const date = new Date(mysqlDatetime.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return mysqlDatetime;
    return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' });
  }

  async function loadAttendance() {
    body.innerHTML = '<tr><td colspan="4" class="empty-msg">Loading records…</td></tr>';
    try {
      const response = await fetch(`backend/attendance-data.php?date=${encodeURIComponent(dateInput.value)}`, {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not load attendance.');

      recordCount.textContent = `${data.records.length} time-in${data.records.length === 1 ? '' : 's'} on ${dateInput.value}`;
      if (data.records.length === 0) {
        body.innerHTML = '<tr><td colspan="4" class="empty-msg">No time-ins recorded for this date.</td></tr>';
        return;
      }

      body.innerHTML = data.records.map(record => `
        <tr>
          <td>${escapeHtml(record.student_name)}</td>
          <td>${escapeHtml(record.year_level)}</td>
          <td>${escapeHtml(record.section)}</td>
          <td>${escapeHtml(formatTime(record.time_in))}</td>
        </tr>
      `).join('');
    } catch (error) {
      recordCount.textContent = 'Attendance records could not be loaded.';
      body.innerHTML = '<tr><td colspan="4" class="empty-msg">Could not load attendance. Please try again.</td></tr>';
      console.error(error);
    }
  }

  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(response => response.json())
    .then(data => {
      if (!data.loggedIn) {
        window.location.href = 'admin-login.html';
        return;
      }
      const name = data.username || 'Admin';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
      document.querySelector('.account-role').textContent =
        data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
    })
    .catch(error => console.error('Could not verify the admin session.', error));

  dateInput.addEventListener('change', loadAttendance);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!studentName.value.trim() || !yearLevel.value.trim() || !studentSection.value.trim()) return;

    formMessage.textContent = '';
    formMessage.classList.remove('error');
    recordButton.disabled = true;
    try {
      const response = await fetch('backend/attendance-add.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          student_name: studentName.value.trim(),
          year_level: yearLevel.value.trim(),
          section: studentSection.value.trim()
        })
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not record time-in.');

      formMessage.textContent = `Time-in recorded for ${data.student_name}.`;
      studentName.value = '';
      yearLevel.value = '';
      studentSection.value = '';
      if (dateInput.value === localDate) await loadAttendance();
    } catch (error) {
      formMessage.textContent = error.message || 'Could not reach the server.';
      formMessage.classList.add('error');
    } finally {
      recordButton.disabled = false;
    }
  });

  loadAttendance();
})();
