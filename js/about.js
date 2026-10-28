
  // Mobile nav toggle
  const hamburger = document.getElementById('hamburger');
  const mainNav = document.getElementById('mainNav');
  hamburger.addEventListener('click', () => mainNav.classList.toggle('open'));

  // On load, ask the PHP backend whether a session is active; if so, show
  // the same profile pill and dropdown used on the homepage.
  const loginBtn = document.getElementById('loginBtn');
  const loginMenu = document.getElementById('loginMenu');
  const profilePill = document.getElementById('profilePill');
  const profileName = document.getElementById('profileName');
  const profileRole = document.getElementById('profileRole');
  const profileDropdown = document.getElementById('profileDropdown');
  const logoutBtn = document.getElementById('logoutBtn');

  fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
    .then(res => res.json())
    .then(data => {
      if (data.loggedIn) {
        loginMenu.style.display = 'none';
        profilePill.classList.add('visible');
        profileName.textContent = data.fullName || 'User';
        profileRole.textContent = (data.role || 'Role').charAt(0).toUpperCase() + (data.role || 'Role').slice(1);

        profilePill.addEventListener('click', (e) => {
          e.stopPropagation();
          profileDropdown.classList.toggle('show');
        });

        logoutBtn.addEventListener('click', async () => {
          if (await window.libraryUI.confirm('Sign out of your library account?', {title:'Sign out', confirmLabel:'Sign out'})) {
            window.location.href = 'backend/logout.php';
          }
        });

        document.addEventListener('click', (e) => {
          if (!profilePill.contains(e.target)) {
            profileDropdown.classList.remove('show');
          }
        });
      }
    })
    .catch(() => { /* backend unreachable — leave the default ACCOUNT state */ });

  loginBtn.addEventListener('click', () => {
    window.location.href = 'login.html';
  });

  // Book List link: send signed-out users to the shared sign-in page.
  document.getElementById('bookListLink').addEventListener('click', (e) => {
    e.preventDefault();
    fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(res => res.json())
      .then(data => {
        if (data.loggedIn) {
          window.location.href = 'browse-books.html';
        } else {
          window.location.href = 'login.html';
        }
      })
      .catch(() => {
        window.location.href = 'login.html';
      });
  });
