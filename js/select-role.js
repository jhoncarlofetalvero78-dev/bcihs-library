const roleSelect = document.getElementById('registrationRole');
const registrationForms = document.querySelectorAll('[data-registration-form]');
let registrationReady = false;

function selectRole(role) {
  roleSelect.value = role;
  registrationForms.forEach(form => { form.hidden = form.dataset.registrationForm !== role; });
}
roleSelect.addEventListener('change', () => selectRole(roleSelect.value));

document.querySelectorAll('.toggle-password').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.target);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    button.querySelector('svg').innerHTML = isHidden
      ? '<path d="M1 12s4-7 11-7 11 7 11 7-11 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>'
      : '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/>';
  });
});

registrationForms.forEach(form => form.addEventListener('submit', event => {
  const button = form.querySelector('button[type="submit"]');
  const message = form.querySelector('.error-msg');
  message.textContent = '';
  if (!registrationReady) {
    event.preventDefault();
    message.textContent = 'The secure form is still loading. Reload if this continues.';
    return;
  }
  if (button.disabled) { event.preventDefault(); return; }
  if (form.querySelector('[name="password"]').value !== form.querySelector('[name="confirm_password"]').value) {
    event.preventDefault(); message.textContent = 'Passwords do not match.'; return;
  }
  button.disabled = true;
  button.textContent = 'Submitting…';
}));

const params = new URLSearchParams(window.location.search);
const requestedRole = params.get('role');
if (['student', 'teacher', 'librarian', 'visitor'].includes(requestedRole)) {
  selectRole(requestedRole);
  document.querySelector(`[data-registration-form="${requestedRole}"] .error-msg`).textContent = params.get('error') || '';
}
fetch('backend/session-status.php', {credentials:'same-origin'})
  .then(response => response.json())
  .then(data => { if (data.loggedIn) window.location.href = 'index.html'; })
  .catch(() => { /* Keep registration available if the session check fails. */ });
fetch('backend/registration-token.php', {credentials:'same-origin',cache:'no-store'})
  .then(response => { if (!response.ok) throw new Error(); return response.json(); })
  .then(data => {
    registrationForms.forEach(form => {
      const token = document.createElement('input'); token.type = 'hidden'; token.name = 'csrf_token'; token.value = data.csrfToken;
      form.appendChild(token);
    });
    registrationReady = true;
  }).catch(() => registrationForms.forEach(form => {
    form.querySelector('.error-msg').textContent = 'Could not initialize the secure form. Reload to retry.';
  }));
