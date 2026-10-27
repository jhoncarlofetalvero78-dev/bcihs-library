
  const roleSelect = document.getElementById('registrationRole');
  const registrationForms = document.querySelectorAll('[data-registration-form]');

  function selectRole(role) {
    roleSelect.value = role;
    registrationForms.forEach((form) => {
      form.hidden = form.dataset.registrationForm !== role;
    });
  }

  roleSelect.addEventListener('change', () => selectRole(roleSelect.value));

  function generateId(prefix) {
    return prefix + String(Math.floor(Math.random() * 999999)).padStart(6, '0');
  }
  document.getElementById('student_id').value = generateId('STU');
  document.getElementById('teacher_id').value = generateId('TCH');
  document.getElementById('librarian_id').value = generateId('LIB');

  document.querySelectorAll('.toggle-password').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.target);
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      button.querySelector('svg').innerHTML = isHidden
        ? '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/>';
    });
  });

  registrationForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      const password = form.querySelector('[name="password"]').value;
      const confirmPassword = form.querySelector('[name="confirm_password"]').value;
      if (password !== confirmPassword) {
        event.preventDefault();
        form.querySelector('.error-msg').textContent = 'Passwords do not match.';
      }
    });
  });

  const params = new URLSearchParams(window.location.search);
  const requestedRole = params.get('role');
  if (['student', 'teacher', 'librarian', 'visitor'].includes(requestedRole)) {
    selectRole(requestedRole);
    document.querySelector(`[data-registration-form="${requestedRole}"] .error-msg`).textContent = params.get('error') || '';
  }

  fetch('backend/session-status.php', { credentials: 'same-origin' })
    .then((response) => response.json())
    .then((data) => { if (data.loggedIn) window.location.href = 'index.html'; })
    .catch(() => { /* Keep registration available if the session check fails. */ });
