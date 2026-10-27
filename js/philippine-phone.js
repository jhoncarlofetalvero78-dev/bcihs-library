(() => {
  const invalidMessage = 'Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.';

  function normalize(value) {
    const compact = value.replace(/[\s().-]/g, '');
    if (/^09\d{9}$/.test(compact)) return compact;
    if (/^\+639\d{9}$/.test(compact)) return `0${compact.slice(3)}`;
    return null;
  }

  function format(value) {
    let digits = value.replace(/\D/g, '');

    if (digits.startsWith('63')) digits = digits.slice(2);
    if (digits === '0') return digits;
    if (digits.startsWith('0')) digits = digits.slice(1);

    const mobile = digits.slice(0, 10);
    const firstPart = mobile.slice(0, 3);
    const secondPart = mobile.slice(3, 6);
    const thirdPart = mobile.slice(6, 10);
    return mobile
      ? `+63 ${firstPart}${secondPart ? ` ${secondPart}` : ''}${thirdPart ? ` ${thirdPart}` : ''}`
      : '';
  }

  window.philippineMobilePhone = { normalize, format };

  document.querySelectorAll('[data-ph-mobile]').forEach((input) => {
    const form = input.form;
    const error = form.querySelector('.error-msg');

    function validate(showError) {
      const valid = normalize(input.value) !== null;
      input.setCustomValidity(valid ? '' : invalidMessage);
      input.setAttribute('aria-invalid', String(!valid));
      if (showError && !valid) {
        error.textContent = invalidMessage;
      } else if (error.textContent === invalidMessage) {
        error.textContent = '';
      }
      return valid;
    }

    input.addEventListener('input', () => {
      const start = input.selectionStart;
      const previousLength = input.value.length;
      input.value = format(input.value);
      input.setCustomValidity('');
      input.removeAttribute('aria-invalid');
      if (error.textContent === invalidMessage) error.textContent = '';
      if (start !== null && document.activeElement === input) {
        const newPosition = Math.max(0, start + input.value.length - previousLength);
        input.setSelectionRange(newPosition, newPosition);
      }
    });

    input.addEventListener('blur', () => {
      if (input.value.trim() !== '') validate(true);
    });

    form.addEventListener('submit', (event) => {
      if (!validate(true)) {
        event.preventDefault();
        input.reportValidity();
        input.focus();
      } else {
        input.value = normalize(input.value);
      }
    });
  });
})();
