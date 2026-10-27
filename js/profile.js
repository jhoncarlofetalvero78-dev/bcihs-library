
  /* ===== DATA MODEL ===== */
  // Default profile data per role; replaced when backend responds
  let profileData = {
    role: 'student',
    full_name: '',
    student_id: '',
    year_section: '',
    grade_level: '',
    section: '',
    id_number: '',
    department: '',
    email: '',
    phone: '',
    address: 'Batangas City, Philippines'
  };

  // Field definitions per role (matching register forms)
  const roleFields = {
    student: {
      profileFields: [
        { key: 'full_name', label: 'Full Name', icon: 'user' },
        { key: 'student_id', label: 'Student ID', icon: 'card', immutable: true },
        { key: 'grade_level', label: 'Grade Level', icon: 'book' },
        { key: 'section', label: 'Section', icon: 'home' },
        { key: 'email', label: 'Email Address', icon: 'mail' },
        { key: 'phone', label: 'Phone Number', icon: 'phone' },
        { key: 'address', label: 'Address', icon: 'map' }
      ],
      editFields: [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'grade_level', label: 'Grade Level', type: 'text' },
        { key: 'section', label: 'Section', type: 'text' },
        { key: 'email', label: 'Email Address', type: 'email' },
        { key: 'phone', label: 'Phone Number', type: 'tel', required: true },
        { key: 'address', label: 'Address', type: 'text' }
      ],
      gradeLabel: 'Grade Level',
      sectionLabel: 'Section'
    },
    teacher: {
      profileFields: [
        { key: 'full_name', label: 'Full Name', icon: 'user' },
        { key: 'id_number', label: 'ID Number', icon: 'card', immutable: true },
        { key: 'department', label: 'Department / Faculty', icon: 'home' },
        { key: 'email', label: 'Email Address', icon: 'mail' },
        { key: 'phone', label: 'Phone Number', icon: 'phone' },
        { key: 'address', label: 'Address', icon: 'map' }
      ],
      editFields: [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'department', label: 'Department / Faculty', type: 'text' },
        { key: 'email', label: 'Email Address', type: 'email' },
        { key: 'phone', label: 'Phone Number', type: 'tel', required: true },
        { key: 'address', label: 'Address', type: 'text' }
      ],
      gradeLabel: 'Department',
      sectionLabel: 'Section'
    },
    librarian: {
      profileFields: [
        { key: 'full_name', label: 'Full Name', icon: 'user' },
        { key: 'id_number', label: 'ID Number', icon: 'card', immutable: true },
        { key: 'department', label: 'Department / Faculty', icon: 'home' },
        { key: 'email', label: 'Email Address', icon: 'mail' },
        { key: 'phone', label: 'Phone Number', icon: 'phone' },
        { key: 'address', label: 'Address', icon: 'map' }
      ],
      editFields: [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'department', label: 'Department / Faculty', type: 'text' },
        { key: 'email', label: 'Email Address', type: 'email' },
        { key: 'phone', label: 'Phone Number', type: 'tel', required: true },
        { key: 'address', label: 'Address', type: 'text' }
      ],
      gradeLabel: 'Department',
      sectionLabel: 'Section'
    },
    admin: {
      profileFields: [
        { key: 'full_name', label: 'Full Name', icon: 'user' },
        { key: 'id_number', label: 'ID Number', icon: 'card', immutable: true },
        { key: 'department', label: 'Department / Faculty', icon: 'home' },
        { key: 'email', label: 'Email Address', icon: 'mail' },
        { key: 'phone', label: 'Phone Number', icon: 'phone' },
        { key: 'address', label: 'Address', icon: 'map' }
      ],
      editFields: [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'department', label: 'Department / Faculty', type: 'text' },
        { key: 'email', label: 'Email Address', type: 'email' },
        { key: 'phone', label: 'Phone Number', type: 'tel', required: true },
        { key: 'address', label: 'Address', type: 'text' }
      ],
      gradeLabel: 'Department',
      sectionLabel: 'Section'
    },
    visitor: {
      profileFields: [
        { key: 'full_name', label: 'Full Name', icon: 'user' },
        { key: 'email', label: 'Email Address', icon: 'mail' },
        { key: 'phone', label: 'Phone Number', icon: 'phone' },
        { key: 'address', label: 'Address', icon: 'map' }
      ],
      editFields: [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'email', label: 'Email Address', type: 'email', required: true },
        { key: 'phone', label: 'Phone Number', type: 'tel', required: true },
        { key: 'address', label: 'Address', type: 'text' }
      ],
      gradeLabel: 'Email',
      sectionLabel: 'Address'
    }
  };

  const iconPaths = {
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    card: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    home: '<path d="M3 9l9-6 9 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 21v-6h6v6"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>',
    phone: '<path d="M22 16.9a1.6 1.6 0 0 0-1.1-1.5c-.4-.2-.9-.1-1.2.2l-1.2 1a12 12 0 0 1-6.5-6.5l1-1.2a1.5 1.5 0 0 0 .2-1.2c-.3-.4-.7-.7-1.2-.9A1.6 1.6 0 0 0 7 4c-3.5 0-6.5 3-6.5 6.5 0 7.2 7.3 14.5 14.5 14.5 3.5 0 6.5-3 6.5-6.5z"/>',
    map: '<path d="M20 10c0 5.5-4.5 10-10 10S0 15.5 0 10 4.5 0 10 0s10 4.5 10 10z"/><path d="M6 10h8M10 6v8"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>'
  };

  function getRoleFields(role) {
    return roleFields[role] || roleFields.student;
  }

/* ===== DOM HELPERS ===== */
function escapeHtml(str){
  const d = document.createElement('div');
  d.textContent = str == null ? '' : String(str);
  return d.innerHTML;
}

function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2400);
}

/* ===== TAB NAVIGATION ===== */
const tabBtns = document.querySelectorAll('.tab-btn');
const tabPanels = document.querySelectorAll('.tab-panel');

tabBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    const target = btn.dataset.tab;
    tabBtns.forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected','false'); });
    tabPanels.forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    btn.setAttribute('aria-selected','true');
    document.getElementById('panel' + target.charAt(0).toUpperCase() + target.slice(1)).classList.add('active');
  });
});

/* ===== POPULATE FORM FROM DATA ===== */
function populateForm(){
  const fields = getRoleFields(profileData.role).editFields;
  const container = document.getElementById('modalInner');
  container.innerHTML = '<h2 id="modalTitle">Edit Profile</h2><form id="editForm" novalidate>' +
    fields.map(f => `
      <div class="form-group">
        <label for="edit_${f.key}">${escapeHtml(f.label)}${f.required ? '<span style="color:#e0303a;">*</span>' : ''}</label>
        <input type="${f.type}" id="edit_${f.key}" name="${f.key}" value="${escapeHtml(profileData[f.key] || '')}" ${f.required ? 'required' : ''}>
      </div>
    `).join('') +
    '<div class="modal-actions">' +
    '<button type="button" class="btn-cancel" id="cancelEditBtn">Cancel</button>' +
    '<button type="submit" class="btn-save" id="saveEditBtn">Save</button>' +
    '</div></form>';
  const phoneInput = document.getElementById('edit_phone');
  if (phoneInput) {
    phoneInput.placeholder = '+63 912 345 6789';
    phoneInput.setAttribute('inputmode', 'tel');
    phoneInput.setAttribute('minlength', '11');
    phoneInput.setAttribute('maxlength', '16');
    phoneInput.value = window.philippineMobilePhone.format(phoneInput.value);
    phoneInput.addEventListener('input', () => {
      phoneInput.value = window.philippineMobilePhone.format(phoneInput.value);
    });
  }
  document.getElementById('cancelEditBtn').addEventListener('click', () => editModal.classList.remove('show'));
  document.getElementById('editForm').addEventListener('submit', handleSave);
}

/* ===== RENDER PROFILE DETAILS ===== */
function renderDetails(){
  const fields = getRoleFields(profileData.role).profileFields;
  const grid = document.getElementById('detailsGrid');
  grid.innerHTML = fields.map(f => {
    const value = profileData[f.key] || '—';
    return `
    <div class="detail-field">
      <span class="detail-label">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${iconPaths[f.icon] || iconPaths.user}</svg>
        ${escapeHtml(f.label)}
      </span>
      <input class="detail-value" readonly value="${escapeHtml(value)}" aria-label="${escapeHtml(f.label)}">
    </div>`;
  }).join('');

  document.getElementById('profileName').textContent = profileData.full_name || 'Your Profile';
  const role = profileData.role;
  if (role === 'student') {
    document.getElementById('profileGrade').textContent = (profileData.grade_level || '—') + ' — ' + (profileData.section || '—');
    document.getElementById('profileId').textContent = profileData.student_id || '—';
   } else if (role === 'teacher' || role === 'librarian') {
     document.getElementById('profileGrade').textContent = profileData.department || '—';
     document.getElementById('profileId').textContent = profileData.id_number || '—';
   } else if (role === 'admin') {
     document.getElementById('profileGrade').textContent = profileData.department || 'ADMINISTRATOR';
     document.getElementById('profileId').textContent = profileData.id_number || '—';
   } else {
     document.getElementById('profileGrade').textContent = 'Visitor';
     document.getElementById('profileId').textContent = '—';
   }

   // Toggle Admin Panel button for librarian and admin roles
   const adminBtn = document.getElementById('adminPanelBtn');
   if (adminBtn) {
     adminBtn.style.display = (role === 'librarian' || role === 'admin') ? 'inline-flex' : 'none';
   }
 }

/* ===== EDIT PROFILE MODAL ===== */
const editModal = document.getElementById('editModal');
const editBtn = document.getElementById('editProfileBtn');

 editBtn.addEventListener('click', () => {
   populateForm();
   editModal.classList.add('show');
 });

 // Admin Panel button — navigate to the admin dashboard
 document.getElementById('adminPanelBtn').addEventListener('click', () => {
   window.location.href = 'admin-dashboard.html';
 });

function handleSave(e){
  e.preventDefault();
  const phoneInput = document.getElementById('edit_phone');
  const normalizedPhone = phoneInput
    ? window.philippineMobilePhone.normalize(phoneInput.value)
    : null;
  if (phoneInput && !normalizedPhone) {
    showToast('Enter a valid Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX.');
    phoneInput.focus();
    return;
  }

  const saveBtn = document.getElementById('saveEditBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving…';

  const fields = getRoleFields(profileData.role).editFields;
  fields.forEach(f => {
    profileData[f.key] = document.getElementById('edit_' + f.key).value.trim();
  });
  if (phoneInput) {
    profileData.phone = window.philippineMobilePhone.format(normalizedPhone);
  }

  renderDetails();
  editModal.classList.remove('show');
  showToast('Profile updated successfully.');

  // Persist to PHP backend if available
  const payload = new URLSearchParams();
  payload.append('full_name', profileData.full_name);
  payload.append('phone', normalizedPhone || profileData.phone);
  payload.append('email', profileData.email);
  payload.append('address', profileData.address);
  if (profileData.role === 'student') {
    payload.append('year_section', (profileData.grade_level || '') + ' — ' + (profileData.section || ''));
   } else if (profileData.role === 'teacher' || profileData.role === 'librarian' || profileData.role === 'admin') {
    payload.append('department', profileData.department || '');
  }

  fetch('backend/profile-update.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: payload.toString()
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) showToast(data.message);
    else showToast(data.message || 'Profile updated locally.');
  })
  .catch(() => {
    /* Backend unavailable — data persisted locally */
  })
  .finally(() => {
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save';
  });
}

editModal.addEventListener('click', (e) => {
  if (e.target === editModal) editModal.classList.remove('show');
});

/* ===== BORROWING HISTORY ===== */
// Realistic sample library data
const sampleBorrowingHistory = [
  { book_title: 'To Kill a Mockingbird', author: 'Harper Lee', borrow_date: '2026-09-01', due_date: '2026-09-15', return_date: '2026-09-12', status: 'Returned' },
  { book_title: '1984', author: 'George Orwell', borrow_date: '2026-09-10', due_date: '2026-09-24', return_date: '—', status: 'Borrowed' },
  { book_title: 'The Great Gatsby', author: 'F. Scott Fitzgerald', borrow_date: '2026-08-15', due_date: '2026-08-29', return_date: '2026-08-25', status: 'Returned' },
  { book_title: 'Pride and Prejudice', author: 'Jane Austen', borrow_date: '2026-09-05', due_date: '2026-09-19', return_date: '—', status: 'Overdue' },
  { book_title: 'Brave New World', author: 'Aldous Huxley', borrow_date: '2026-08-20', due_date: '2026-09-03', return_date: '2026-09-02', status: 'Returned' }
];

function renderBorrowingHistory(data){
  const container = document.getElementById('historyContent');
  if (!data || data.length === 0){
    container.innerHTML = '<p class="empty-msg">No borrowing history found.</p>';
    updateStats([]);
    return;
  }
  const rows = data.map(row => {
    const statusClass = row.status.toLowerCase();
    return `<tr>
      <td><strong>${escapeHtml(row.book_title)}</strong></td>
      <td>${escapeHtml(row.author)}</td>
      <td>${escapeHtml(row.borrow_date)}</td>
      <td>${escapeHtml(row.due_date)}</td>
      <td>${escapeHtml(row.return_date)}</td>
      <td><span class="status-badge ${statusClass}">${escapeHtml(row.status)}</span></td>
    </tr>`;
  }).join('');
  container.innerHTML = `
    <table class="history-table">
      <thead>
        <tr>
          <th>Book Title</th>
          <th>Author</th>
          <th>Borrow Date</th>
          <th>Due Date</th>
          <th>Return Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
  updateStats(data);
}

function updateStats(data){
  let borrowed = 0, returned = 0, total = 0;
  if (data && data.length) {
    total = data.length;
    data.forEach(item => {
      const s = (item.status || '').toLowerCase();
      if (s === 'borrowed' || s === 'overdue' || s === 'pending' || s === 'approved') borrowed++;
      else if (s === 'returned' || s === 'fulfilled') returned++;
    });
  }
  const statBorrowed = document.getElementById('statBorrowed');
  const statReturned = document.getElementById('statReturned');
  const statTotal = document.getElementById('statTotal');
  if (statBorrowed) statBorrowed.textContent = borrowed;
  if (statReturned) statReturned.textContent = returned;
  if (statTotal) statTotal.textContent = total;
}

function loadBorrowingHistory(){
  fetch('backend/borrowing-history-data.php', { credentials: 'same-origin' })
    .then(res => {
      if (res.status === 401) throw new Error('not_logged_in');
      if (!res.ok) throw new Error('failed');
      return res.json();
    })
    .then(data => {
      const requests = data.requests || [];
      // Map backend fields to display fields
      const mapped = requests.map(r => ({
        book_title: r.book_title || 'Unknown',
        author: r.author || '—',
        borrow_date: r.requested_at || '—',
        due_date: r.due_date || '—',
        return_date: r.returned_at || '—',
        status: r.status ? r.status.charAt(0).toUpperCase() + r.status.slice(1) : 'Unknown'
      }));
      renderBorrowingHistory(mapped);
    })
    .catch(() => {
      // Fallback to sample data when backend is unavailable
      renderBorrowingHistory(sampleBorrowingHistory);
    });
}

  /* ===== BACK BUTTON ===== */
  document.getElementById('backBtn').addEventListener('click', () => {
    if (document.referrer && document.referrer !== window.location.href) {
      window.history.back();
    } else {
      window.location.href = 'backend/logout.php';
    }
  });

/* ===== INITIALIZE ===== */
(function init(){
  // Load profile data from PHP backend
  fetch('backend/profile-data.php', { credentials: 'same-origin' })
    .then(res => {
      if (!res.ok) throw new Error('not logged in');
      return res.json();
    })
    .then(data => {
      if (data.loggedIn === false) throw new Error('not logged in');
      // Update profileData with backend values
      profileData.role = data.role || 'student';
      if (data.full_name) profileData.full_name = data.full_name;
      if (data.student_id) profileData.student_id = data.student_id;
      if (data.id_number) profileData.id_number = data.id_number;
      if (data.year_section) {
        const normalized = data.year_section.replace(/[-–—]/g, '—');
        profileData.grade_level = normalized.split(' — ')[0] || data.year_section;
        profileData.section = normalized.split(' — ')[1] || '';
      }
      if (data.department) profileData.department = data.department;
      if (data.email) profileData.email = data.email;
      if (data.phone) {
        const normalizedPhone = window.philippineMobilePhone.normalize(data.phone);
        profileData.phone = normalizedPhone
          ? window.philippineMobilePhone.format(normalizedPhone)
          : data.phone;
      }
      renderDetails();
    })
    .catch(() => {
      // Default to student role with sample data for display
      profileData.role = 'student';
      profileData.full_name = 'Maria Santos';
      profileData.student_id = '2026-00142';
      profileData.grade_level = 'Grade 10';
      profileData.section = 'Rizal';
      profileData.email = 'maria.santos@bcihs.edu.ph';
      profileData.phone = '+63 912 345 6789';
      profileData.address = 'Batangas City, Philippines';
      renderDetails();
    });

  // Load borrowing history
  loadBorrowingHistory();
})();
