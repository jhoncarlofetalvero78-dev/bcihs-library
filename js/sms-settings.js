(() => {
  const form = document.getElementById('smsPermissionForm');
  const toggle = document.getElementById('allowSmsNotifications');
  const save = document.getElementById('saveSmsPermission');
  const refresh = document.getElementById('refreshSmsSettings');
  const feedback = document.getElementById('smsSettingsFeedback');
  let current = null;
  let busy = false;

  function renderSettings(data) {
    current = data;
    const permission = data.permission;
    toggle.checked = permission.allowed === true;
    toggle.disabled = !data.can_manage || !permission.available;
    save.hidden = !data.can_manage;
    save.disabled = !permission.available;
    document.getElementById('smsPermissionLabel').textContent = permission.available
      ? (permission.allowed ? 'ON' : 'OFF') : 'OFF — setup required';
    document.getElementById('smsSelectedProvider').textContent = ({unisms:'UniSMS',semaphore:'Semaphore',simulation:'Simulation'})[data.provider] || 'Unavailable';
    document.getElementById('smsEffectiveMode').textContent = data.delivery_mode;
    document.getElementById('smsConfigReadiness').textContent = data.configuration_message;
    document.getElementById('smsPermissionRole').textContent = data.can_manage
      ? 'Only administrators can change this permission. Provider keys and live activation stay in private deployment configuration.'
      : 'View only. Ask an authorized administrator to change notification permission.';
    const list = document.getElementById('smsSettingsAudit');
    list.replaceChildren();
    for (const row of data.audit || []) {
      const item = document.createElement('li');
      item.textContent = `${row.changed_at} PHT · Administrator #${row.admin_id} · ${Number(row.previous_allowed) ? 'ON' : 'OFF'} → ${Number(row.new_allowed) ? 'ON' : 'OFF'} · revision ${row.setting_version}`;
      list.appendChild(item);
    }
    document.getElementById('smsAuditDetails').hidden = !data.can_manage;
    document.getElementById('smsAuditEmpty').hidden = (data.audit || []).length > 0;
  }

  function showUnavailable() {
    current = null;
    toggle.checked = false;
    document.getElementById('smsPermissionLabel').textContent = 'Unavailable — refresh required';
    document.getElementById('smsEffectiveMode').textContent = 'Unknown';
    document.getElementById('smsConfigReadiness').textContent = 'Server status could not be verified. Controls are disabled; the last saved server permission may still apply.';
  }

  async function requestSettings(input) {
    const options = {credentials:'same-origin',cache:'no-store'};
    if (input) {
      options.method = 'POST';
      options.headers = {'Content-Type':'application/json','X-CSRF-Token':current.csrfToken};
      options.body = JSON.stringify(input);
    }
    const response = await fetch('backend/sms-settings.php', options);
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'SMS settings are unavailable. Refresh before trying again.');
    return data;
  }

  async function loadSettings() {
    if (busy) return;
    busy = true;
    toggle.disabled = save.disabled = refresh.disabled = true;
    try {
      renderSettings(await requestSettings());
      feedback.textContent = '';
    } catch (error) {
      showUnavailable();
      feedback.textContent = error.message;
    } finally {
      busy = false;
      refresh.disabled = false;
    }
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !current?.can_manage || !current.permission.available) return;
    const allowed = toggle.checked;
    if (allowed === current.permission.allowed) return;
    busy = true;
    toggle.disabled = save.disabled = refresh.disabled = true;
    try {
      if (allowed && !await window.libraryUI.confirm(
        'Allow automatic SMS notifications? Simulation sends no SMS. If live delivery is separately authorized in private configuration, future events may use paid credits. Previously suppressed messages will not be replayed.',
        {title:'Enable SMS notifications?',confirmLabel:'Enable notifications'})) {
        toggle.checked = current.permission.allowed;
        return;
      }
      renderSettings(await requestSettings({allowed,version:current.permission.version,confirm_enable:allowed}));
      feedback.textContent = allowed ? 'Notification permission enabled. Existing private delivery safeguards still apply.' : 'Notification permission disabled. No suppressed messages will be replayed.';
      document.getElementById('refreshBtn').click();
    } catch (error) {
      feedback.textContent = error.message + ' Refresh settings before another change.';
      showUnavailable();
    } finally {
      busy = false;
      toggle.disabled = !current?.can_manage || !current?.permission.available;
      save.disabled = toggle.disabled;
      refresh.disabled = false;
    }
  });
  refresh.addEventListener('click', loadSettings);
  loadSettings();
})();
