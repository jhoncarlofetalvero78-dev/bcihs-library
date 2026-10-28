(() => {
  const sidebar = document.querySelector('.sidebar');
  const topbar = document.querySelector('.topbar');
  const brand = document.querySelector('.topbar-brand');
  const account = topbar && topbar.querySelector('.topbar-account');

  if (!sidebar || !topbar || !brand || !account) {
    console.error('Admin navigation could not initialize: expected sidebar branding or account was not found.');
    return;
  }

  const navList = sidebar.querySelector('.nav-list:not(.logout-item)');
  if (navList) {
    let attendanceLink = navList.querySelector('a[href="attendance.html"]');
    let attendanceItem = attendanceLink && attendanceLink.closest('li');
    if (!attendanceItem) {
      attendanceItem = document.createElement('li');
      attendanceLink = document.createElement('a');
      attendanceLink.href = 'attendance.html';
      attendanceLink.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
      attendanceLink.append(document.createTextNode('Attendance'));
      attendanceItem.append(attendanceLink);
    }

    attendanceItem.hidden = false;
    attendanceItem.style.display = 'list-item';
    attendanceLink.hidden = false;
    attendanceLink.style.display = 'flex';

    const isAttendancePage = window.location.pathname.endsWith('/attendance.html');
    attendanceItem.classList.toggle('active', isAttendancePage);
    if (isAttendancePage) {
      attendanceLink.setAttribute('aria-current', 'page');
    } else {
      attendanceLink.removeAttribute('aria-current');
    }
  }

  const sectionNames = {'manage-books.html':'Manage Books','borrow-requests.html':'Borrow Requests','transactions.html':'Transactions'};
  sidebar.querySelectorAll('.nav-list a').forEach(link => {
    const name = sectionNames[link.getAttribute('href')];
    if (name) {
      Array.from(link.childNodes).filter(node => node.nodeType === 3).forEach(node => {
        if (node.textContent.trim()) node.textContent = ' ' + name;
      });
      link.setAttribute('aria-label', name);
    }
    if (link.getAttribute('href') === window.location.pathname.split('/').pop()) link.setAttribute('aria-current','page');
  });

  const navigationLinks = Array.from(sidebar.querySelectorAll('.nav-list li a'));
  const navigationTitles = new Map(
    navigationLinks.map((link) => [link, link.getAttribute('title')])
  );

  const header = document.createElement('div');
  header.className = 'sidebar-header';

  const toggle = document.createElement('button');
  toggle.className = 'sidebar-toggle';
  toggle.type = 'button';
  toggle.setAttribute('aria-expanded', 'true');
  toggle.setAttribute('aria-label', 'Minimize navigation');
  toggle.title = 'Minimize navigation';
  toggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>';

  header.append(brand, toggle);
  sidebar.prepend(header);

  const mobileLayout = window.matchMedia('(max-width: 760px)');
  const collapsedStorageKey = 'bcihsAdminSidebarCollapsed';
  const setToggleState = (collapsed) => {
    const label = collapsed ? 'Expand navigation' : 'Minimize navigation';
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', label);
    toggle.title = label;
    navigationLinks.forEach((link) => {
      const originalTitle = navigationTitles.get(link);
      if (collapsed) {
        link.title = originalTitle || link.textContent.trim();
      } else if (originalTitle) {
        link.title = originalTitle;
      } else {
        link.removeAttribute('title');
      }
    });
  };
  const syncLayout = () => {
    topbar.append(account);
    if (mobileLayout.matches) {
      sidebar.classList.remove('is-collapsed');
      sidebar.classList.add('is-collapsed');
      setToggleState(true);
      topbar.insertBefore(brand, account);
      header.append(toggle);
    } else {
      sidebar.classList.toggle('is-collapsed', localStorage.getItem(collapsedStorageKey) === 'true');
      header.prepend(brand);
      header.append(toggle);
      setToggleState(sidebar.classList.contains('is-collapsed'));
    }
  };
  syncLayout();
  sidebar.classList.add('sidebar-ready');
  mobileLayout.addEventListener('change', syncLayout);

  toggle.addEventListener('click', () => {
    const collapsed = sidebar.classList.toggle('is-collapsed');
    if (!mobileLayout.matches) {
      localStorage.setItem(collapsedStorageKey, String(collapsed));
    }
    setToggleState(collapsed);
  });
})();
