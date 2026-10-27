
  const params = new URLSearchParams(window.location.search);
  const allowedRoles = ['student', 'teacher', 'staff', 'employee', 'visitor'];
  let role = (params.get('role') || '').toLowerCase().replace(/[^a-z]/g, '');
  if (!allowedRoles.includes(role)) role = '';

  function loginUrl() {
    return 'login.html' + (role ? `?role=${role}` : '');
  }
  document.getElementById('backToLogin').addEventListener('click', e => { e.preventDefault(); window.location.href = loginUrl(); });
  document.getElementById('backToLogin2').addEventListener('click', e => { e.preventDefault(); window.location.href = loginUrl(); });

  function togglePasswordField(toggleId, inputId, eyeId) {
    const toggleBtn = document.getElementById(toggleId);
    const input = document.getElementById(inputId);
    const eyeIcon = document.getElementById(eyeId);
    toggleBtn.addEventListener('click', () => {
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
      eyeIcon.innerHTML = isHidden
        ? '<path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/>'
        : '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
    });
  }
  togglePasswordField('toggleNewPassword', 'newPassword', 'eyeIcon1');
  togglePasswordField('toggleConfirmPassword', 'confirmPassword', 'eyeIcon2');

  const requestForm  = document.getElementById('requestForm');
  const resetForm    = document.getElementById('resetForm');
  const requestMsg   = document.getElementById('requestMsg');
  const resetMsg     = document.getElementById('resetMsg');
  const requestBtn   = document.getElementById('requestBtn');
  const resetBtn     = document.getElementById('resetBtn');

  let lastPhone = '';

  document.getElementById('phone').addEventListener('input', event => {
    const input = event.target;
    let digits = input.value.replace(/\D/g, '');

    if (digits.startsWith('63')) digits = digits.slice(2);
    if (digits === '0') {
      input.value = digits;
      return;
    }
    if (digits.startsWith('0')) digits = digits.slice(1);
    digits = digits.slice(0, 10);

    const firstPart = digits.slice(0, 3);
    const secondPart = digits.slice(3, 6);
    const thirdPart = digits.slice(6, 10);
    input.value = digits
      ? `+63 ${firstPart}${secondPart ? ` ${secondPart}` : ''}${thirdPart ? ` ${thirdPart}` : ''}`
      : '';
  });

  async function parseJsonResponse(res) {
    const text = await res.text();
    let data = {};

    if (text) {
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('The server returned an invalid response. Please try again.');
      }
    }

    if (!res.ok) {
      throw new Error(data.error || 'Something went wrong. Please try again.');
    }

    return data;
  }

  function sendResetCode() {
    requestMsg.className = 'msg';
    requestMsg.textContent = '';
    requestBtn.disabled = true;
    requestBtn.textContent = 'Sending…';

    const phone = document.getElementById('phone').value.trim();
    lastPhone = phone;

    fetch('backend/forgot-password-request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role, phone })
    })
      .then(parseJsonResponse)
      .then(data => {
        requestMsg.className = 'msg success';
        requestMsg.textContent = data.message;
        requestForm.style.display = 'none';
        resetForm.style.display = 'block';
      })
      .catch(err => {
        requestMsg.className = 'msg error';
        requestMsg.textContent = err.message;
      })
      .finally(() => {
        requestBtn.disabled = false;
        requestBtn.textContent = 'Send Reset Code';
      });
  }

  requestForm.addEventListener('submit', e => { e.preventDefault(); sendResetCode(); });

  document.getElementById('resendBtn').addEventListener('click', () => {
    document.getElementById('phone').value = lastPhone;
    sendResetCode();
  });

  resetForm.addEventListener('submit', e => {
    e.preventDefault();
    resetMsg.className = 'msg';
    resetMsg.textContent = '';

    const code = document.getElementById('code').value.trim();
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (newPassword !== confirmPassword) {
      resetMsg.className = 'msg error';
      resetMsg.textContent = 'Those passwords don\'t match.';
      return;
    }

    resetBtn.disabled = true;
    resetBtn.textContent = 'Resetting…';

    fetch('backend/reset-password-process.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ role, phone: lastPhone, code, password: newPassword })
    })
      .then(parseJsonResponse)
      .then(() => {
        resetMsg.className = 'msg success';
        resetMsg.textContent = 'Password reset! Redirecting you to log in…';
        setTimeout(() => { window.location.href = loginUrl(); }, 1600);
      })
      .catch(err => {
        resetMsg.className = 'msg error';
        resetMsg.textContent = err.message;
        resetBtn.disabled = false;
        resetBtn.textContent = 'Reset Password';
      });
  });
