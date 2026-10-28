
  const passwordInput = document.getElementById('password');
  const toggleBtn = document.getElementById('togglePassword');
  const eyeIcon = document.getElementById('eyeIcon');

  toggleBtn.addEventListener('click', () => {
    const isHidden = passwordInput.type === 'password';
    passwordInput.type = isHidden ? 'text' : 'password';
    toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    eyeIcon.innerHTML = isHidden
      ? '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/>'
      : '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
  });

  const form = document.getElementById('adminLoginForm');
  const errorMsg = document.getElementById('errorMsg');
  const submitBtn = document.getElementById('submitBtn');
  const pickup = new URLSearchParams(window.location.search).get('pickup');
  const pickupToken = pickup && pickup.length === 64 && /^[a-f0-9]{64}$/.test(pickup) ? pickup : '';
  if (pickupToken) {
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'pickup'; input.value = pickupToken;
    form.appendChild(input);
  }

  form.addEventListener('submit', (e) => {
    errorMsg.textContent = '';
    const identifier = document.getElementById('identifier').value.trim();
    const password = passwordInput.value;

    if (!identifier || !password) {
      e.preventDefault();
      errorMsg.textContent = 'Please enter your school ID or username and password.';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Logging in...';

    if (document.getElementById('remember').checked) {
      localStorage.setItem('bcihs_admin_remember_username', identifier);
    } else {
      localStorage.removeItem('bcihs_admin_remember_username');
    }
  });

  window.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const errorText = params.get('error');
    if (errorText) document.getElementById('errorMsg').textContent = errorText;

    const savedUsername = localStorage.getItem('bcihs_admin_remember_username');
    if (savedUsername) {
      document.getElementById('identifier').value = savedUsername;
      document.getElementById('remember').checked = true;
    }

    fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(res => res.json())
      .then(data => {
        if (data.loggedIn) {
          window.location.href = pickupToken ? 'pickup-verification.html?token=' + pickupToken : 'admin-dashboard.html';
        }
      })
      .catch(() => { /* backend unreachable — stay on the page */ });
  });

  document.getElementById('forgotLink').addEventListener('click', (e) => {
    e.preventDefault();
    window.location.href = 'forgot-password.html?role=librarian';
  });
