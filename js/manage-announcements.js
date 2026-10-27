
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
      document.querySelector('.account-role').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
    })
    .catch(() => {});

  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => showToast(`${el.dataset.soon} isn't wired up yet — coming soon.`));
  });

  const toast = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }

  let allAnnouncements = [], csrfToken='', submissionToken='', formToken='', pendingDelete=null, deleting=false;

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

  function renderCards(list) {
    const holder = document.getElementById('cardList');
    if (list.length === 0) {
      holder.innerHTML = '<p class="empty-msg">No announcements found.</p>';
      return;
    }
    holder.innerHTML = list.map(a => `
      <div class="announcement-card">
        <div class="card-top">
          <div class="card-title-row">
            <h3>${escapeHtml(a.title)}</h3>
            <span class="category-pill ${escapeHtml(a.category)}">${escapeHtml(a.category)}</span>
          </div>
          <div class="card-actions">
            <button class="action-btn edit" title="Edit" onclick="openEditModal(${a.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            </button>
            <button class="action-btn delete" title="Delete" onclick="deleteAnnouncement(${a.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
            </button>
          </div>
        </div>
        <div class="card-meta">${formatDate(a.created_at)}${a.posted_by_name ? ' · Posted by ' + escapeHtml(a.posted_by_name) : ''}</div>
        ${a.image_path ? `<img class="announcement-photo" src="${escapeHtml(a.image_path)}" alt="Photo for ${escapeHtml(a.title)}">` : ''}
        <div class="card-body">${escapeHtml(a.body)}</div>
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
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allAnnouncements.length} announcement${allAnnouncements.length === 1 ? '' : 's'}`;
    renderCards(filtered);
  }

  function loadAnnouncements() {
    return fetch('backend/announcement-management-data.php', { credentials: 'same-origin' })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not load announcements.');
        return data;
      })
      .then(data => {
        allAnnouncements = data.announcements || [];csrfToken=data.csrfToken;submissionToken=data.submissionToken;
        document.getElementById('addBtn').disabled=false;
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('cardList').style.display = 'flex';
        document.getElementById('countBadge').style.display = 'block';
        applyFilters();
      })
      .catch(err => {
        allAnnouncements = [];
        document.getElementById('addBtn').disabled = true;
        document.getElementById('countBadge').style.display = 'none';
        document.getElementById('cardList').innerHTML='';document.getElementById('loadingMsg').style.display='block';
        document.getElementById('loadingMsg').textContent = err.message || 'Something went wrong loading announcements.';
      });
  }

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('categoryFilter').addEventListener('change', applyFilters);

  // ---- Modal ----
  const overlay = document.getElementById('modalOverlay');
  const form = document.getElementById('announcementForm');
  const photoInput = document.getElementById('photoInput');
  const photoPreview = document.getElementById('photoPreview');

  function setPhotoPreview(src) {
    photoPreview.src = src || '';
    photoPreview.hidden = !src;
  }

  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Post New Announcement';
    document.getElementById('announcementId').value = '';
    form.reset();formToken=submissionToken;
    setPhotoPreview('');
    document.getElementById('modalError').textContent = '';
    overlay.classList.add('show');
  }

  window.openEditModal = function(id) {
    const a = allAnnouncements.find(x => Number(x.id) === Number(id));
    if (!a) return;
    photoInput.value = '';formToken=submissionToken;
    document.getElementById('modalTitle').textContent = 'Edit Announcement';
    document.getElementById('announcementId').value = a.id;
    document.getElementById('titleInput').value = a.title;
    document.getElementById('categoryInput').value = a.category;
    document.getElementById('bodyInput').value = a.body;
    document.getElementById('cancelBtn').disabled=false;
    setPhotoPreview(a.image_path || '');
    document.getElementById('modalError').textContent = '';
    overlay.classList.add('show');
  };

  photoInput.addEventListener('change', () => {
    const file = photoInput.files[0];
    setPhotoPreview(file ? URL.createObjectURL(file) : (document.getElementById('announcementId').value
      ? (allAnnouncements.find(a => a.id === Number(document.getElementById('announcementId').value)) || {}).image_path
      : ''));
  });

  window.deleteAnnouncement=function(id){
    const announcement=allAnnouncements.find(row=>Number(row.id)===Number(id));if(!announcement || deleting)return;
    pendingDelete=id;document.getElementById('deletePrompt').textContent=`Remove published announcement “${announcement.title}”? It will disappear from public pages. This cannot be undone.`;
    document.getElementById('deleteConfirmation').hidden=false;document.getElementById('confirmDelete').focus();
  };
  document.getElementById('cancelDelete').addEventListener('click',()=>{pendingDelete=null;document.getElementById('deleteConfirmation').hidden=true;});
  document.getElementById('confirmDelete').addEventListener('click',async()=>{
    if(!pendingDelete || deleting)return;deleting=true;document.getElementById('confirmDelete').disabled=true;
    try {
      const response=await fetch('backend/announcement-delete.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({id:pendingDelete})});
      const data=await response.json();if(!response.ok)throw new Error(data.error || 'Could not remove announcement.');
      document.getElementById('deleteConfirmation').hidden=true;pendingDelete=null;showToast('Announcement removed.');await loadAnnouncements();
    }catch(error){showToast(error.message || 'Could not reach the server.');}
    finally{deleting=false;document.getElementById('confirmDelete').disabled=false;}
  });

  document.getElementById('addBtn').addEventListener('click', openAddModal);
  document.getElementById('cancelBtn').addEventListener('click', () => overlay.classList.remove('show'));
  overlay.addEventListener('click', (e) => { if (e.target === overlay && !document.getElementById('saveBtn').disabled) overlay.classList.remove('show'); });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if(document.getElementById('saveBtn').disabled)return;
    const errorEl = document.getElementById('modalError');
    errorEl.textContent = '';

    const payload = new FormData();
    payload.append('submission_key',formToken);
    payload.append('id', document.getElementById('announcementId').value);
    payload.append('title', document.getElementById('titleInput').value.trim());
    payload.append('category', document.getElementById('categoryInput').value);
    payload.append('body', document.getElementById('bodyInput').value.trim());
    if (photoInput.files[0]) payload.append('photo', photoInput.files[0]);

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;document.getElementById('cancelBtn').disabled=true;
    saveBtn.textContent = 'Saving…';

    fetch('backend/announcement-save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers:{'X-CSRF-Token':csrfToken},
      body: payload
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not save that announcement.');
        return data;
      })
      .then(data => {
        submissionToken=data.submissionToken;
        overlay.classList.remove('show');
        showToast(payload.get('id') ? 'Announcement updated.' : 'Announcement posted.');
        loadAnnouncements();
      })
      .catch(err => { errorEl.textContent = err.message; })
      .finally(() => {
        saveBtn.disabled = false;document.getElementById('cancelBtn').disabled=false;
        saveBtn.textContent = 'Save and Publish';
      });
  });

loadAnnouncements();
