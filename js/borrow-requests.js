
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      document.querySelector('.account-role').textContent = data.role === 'librarian' ? 'LIBRARIAN' : 'ADMINISTRATOR';
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
  let csrfToken = '';
  let checkoutToken = '';
  let savingManual = false;
  const activeActions = new Set();

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function formatDate(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = borrowing.date(mysqlDatetime);
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone:'Asia/Manila' });
  }

  function formatDateTime(mysqlDatetime) {
    if (!mysqlDatetime) return '—';
    const d = borrowing.date(mysqlDatetime);
    if (isNaN(d)) return mysqlDatetime;
    return d.toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZone: 'Asia/Manila'
    });
  }

  function renderRows(requests) {
    const body = document.getElementById('requestsBody');
    body.innerHTML = requests.length ? requests.map(r => {
      const buttons = [`<button class="action-btn view" type="button" onclick="viewRequest(${Number(r.id)})">Details</button>`];
      if (r.status === 'pending') buttons.push(`<button class="action-btn approve" type="button" onclick="act(${Number(r.id)}, 'approve')">Approve</button>`);
      if (['pending','awaiting_pickup'].includes(r.status)) buttons.push(`<button class="action-btn reject" type="button" onclick="act(${Number(r.id)}, 'reject')">Reject</button>`);
      if (r.status === 'awaiting_pickup') buttons.push(`<button class="action-btn checkout" type="button" onclick="act(${Number(r.id)}, 'checkout')">Confirm Checkout</button>`);
      if (borrowing.isCheckedOut(r.status)) buttons.push(`<button class="action-btn return" type="button" onclick="act(${Number(r.id)}, 'return')">Confirm Return</button>`);
      return `<tr>
        <td><strong>${escapeHtml(r.student_name)}</strong><small class="reservation-meta">${escapeHtml(borrowing.roleLabel(r.borrower_role))} · ${escapeHtml(r.borrower_identifier || "Staff-verified borrower")}</small></td><td>${escapeHtml(r.book_title)}</td>
        <td>${formatDate(r.requested_at)}</td><td>${formatDate(borrowing.loanStart(r))}</td><td>${formatDate(r.due_date)}</td>
        <td><span class="status-pill ${r.status}">${borrowing.label(r.status)}</span></td>
        <td><div class="borrow-actions">${activeActions.has(Number(r.id)) ? '<span role="status">Saving…</span>' : buttons.join('')}</div></td>
      </tr>`;
    }).join('') : '<tr><td colspan="7" class="empty-msg">No requests match.</td></tr>';
  }

  function applyFilters() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    const filtered = allRequests.filter(r => {
      if (status === 'checked_out' && !borrowing.isCheckedOut(r.status)) return false;
      if (status && status !== 'checked_out' && r.status !== status) return false;
      if (!q) return true;
      return [r.student_name, r.book_title].join(' ').toLowerCase().includes(q);
    });
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allRequests.length} request${allRequests.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function loadRequests() {
    csrfToken = '';
    checkoutToken = '';
    document.getElementById('loadingMsg').style.display = 'block';
    document.getElementById('loadingMsg').textContent = 'Loading requests…';
    document.getElementById('requestsTable').style.display = 'none';
    return fetch('backend/borrow-requests-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
        allRequests = data.requests || [];
        csrfToken = data.csrfToken || '';
        checkoutToken = data.manualCheckoutToken || '';
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
      .filter(book => borrowing.requestable(book) > 0)
      .sort((a, b) => a.title.localeCompare(b.title))
      .forEach(book => {
        const option = document.createElement('option');
        option.value = `${book.title} — ${book.author} (${borrowing.requestable(book)} available)`;
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
    studentInput.placeholder = 'Type the borrower name';
    bookInput.placeholder = 'Loading books…';
    studentInput.focus();

    document.getElementById('manualCheckoutBtn').disabled = true;
    loadRequests().then(() => fetch('backend/books-data.php', { credentials: 'same-origin', cache: 'no-store' }))
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
        if (!allBooks.some(book => borrowing.requestable(book) > 0)) {
          showToast('No books are currently available to borrow.');
          return;
        }
        if (!csrfToken || !checkoutToken) throw new Error('Reload Borrow Requests to refresh your checkout form.');
        document.getElementById('manualCheckoutBtn').disabled = false;
        bookInput.placeholder = 'Type or select a book title';
      })
      .catch(err => {
        bookInput.placeholder = 'Could not load books';
        showToast(err.message || 'Could not load book options.');
      });
  }

  function closeBorrowModal() {
    if (savingManual) return;
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
      ['Borrower Name', request.student_name || '—'],
      ['Book Title', request.book_title || '—'],
      ['Author', request.author || '—'],
      ['Accession Number', request.accession_number || '—'],
      ['Status', borrowing.label(request.status)],
      ['Requested At', formatDateTime(request.requested_at)],
      ['Due Date', formatDateTime(request.due_date)],
      ['Approved At', formatDateTime(request.approved_at)],
      ['Checked Out At', formatDateTime(borrowing.loanStart(request))],
      ['Legacy Record', !request.checked_out_at && borrowing.loanStart(request) ? 'Earlier system recorded handover in Approved At; original dates retained.' : 'No'],
      ['Rejected At', formatDateTime(request.rejected_at)],
      ['Returned At', formatDateTime(request.returned_at)]
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

  document.getElementById('borrowForm').addEventListener('submit', async function(event) {
    event.preventDefault();
    const studentInput = document.getElementById('studentInput');
    const bookInput = document.getElementById('bookInput');
    const borrowerName = studentInput.value.trim();
    const availableBooks = allBooks.filter(book => borrowing.requestable(book) > 0);
    const book = matchTypedOption(
      bookInput.value,
      availableBooks,
      item => `${item.title} — ${item.author} (${borrowing.requestable(item)} available)`,
      item => item.title
    );

    if (!borrowerName) {
      showToast('Type the borrower name.');
      studentInput.focus();
      return;
    }
    if (!book) {
      showToast('Type a book title and select a matching suggestion.');
      bookInput.focus();
      return;
    }

    const save = document.getElementById('manualCheckoutBtn');
    if (save.disabled) return;
    if (!window.checkoutDue) { showToast('Reload to load the due-date confirmation.'); return; }
    const due = await window.checkoutDue.choose({source:'manual', id:Number(book.id), csrfToken, label:borrowerName + ' — ' + book.title});
    if (!due) return;
    save.disabled = true;
    savingManual = true;
    fetch('backend/borrow-request-add.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      body: JSON.stringify({ borrower_name: borrowerName, book_id: Number(book.id), due_preview_token: due.due_preview_token, confirm_checkout: true, checkout_token: checkoutToken })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not save that borrow record.');
        return data;
      })
      .then(() => {
        savingManual = false;
        showToast('Physical checkout recorded.');
        closeBorrowModal();
        loadRequests();
      })
      .catch(err => showToast(err.message))
      .finally(() => { save.disabled = false; savingManual = false; });
  });

  window.act = async function(id, action) {
    const request = allRequests.find(item => Number(item.id) === Number(id));
    if (!request || activeActions.has(id)) return;
    const prompts = {
      approve: 'Approve this request and hold one copy for pickup? This does not check out the book.',
      reject: 'Reject this request and release any pickup hold?',
      checkout: 'Confirm that the physical book is being handed to this borrower now? Review the approved borrowing period and due date before confirmation.',
      return: 'Confirm that the physical book has been returned to the library?'
    };
    if (!prompts[action]) return;
    let due = null;
    if (action === 'checkout') {
      if (!window.checkoutDue) { showToast('Reload to load the due-date confirmation.'); return; }
      due = await window.checkoutDue.choose({source:'borrow', id:Number(id), csrfToken, label:request.student_name + ' — ' + request.book_title});
      if (!due) return;
    } else if (!await window.libraryUI.confirm(`${request.student_name} — ${request.book_title}\n\n${prompts[action]}`, {danger:action === 'reject', confirmLabel:({approve:'Approve for pickup',reject:'Reject request',return:'Confirm return'})[action]})) return;
    activeActions.add(id);
    applyFilters();
    try {
      const response = await fetch('backend/borrow-request-action.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({id, action, ...(due ? {due_preview_token:due.due_preview_token} : {})})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not update that request.');
      showToast(({approve:'Approved; a copy is held for pickup.', reject:'Request rejected.', checkout:'Checkout confirmed; the loan has started.', return:'Return confirmed; inventory restored.'})[action]);
      await loadRequests();
    } catch (error) { showToast(error.message); }
    finally { activeActions.delete(id); applyFilters(); }
  };

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('statusFilter').addEventListener('change', applyFilters);

loadRequests();
