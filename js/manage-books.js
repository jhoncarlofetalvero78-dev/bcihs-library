
  // ---- Guard: only admins may view this page ----
  fetch('backend/admin-session-status.php', { credentials: 'same-origin' })
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) { window.location.href = 'admin-login.html'; return; }
      const name = data.username || 'Admin';
      document.getElementById('adminName').textContent = name;
      document.getElementById('adminInitial').textContent = name.charAt(0).toUpperCase();
    })
    .catch(() => {});

  document.querySelectorAll('[data-soon]').forEach(el => {
    el.addEventListener('click', () => showSoonToast(el.dataset.soon));
  });

  const toast = document.getElementById('toast');
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2400);
  }
  function showSoonToast(label) {
    showToast(`${label} isn't wired up yet — coming soon.`);
  }

  let allBooks = [];

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function callNumberFor(book) {
    const classification = String(book.ddc || '').trim();
    const cutter = String(book.cutter_number || '').trim();
    const year = String(book.year_of_publication || '').trim();
    const parts = [classification, cutter].filter(Boolean);
    if (year && !parts.some(part => part.split(/\s+/).includes(year))) parts.push(year);
    return parts.join(' ') || book.call_number || '';
  }

  function renderRows(books) {
    const body = document.getElementById('booksBody');
    if (books.length === 0) {
      body.innerHTML = '<tr><td colspan="12" class="empty-msg">No books found.</td></tr>';
      return;
    }
      body.innerHTML = books.map(b => {
      const available = b.total_copies - b.borrowed_copies;
      const category = b.category || b.genre || '';
      const classification = b.ddc || b.call_number || '';
      return `
        <tr>
          <td>${escapeHtml(b.isbn) || '&mdash;'}</td>
          <td>${escapeHtml(classification) || '&mdash;'}</td>
          <td>${escapeHtml(b.cutter_number) || '&mdash;'}</td>
          <td>${escapeHtml(callNumberFor(b)) || '&mdash;'}</td>
          <td>${escapeHtml(b.title)}</td>
          <td>${escapeHtml(b.author)}</td>
          <td>${escapeHtml(b.edition) || '&mdash;'}</td>
          <td>${escapeHtml(category) || '&mdash;'}</td>
          <td>${escapeHtml(b.accession_number) || '&mdash;'}</td>
          <td>${b.total_copies}</td>
          <td><span class="copies-pill ${available === 0 ? 'zero' : ''}">${available}</span></td>
          <td>
            <button class="action-btn qr" title="QR Code" onclick="openQrModal(${b.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM14 20h3M20 14v3M20 20h.01"/></svg>
            </button>
            <button class="action-btn edit" title="Edit" onclick="openEditModal(${b.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            </button>
            <button class="action-btn delete" title="Delete" onclick="deleteBook(${b.id})">
              <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>
            </button>
          </td>
        </tr>
      `;
    }).join('');
  }

  function applyFilter() {
    const filtered = getFilteredBooks();
    document.getElementById('countBadge').textContent =
      `Showing ${filtered.length} of ${allBooks.length} book${allBooks.length === 1 ? '' : 's'}`;
    renderRows(filtered);
  }

  function getFilteredBooks() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    return !q ? allBooks : allBooks.filter(b =>
      [
        b.title,
        b.author,
        b.edition,
        b.year_of_publication,
        b.isbn,
        b.ddc,
        b.cutter_number,
        b.genre,
        b.category,
        b.call_number,
        b.accession_number,
        b.publisher,
        b.place_of_publication
      ].filter(Boolean).join(' ').toLowerCase().includes(q)
    );
  }

  function exportCsv() {
    const rows = getFilteredBooks();
    const header = ['ISBN', 'Classification No.', 'Cutter No.', 'Call No.', 'Title', 'Author', 'Edition', 'Category', 'Accession Number', 'Total Copies', 'Borrowed Copies', 'Available Copies'];
    const csvCell = value => {
      let text = String(value == null ? '' : value);
      if (/^[\s]*[=+\-@]/.test(text)) text = `'${text}`;
      return `"${text.replace(/"/g, '""')}"`;
    };
    const lines = [header.map(csvCell).join(',')];

    rows.forEach(book => {
      const borrowed = Number(book.borrowed_copies) || 0;
      const total = Number(book.total_copies) || 0;
      lines.push([
        book.isbn,
        book.ddc || book.call_number || '',
        book.cutter_number,
        callNumberFor(book),
        book.title,
        book.author,
        book.edition,
        book.category || book.genre || '',
        book.accession_number,
        total,
        borrowed,
        total - borrowed
      ].map(csvCell).join(','));
    });

    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `books-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function loadBooks() {
    return fetch('backend/books-data.php', { credentials: 'same-origin' })
      .then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      })
      .then(data => {
        allBooks = data.books || [];
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('booksTable').style.display = 'table';
        document.getElementById('countBadge').style.display = 'block';
        document.getElementById('exportBtn').disabled = false;
        applyFilter();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') {
          window.location.href = 'admin-login.html';
          return;
        }
        document.getElementById('loadingMsg').textContent = 'Something went wrong loading books.';
      });
  }

  document.getElementById('searchInput').addEventListener('input', applyFilter);
  document.getElementById('exportBtn').addEventListener('click', exportCsv);

  // ---- Modal ----
  const overlay = document.getElementById('modalOverlay');
  const form = document.getElementById('bookForm');
  const photoInput = document.getElementById('photoInput');
  const photoPreview = document.getElementById('photoPreview');

  function resetPhotoPreview() {
    photoInput.value = '';
    photoPreview.hidden = true;
    photoPreview.removeAttribute('src');
  }

  photoInput.addEventListener('change', () => {
    const file = photoInput.files && photoInput.files[0];
    if (!file) {
      photoPreview.hidden = true;
      photoPreview.removeAttribute('src');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      photoPreview.src = reader.result;
      photoPreview.hidden = false;
    };
    reader.readAsDataURL(file);
  });

  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Book';
    document.getElementById('bookId').value = '';
    form.reset();
    resetPhotoPreview();
    document.getElementById('totalInput').value = 1;
    document.getElementById('modalError').textContent = '';
    overlay.classList.add('show');
  }

  window.openEditModal = function(id) {
    const book = allBooks.find(b => b.id === id);
    if (!book) return;
    document.getElementById('modalTitle').textContent = 'Edit Book';
    document.getElementById('bookId').value = book.id;
    document.getElementById('titleInput').value = book.title;
    document.getElementById('authorInput').value = book.author;
    document.getElementById('editionInput').value = book.edition || '';
    document.getElementById('placeInput').value = book.place_of_publication || '';
    document.getElementById('publisherInput').value = book.publisher || '';
    document.getElementById('yearInput').value = book.year_of_publication || '';
    document.getElementById('isbnInput').value = book.isbn || '';
    document.getElementById('categoryInput').value = book.category || book.genre || '';
    document.getElementById('classificationNumberInput').value = book.ddc || book.call_number || '';
    document.getElementById('cutterNumberInput').value = book.cutter_number || '';
    document.getElementById('accessionNumberInput').value = book.accession_number || '';
    photoInput.value = '';
    if (book.image_path) {
      photoPreview.src = book.image_path;
      photoPreview.hidden = false;
    } else {
      photoPreview.hidden = true;
      photoPreview.removeAttribute('src');
    }
    document.getElementById('totalInput').value = book.total_copies;
    document.getElementById('modalError').textContent = '';
    overlay.classList.add('show');
  };

  window.deleteBook = function(id) {
    const book = allBooks.find(b => b.id === id);
    if (!book) return;
    if (!confirm(`Delete "${book.title}"? This can't be undone.`)) return;

    fetch('backend/book-delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ id })
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          showToast('Book deleted.');
          loadBooks();
        } else {
          showToast(data.error || 'Could not delete that book.');
        }
      })
      .catch(() => showToast('Could not reach the server.'));
  };

  // ---- QR code modal ----
  // Builds a link to this book's entry in the patron catalog (browse-books.html
  // supports ?book=<id> by scrolling to and highlighting that card — see
  // browse-books.html). The QR image itself is generated by a free public API
  // (api.qrserver.com) so no QR library needs to be installed on the server.
  const qrOverlay = document.getElementById('qrOverlay');

  window.openQrModal = function(id) {
    const book = allBooks.find(b => b.id === id);
    if (!book) return;

    const target = new URL('browse-books.html', window.location.href);
    target.searchParams.set('book', book.id);
    const targetUrl = target.href;
    const qrImageUrl = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(targetUrl)}`;
    const fileName = `qr-${book.title.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`;

    document.getElementById('qrModalTitle').textContent = `QR Code — ${book.title}`;
    document.getElementById('qrImage').src = qrImageUrl;
    document.getElementById('qrUrlText').textContent = targetUrl;

    const downloadBtn = document.getElementById('qrDownloadBtn');
    downloadBtn.href = qrImageUrl;
    downloadBtn.setAttribute('download', fileName);

    // The download attribute is ignored on cross-origin links by most browsers,
    // so fetch the image ourselves and point the button at a same-origin blob
    // URL instead — this makes the actual file download work reliably.
    fetch(qrImageUrl)
      .then(res => res.blob())
      .then(blob => {
        downloadBtn.href = URL.createObjectURL(blob);
      })
      .catch(() => { /* leave the direct URL as a fallback — it'll still open the image */ });

    qrOverlay.classList.add('show');
  };

  document.getElementById('qrCloseBtn').addEventListener('click', () => qrOverlay.classList.remove('show'));
  qrOverlay.addEventListener('click', (e) => { if (e.target === qrOverlay) qrOverlay.classList.remove('show'); });

  document.getElementById('addBookBtn').addEventListener('click', openAddModal);
  document.getElementById('cancelBtn').addEventListener('click', () => overlay.classList.remove('show'));
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.classList.remove('show'); });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('modalError');
    errorEl.textContent = '';

    const category = document.getElementById('categoryInput').value.trim();
    const classificationNumber = document.getElementById('classificationNumberInput').value.trim();
    const cutterNumber = document.getElementById('cutterNumberInput').value.trim();
    const bookId = document.getElementById('bookId').value || '';
    const formData = new FormData();
    if (bookId) formData.append('id', bookId);
    formData.append('title', document.getElementById('titleInput').value.trim());
    formData.append('author', document.getElementById('authorInput').value.trim());
    formData.append('edition', document.getElementById('editionInput').value.trim());
    formData.append('place_of_publication', document.getElementById('placeInput').value.trim());
    formData.append('publisher', document.getElementById('publisherInput').value.trim());
    formData.append('year_of_publication', document.getElementById('yearInput').value.trim());
    formData.append('isbn', document.getElementById('isbnInput').value.trim());
    formData.append('category', category);
    formData.append('classification_number', classificationNumber);
    formData.append('cutter_number', cutterNumber);
    formData.append('accession_number', document.getElementById('accessionNumberInput').value.trim());
    formData.append('genre', category);
    formData.append('total_copies', document.getElementById('totalInput').value);
    if (photoInput.files && photoInput.files[0]) {
      formData.append('photo', photoInput.files[0]);
    }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    fetch('backend/book-save.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not save that book.');
        return data;
      })
      .then(data => {
        overlay.classList.remove('show');
        showToast(bookId ? 'Book updated.' : 'Book added.');
        loadBooks();
      })
      .catch(err => { errorEl.textContent = err.message; })
      .finally(() => {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
      });
  });

loadBooks();
