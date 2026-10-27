
  const params = new URLSearchParams(window.location.search);
  const otp = params.get('otp');
  const phone = params.get('phone');
  const message = params.get('message');
  const phoneInput = document.getElementById('phoneNumber');
  const otpInput = document.getElementById('otpCode');
  const messageBox = document.getElementById('messageBox');

  if (phone) {
    phoneInput.value = decodeURIComponent(phone);
  }

  if (otp) {
    otpInput.value = otp;
    messageBox.style.display = 'block';
    messageBox.classList.add('success');
    messageBox.textContent = 'OTP code generated successfully. Use the code below and complete your new password.';
  }

  if (message) {
    messageBox.style.display = 'block';
    messageBox.textContent = decodeURIComponent(message);
  }

  const togglePassword = (inputId, btnId) => {
    const input = document.getElementById(inputId);
    const button = document.getElementById(btnId);
    button.addEventListener('click', () => {
      const hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
      button.innerHTML = hidden
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 10.6a3 3 0 0 0 4.2 4.2M9.9 4.2A11 11 0 0 1 12 4c7 0 11 7 11 7a17.7 17.7 0 0 1-3.2 4.2M6.5 6.7A17.6 17.6 0 0 0 1 11s4 7 11 7a10.8 10.8 0 0 0 4.2-.85"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    });
  };

  togglePassword('newPassword', 'togglePasswordOne');
  togglePassword('confirmPassword', 'togglePasswordTwo');

  document.getElementById('resetForm').addEventListener('submit', (event) => {
    const otpValue = document.getElementById('otpCode').value.trim();
    const newPassword = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;

    if (!otpValue) {
      event.preventDefault();
      messageBox.style.display = 'block';
      messageBox.textContent = 'Please enter the OTP code.';
      return;
    }

    if (!newPassword || !confirmPassword) {
      event.preventDefault();
      messageBox.style.display = 'block';
      messageBox.textContent = 'Please complete both password fields.';
      return;
    }
    if (newPassword.length < 8) {
      event.preventDefault();
      messageBox.style.display = 'block';
      messageBox.textContent = 'Password must be at least 8 characters long.';
      return;
    }
    if (newPassword !== confirmPassword) {
      event.preventDefault();
      messageBox.style.display = 'block';
      messageBox.textContent = 'Passwords do not match.';
    }
  });
