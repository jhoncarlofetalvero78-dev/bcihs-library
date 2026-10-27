
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

  document.getElementById('bookListLink').addEventListener('click', event => {
    event.preventDefault();
    fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(res => res.json())
      .then(data => {
        window.location.href = data.loggedIn ? 'browse-books.html' : 'login.html';
      })
      .catch(() => {
        window.location.href = 'login.html';
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

  // ---- Announcements list ----
  let allAnnouncements = [];

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    // These values are used in both text and quoted HTML attributes.
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function formatDate(mysqlDatetime) {
    const d = new Date((mysqlDatetime || '').replace(' ', 'T')+'+08:00');
    if (isNaN(d)) return mysqlDatetime || '';
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric', timeZone:'Asia/Manila' });
  }

  function render(items) {
    const list = document.getElementById('announceList');
    if (items.length === 0) {
      list.innerHTML = '<p class="empty-msg">No announcements match.</p>';
      return;
    }
    list.innerHTML = items.map(a => `
      <div class="announce-card">
        <div class="top-row">
          <h3>${escapeHtml(a.title)}</h3>
          <span class="badge ${escapeHtml(a.category)}">${escapeHtml(a.category)}</span>
        </div>
        ${a.image_path ? `<img class="announcement-photo" src="${escapeHtml(a.image_path)}" alt="Photo for ${escapeHtml(a.title)}">` : ''}
        <p>${escapeHtml(a.body)}</p>
        <span class="date">&#128276; ${formatDate(a.created_at)}</span>
      </div>
    `).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const category = document.getElementById('categoryFilter').value;
    const filtered = allAnnouncements.filter(a => {
      if (category && a.category !== category) return false;
      if (!q) return true;
      return [a.title, a.body].join(' ').toLowerCase().includes(q);
    });
    render(filtered);
  }

  fetch('backend/announcements-data.php')
    .then(res => res.ok ? res.json() : Promise.reject(new Error('Could not load announcements.')))
    .then(data => {
      allAnnouncements = data.announcements || [];
      document.getElementById('loadingMsg').style.display = 'none';
      document.getElementById('announceList').style.display = 'grid';
      applyFilters();
    })
    .catch(() => {
      document.getElementById('loadingMsg').textContent = 'Could not load announcements right now.';
    });

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('categoryFilter').addEventListener('change', applyFilters);
