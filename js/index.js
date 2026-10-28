
  // Mobile nav toggle
  const hamburger = document.getElementById('hamburger');
  const mainNav = document.getElementById('mainNav');
  if (hamburger && mainNav) {
    hamburger.addEventListener('click', () => mainNav.classList.toggle('open'));
  }

  // Smooth scroll + active nav state for all nav-link elements (header + footer quick links)
  document.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const targetId = link.getAttribute('data-target');
      const target = targetId ? document.getElementById(targetId) : null;
      if (target) target.scrollIntoView({ behavior: 'smooth' });

      const navItems = document.querySelectorAll('#mainNav li');
      navItems.forEach(li => li.classList.remove('active'));
      if (targetId) {
        const headerMatch = document.querySelector(`#mainNav a[data-target="${targetId}"]`);
        if (headerMatch) headerMatch.closest('li').classList.add('active');
      }

      mainNav.classList.remove('open');
    });
  });

  // "View All" buttons scroll to their section
  document.querySelectorAll('.view-all').forEach(btn => {
    btn.addEventListener('click', (event) => {
      const targetId = btn.getAttribute('data-target');
      if (!targetId) return;
      const target = document.getElementById(targetId);
      if (target) {
        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth' });
      }
    });
  });

  // Hero buttons are standard anchor links; no JS handlers needed.

  // On load, ask the PHP backend whether a session is active; if so, switch
  // the button to show the profile pill with user info.
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
        logoutBtn.addEventListener('click', async () => {
          if (await window.libraryUI.confirm('Sign out of your library account?', {title:'Sign out', confirmLabel:'Sign out'})) {
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

  // Route book actions through the book list, which handles real borrow and reservation requests.
  function openBookList() {
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
  }

  document.getElementById('bookListLink').addEventListener('click', (e) => {
    e.preventDefault();
    openBookList();
  });

  function callNumberFor(book) {
    if (String(book.call_number || '').trim()) return book.call_number;
    if (!String(book.cutter_number || '').trim()) return '';
    const classification = String(book.ddc || '').trim();
    const cutter = String(book.cutter_number || '').trim();
    const year = String(book.year_of_publication || '').trim();
    const parts = [classification, cutter].filter(Boolean);
    if (year && !parts.some(part => part.split(/\s+/).includes(year))) parts.push(year);
    return parts.join(' ') || book.call_number || '';
  }

  function buildBookMarkup(book) {
    return `
      <div class="book-card" data-status="Available">
        <div class="book-cover">${book.image_path
          ? `<img src="${escapeHtml(book.image_path)}" alt="Cover of ${escapeHtml(book.title)}">`
          : '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 4.5c2.2-1 4.6-1 7 0v13.5c-2.4-1-4.8-1-7 0V4.5z"/><path d="M22 4.5c-2.2-1-4.6-1-7 0v13.5c2.4-1 4.8-1 7 0V4.5z"/></svg>'}</div>
        <div class="book-info">
          <h4>${escapeHtml(book.title)}</h4>
          <div class="author">${escapeHtml(book.author)}</div>
          ${callNumberFor(book) ? `<div class="call-number">Call No.: ${escapeHtml(callNumberFor(book))}</div>` : ''}
          <div class="genre">${escapeHtml(book.category || book.genre || 'General')}</div>
          <span class="status-pill Available">Available</span>
        </div>
      </div>
    `;
  }

  function renderAvailableBooks(grid, books) {
    const availableBooks = books.slice(0, 4);
    grid.innerHTML = availableBooks.length
      ? availableBooks.map(buildBookMarkup).join('')
      : '<p class="book-empty">No books are currently available.</p>';
  }

  function loadFeaturedBooks() {
    const grid = document.getElementById('bookGrid');
    if (!grid) return;

    fetch('backend/public-books-data.php', { credentials: 'same-origin' })
      .then(res => res.ok ? res.json() : Promise.reject(new Error('Unable to load books.')))
      .then(data => {
        const books = Array.isArray(data.books) ? data.books : [];
        renderAvailableBooks(grid, books);
      })
      .catch(() => {
        grid.innerHTML = '<p class="book-empty">Unable to load available books.</p>';
      });
  }

  loadFeaturedBooks();

  // Announcements: load real data from server with a safe fallback for local file preview.
  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    // These values are used in both text and quoted HTML attributes.
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }
  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T')+'+08:00');
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone:'Asia/Manila' });
  }
  function renderAnnouncements(list = []) {
    const holder = document.getElementById('announceList');
    if (!holder) return;

    if (list.length === 0) {
      holder.innerHTML = '<p style="text-align:center; padding:20px; color:#777;">No announcements yet.</p>';
      return;
    }

    holder.innerHTML = list.slice(0, 3).map(a => `
      <div class="announcement-item">
        <div class="top-row"><h4>${escapeHtml(a.title)}</h4><span class="badge ${escapeHtml(a.category)}">${escapeHtml(a.category)}</span></div>
        ${a.image_path ? `<img class="announcement-photo" src="${escapeHtml(a.image_path)}" alt="Photo for ${escapeHtml(a.title)}">` : ''}
        <p>${escapeHtml(a.body)}</p>
        <span class="date">&#128276; ${formatDate(a.created_at)}</span>
      </div>
    `).join('');

    document.querySelectorAll('.announcement-item').forEach(item => {
      item.addEventListener('click', () => {
        const paragraph = item.querySelector('p');
        if (paragraph) paragraph.classList.toggle('expanded');
      });
    });
  }

  function loadAnnouncements() {
    fetch('backend/announcements-data.php', { credentials: 'same-origin' })
      .then(res => res.ok ? res.json() : Promise.reject())
      .then(data => {
        const list = Array.isArray(data.announcements) ? data.announcements : [];
        renderAnnouncements(list);
      })
      .catch(() => {
        document.getElementById('announceList').innerHTML='<p role="alert">Could not load announcements. Reload to retry.</p>';
      });
  }
  loadAnnouncements();
