
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
    })
    .catch(() => {});

  const toast = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }
  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => showToast(`${el.dataset.soon} isn't wired up yet — coming soon.`));
  });

  let allRequests = [];
  let allBooks = [];

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function formatDateTime(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = new Date(mysqlDatetime.replace(' ', 'T'));
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function renderRows(requests) {
    const body = document.getElementById('requestsBody');
    if (requests.length === 0) {
      body.innerHTML = '<tr><td colspan="6" class="empty-msg">No requests match.</td></tr>';
      return;
    }
    body.innerHTML = requests.map(r => `
      <tr>
        <td>${escapeHtml(r.student_name)}</td>
        <td>${escapeHtml(r.book_title)}</td>
        <td>${formatDate(r.requested_at)}</td>
        <td>${formatDate(r.due_date)}</td>
        <td><span class="status-pill ${r.status}">${r.status.charAt(0).toUpperCase() + r.status.slice(1)}</span></td>
        <td>
          <button class="action-btn view" type="button" title="View details" aria-label="View details" onclick="viewRequest(${Number(r.id)})">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
          <button class="action-btn approve" title="Approve" ${r.status !== 'pending' ? 'disabled' : ''} onclick="act(${r.id}, 'approve')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
          </button>
          <button class="action-btn reject" title="Reject" ${r.status !== 'pending' ? 'disabled' : ''} onclick="act(${r.id}, 'reject')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M6 6l12 12M18 6L6 18"/></svg>
          </button>
          <button class="action-btn return" title="Mark Returned" ${r.status !== 'approved' ? 'disabled' : ''} onclick="act(${r.id}, 'return')">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M9 14l-4-4 4-4"/><path d="M5 10h9a5 5 0 0 1 5 5v1"/></svg>
          </button>
        </td>
      </tr>
    `).join('');
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const filtered = allRequests.filter(r => {
      if (status && r.status !== status) return false;
      if (!q) return true;
      return [r.student_name, r.book_title].join(' ').toLowerCase().includes(q);
    });
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allRequests.length} request${allRequests.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function loadRequests() {
    return fetch('backend/borrow-requests-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
        allRequests = data.requests || [];
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('requestsTable').style.display = 'table';
        document.getElementById('countBadge').style.display = 'block';
        applyFilters();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') { window.location.href = 'admin-login.html'; return; }
        document.getElementById('loadingMsg').textContent = 'Something went wrong loading requests.';
      });
  }

  function populateBorrowFormOptions() {
    const bookOptions = document.getElementById('bookOptions');
    bookOptions.replaceChildren();

    allBooks
      .filter(book => book.total_copies > book.borrowed_copies)
      .sort((a, b) => a.title.localeCompare(b.title))
      .forEach(book => {
        const option = document.createElement('option');
        option.value = `${book.title} — ${book.author} (${book.total_copies - book.borrowed_copies} available)`;
        bookOptions.appendChild(option);
      });
  }

  function matchTypedOption(value, options, getLabel, getName) {
    const normalizedValue = value.trim().toLocaleLowerCase();
    if (!normalizedValue) return null;

    const exactLabelMatches = options.filter(option =>
      getLabel(option).toLocaleLowerCase() === normalizedValue
    );
    if (exactLabelMatches.length === 1) return exactLabelMatches[0];

    const exactNameMatches = options.filter(option =>
      getName(option).trim().toLocaleLowerCase() === normalizedValue
    );
    return exactNameMatches.length === 1 ? exactNameMatches[0] : null;
  }

  function openBorrowModal() {
    const modal = document.getElementById('borrowModal');
    const studentInput = document.getElementById('studentInput');
    const bookInput = document.getElementById('bookInput');
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('bookOptions').replaceChildren();
    studentInput.value = '';
    bookInput.value = '';
    studentInput.placeholder = 'Type the student name';
    bookInput.placeholder = 'Loading books…';
    studentInput.focus();

    fetch('backend/books-data.php', { credentials: 'same-origin' })
      .then(async response => {
        if (!response.ok) {
          if (response.status === 401) {
            window.location.href = 'admin-login.html';
            throw new Error('Your staff session has expired. Please sign in again.');
          }
          throw new Error('Could not load book options.');
        }
        const bookData = await response.json();
        allBooks = bookData.books || [];
        populateBorrowFormOptions();
        if (!allBooks.some(book => book.total_copies > book.borrowed_copies)) {
          showToast('No books are currently available to borrow.');
          return;
        }
        bookInput.placeholder = 'Type or select a book title';
      })
      .catch(err => {
        bookInput.placeholder = 'Could not load books';
        showToast(err.message || 'Could not load book options.');
      });
  }

  function closeBorrowModal() {
    document.getElementById('borrowModal').classList.add('hidden');
    document.getElementById('borrowModal').setAttribute('aria-hidden', 'true');
    document.getElementById('borrowForm').reset();
  }

  document.getElementById('addBorrowBtn').addEventListener('click', openBorrowModal);
  document.getElementById('closeBorrowModal').addEventListener('click', closeBorrowModal);
  document.getElementById('cancelBorrowBtn').addEventListener('click', closeBorrowModal);
  document.getElementById('borrowModal').addEventListener('click', (event) => {
    if (event.target === event.currentTarget) closeBorrowModal();
  });

  function closeRequestDetails() {
    const modal = document.getElementById('requestDetailsModal');
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  window.viewRequest = function(id) {
    const request = allRequests.find(item => Number(item.id) === Number(id));
    if (!request) {
      showToast('Could not find that borrow request.');
      return;
    }

    const rows = [
      ['Student Name', request.student_name || '—'],
      ['Book Title', request.book_title || '—'],
      ['Author', request.author || '—'],
      ['Accession Number', request.accession_number || '—'],
      ['Status', request.status ? request.status.charAt(0).toUpperCase() + request.status.slice(1) : '—'],
      ['Date & Time', formatDateTime(request.requested_at)],
      ['Due Date', formatDate(request.due_date)],
      ['Approved At', formatDate(request.approved_at)],
      ['Rejected At', formatDate(request.rejected_at)],
      ['Returned At', formatDate(request.returned_at)]
    ];
    document.getElementById('requestDetailsBody').innerHTML = rows.map(([label, value]) => `
      <div class="request-detail-row"><span class="label">${label}</span><span class="value">${escapeHtml(value)}</span></div>
    `).join('');

    const modal = document.getElementById('requestDetailsModal');
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  };

  document.getElementById('closeRequestDetails').addEventListener('click', closeRequestDetails);
  document.getElementById('requestDetailsModal').addEventListener('click', (event) => {
    if (event.target === event.currentTarget) closeRequestDetails();
  });

  document.getElementById('borrowForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const studentInput = document.getElementById('studentInput');
    const bookInput = document.getElementById('bookInput');
    const borrowerName = studentInput.value.trim();
    const availableBooks = allBooks.filter(book => book.total_copies > book.borrowed_copies);
    const book = matchTypedOption(
      bookInput.value,
      availableBooks,
      item => `${item.title} — ${item.author} (${item.total_copies - item.borrowed_copies} available)`,
      item => item.title
    );
    const dueDate = document.getElementById('dueDate').value;

    if (!borrowerName) {
      showToast('Type the student name.');
      studentInput.focus();
      return;
    }
    if (!book) {
      showToast('Type a book title and select a matching suggestion.');
      bookInput.focus();
      return;
    }

    fetch('backend/borrow-request-add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ borrower_name: borrowerName, book_id: Number(book.id), due_date: dueDate || null })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not save that borrow record.');
        return data;
      })
      .then(() => {
        showToast('Borrow record added.');
        closeBorrowModal();
        loadRequests();
      })
      .catch(err => showToast(err.message));
  });

  window.act = function(id, action) {
    const labels = { approve: 'approved', reject: 'rejected', return: 'marked as returned' };
    fetch('backend/borrow-request-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id, action })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not update that request.');
        return data;
      })
      .then(() => {
        showToast(`Request ${labels[action]}.`);
        loadRequests();
      })
      .catch(err => showToast(err.message));
  };

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('statusFilter').addEventListener('change', applyFilters);

loadRequests();
