
  // Auto-generate Student ID on page load
  window.addEventListener('DOMContentLoaded', () => {
    const studentIdField = document.getElementById('student_id');
    if (studentIdField.value === '') {
      // Generate: STU + 6-digit random number
      const randomNum = String(Math.floor(Math.random() * 999999)).padStart(6, '0');
      const generatedId = 'STU' + randomNum;
      studentIdField.value = generatedId;
    }
  });

  // Show any error passed back from backend/register-process.php via ?error=
  const params = new URLSearchParams(window.location.search);
  const errorText = params.get('error') || '';
  if (errorText) document.getElementById('errorMsg').textContent = errorText;

  // If already logged in, there's no need to register again.
  fetch('backend/session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => { if (data.loggedIn) window.location.href = 'index.html'; })
    .catch(() => { /* backend unreachable — stay on the registration page */ });

  document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      // Swap between eye-slash (hidden) and plain eye (visible) icons.
      btn.querySelector('svg').innerHTML = isHidden
        ? '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/>';
    });
  });

  document.getElementById('studentRegisterForm').addEventListener('submit', (e) => {
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    if (password !== confirm) {
      e.preventDefault();
      alert('Passwords do not match.');
    }
  });
