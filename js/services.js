
  // Mobile nav toggle
  const hamburger = document.getElementById('hamburger');
  const mainNav = document.getElementById('mainNav');
  hamburger.addEventListener('click', () => mainNav.classList.toggle('open'));

  // On load, ask the PHP backend whether a session is active; if so, switch
  // the button to show the profile pill with user info.
  const loginBtn = document.getElementById('loginBtn');
  const loginMenu = document.getElementById('loginMenu');
  const profilePill = document.getElementById('profilePill');
  const profileName = document.getElementById('profileName');
  const profileRole = document.getElementById('profileRole');
  const profileDropdown = document.getElementById('profileDropdown');
  const logoutBtn = document.getElementById('logoutBtn');

  document.querySelectorAll('.book-list-link').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      const destination = link.href;
      fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(res => res.json())
        .then(data => {
          window.location.href = data.loggedIn ? destination : 'login.html';
        })
        .catch(() => {
          window.location.href = 'login.html';
        });
    });
  });

  fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(res => res.json())
    .then(data => {
      if (data.loggedIn) {
        // Hide the login menu and show the profile pill
        loginMenu.style.display = 'none';
        profilePill.classList.add('visible');
        
        // Set user name and role
        profileName.textContent = data.fullName || 'User';
        profileRole.textContent = data.role.charAt(0).toUpperCase() + data.role.slice(1) || 'Role';
        
        // Profile pill click toggles dropdown
        profilePill.addEventListener('click', (e) => {
          e.stopPropagation();
          profileDropdown.classList.toggle('show');
        });
        
        // Logout button
        logoutBtn.addEventListener('click', () => {
          if (confirm('Are you sure you want to log out?')) {
            window.location.href = 'backend/logout.php';
          }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
          if (!profilePill.contains(e.target)) {
            profileDropdown.classList.remove('show');
          }
        });
      }
    })
    .catch(() => { /* backend unreachable — leave the default LOGIN state */ });

  loginBtn.addEventListener('click', () => {
    window.location.href = 'login.html';
  });
