  fetch('backend/admin-session-status.php', { credentials: 'same-origin', cache: 'no-store' })
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
  function showToast(message) {
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 4000);
  }

  let allBooks = [];
  let csrfToken = '';
  let currentPage = 1;
  let editingBorrowed = 0;
  let previousFocus = null;
  let newCategoryValue = '__new_category__';
  let uncategorizedValue = '__uncategorized__';
  const defaultCategories = ['General', 'Reference', 'Textbooks', 'Filipiniana', 'Fiction', 'Nonfiction', 'Science', 'Mathematics', 'History', 'Literature', 'Technology'];
  const searchInput = document.getElementById('searchInput');
  const categoryFilter = document.getElementById('categoryFilter');
  const availabilityFilter = document.getElementById('availabilityFilter');
  const categoryInput = document.getElementById('categoryInput');
  const newCategoryInput = document.getElementById('newCategoryInput');
  const pageSize = document.getElementById('pageSize');
  const overlay = document.getElementById('modalOverlay');
  const form = document.getElementById('bookForm');
  const photoInput = document.getElementById('photoInput');
  const photoPreview = document.getElementById('photoPreview');
  const saveBtn = document.getElementById('saveBtn');

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  function categoryFor(book) {
    return book.category || book.genre || '';
  }

  function callNumberFor(book) {
    if (String(book.call_number || '').trim()) return book.call_number;
    if (!String(book.cutter_number || '').trim()) return '';
    // Only a display/edit fallback for old records; never infer a DDC from a title.
    const parts = [book.ddc, book.cutter_number].filter(Boolean);
    const year = String(book.year_of_publication || '').trim();
    if (year && !parts.some(part => String(part).split(/\s+/).includes(year))) parts.push(year);
    return parts.join(' ');
  }

  function populateCategories(selectedCategory = '') {
    const currentFilter = categoryFilter.value;
    const wasUncategorized = currentFilter === uncategorizedValue;
    const stored = [...new Set(allBooks.map(categoryFor).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    while (stored.includes(uncategorizedValue)) uncategorizedValue += '_';
    categoryFilter.replaceChildren(new Option('All categories', ''), new Option('Uncategorized', uncategorizedValue));
    stored.forEach(category => categoryFilter.add(new Option(category, category)));
    categoryFilter.value = wasUncategorized ? uncategorizedValue
      : [...categoryFilter.options].some(option => option.value === currentFilter) ? currentFilter : '';
    const choices = [...new Set([...defaultCategories, ...stored, selectedCategory].filter(Boolean))].sort((a, b) => a.localeCompare(b));
    categoryInput.replaceChildren(new Option('Uncategorized', ''));
    choices.forEach(category => categoryInput.add(new Option(category, category)));
    while (choices.includes(newCategoryValue)) newCategoryValue += '_';
    categoryInput.add(new Option('Add a new category…', newCategoryValue));
    categoryInput.value = selectedCategory;
    updateCategoryInput();
  }

  function updateCategoryInput() {
    const isNew = categoryInput.value === newCategoryValue;
    document.getElementById('newCategoryField').hidden = !isNew;
    newCategoryInput.required = isNew;
  }

  function getFilteredBooks() {
    const query = searchInput.value.trim().toLowerCase();
    return allBooks.filter(book => {
      const category = categoryFor(book);
      if (categoryFilter.value === uncategorizedValue && category !== '') return false;
      if (categoryFilter.value && categoryFilter.value !== uncategorizedValue && category !== categoryFilter.value) return false;
      const available = Number(book.total_copies) - Number(book.borrowed_copies);
      if (availabilityFilter.value === 'available' && available <= 0) return false;
      if (availabilityFilter.value === 'unavailable' && available > 0) return false;
      return !query || [book.title, book.author, book.edition, book.year_of_publication, book.isbn,
        book.ddc, callNumberFor(book), book.genre, category, book.accession_number, book.publisher,
        book.place_of_publication].filter(Boolean).join(' ').toLowerCase().includes(query);
    });
  }

  function renderRows(books) {
    document.getElementById('booksBody').innerHTML = books.length ? books.map(book => {
      const available = Number(book.total_copies) - Number(book.borrowed_copies);
      return `<tr>
        <td class="book-summary"><strong>${escapeHtml(book.title)}</strong><span>${escapeHtml(book.author)}</span>${book.edition ? `<small>${escapeHtml(book.edition)}</small>` : ''}</td>
        <td class="book-identifiers"><span>ISBN: ${escapeHtml(book.isbn) || '—'}</span><span>Accession: ${escapeHtml(book.accession_number) || '—'}</span></td>
        <td>${escapeHtml(book.ddc) || '—'}</td>
        <td class="call-number-cell">${escapeHtml(callNumberFor(book)) || '—'}</td>
        <td>${escapeHtml(categoryFor(book)) || 'Uncategorized'}</td>
        <td class="inventory-cell"><span>Total: ${Number(book.total_copies)}</span><span>Borrowed: ${Number(book.borrowed_copies)}</span><span class="copies-pill ${available <= 0 ? 'zero' : ''}">Available: ${available}</span><small>Held for pickup: ${Number(book.held_copies) || 0}</small></td>
        <td class="actions-column"><div class="book-actions">
          <button type="button" class="action-btn qr" title="Show catalog QR" aria-label="Show QR for ${escapeHtml(book.title)}" onclick="openQrModal(${book.id})">QR</button>
          <button type="button" class="action-btn edit" aria-label="Edit ${escapeHtml(book.title)}" onclick="openEditModal(${book.id})">Edit</button>
          <button type="button" class="action-btn delete" aria-label="Delete ${escapeHtml(book.title)}" onclick="deleteBook(${book.id})">Delete</button>
        </div></td>
      </tr>`;
    }).join('') : `<tr><td colspan="7" class="empty-msg">${allBooks.length ? 'No books match these filters. Clear filters to see the full catalog.' : 'No books in the catalog yet. Use Add Book to create the first title.'}</td></tr>`;
  }

  function applyFilter(resetPage = true) {
    if (resetPage) currentPage = 1;
    const filtered = getFilteredBooks();
    const size = Number(pageSize.value);
    const pages = Math.max(1, Math.ceil(filtered.length / size));
    currentPage = Math.min(currentPage, pages);
    const start = (currentPage - 1) * size;
    renderRows(filtered.slice(start, start + size));
    document.getElementById('countBadge').textContent = filtered.length
      ? `Showing ${start + 1}–${Math.min(start + size, filtered.length)} of ${filtered.length} matching titles (${allBooks.length} total)`
      : `0 matching titles (${allBooks.length} total)`;
    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${pages}`;
    document.getElementById('prevPageBtn').disabled = currentPage <= 1;
    document.getElementById('nextPageBtn').disabled = currentPage >= pages;
    document.getElementById('pagination').hidden = filtered.length === 0;
    document.getElementById('exportBtn').disabled = filtered.length === 0;
  }

  function csvCell(value) {
    let text = String(value == null ? '' : value);
    if (/^[\s\u0000-\u001f]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = `'${text}`;
    return `"${text.replace(/"/g, '""')}"`;
  }

  function exportCsv() {
    const header = ['ISBN', 'Classification Number (DDC)', 'Call Number', 'Title', 'Author', 'Edition', 'Category', 'Accession Number', 'Place of Publication', 'Publisher', 'Year of Publication', 'Total Copies', 'Borrowed Copies', 'Available Copies'];
    const lines = [header.map(csvCell).join(',')];
    // Export every matching title, including matches on other pages.
    getFilteredBooks().forEach(book => lines.push([
      book.isbn, book.ddc, callNumberFor(book), book.title, book.author, book.edition,
      categoryFor(book), book.accession_number, book.place_of_publication, book.publisher,
      book.year_of_publication, book.total_copies, book.borrowed_copies,
      Number(book.total_copies) - Number(book.borrowed_copies)
    ].map(csvCell).join(',')));
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `books-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function readResponse(response) {
    let data;
    try { data = await response.json(); }
    catch (error) { throw new Error('The server could not complete this request. Please try again.'); }
    if (response.status === 401) {
      window.location.href = 'admin-login.html';
      throw new Error('Please sign in again.');
    }
    if (!response.ok) throw new Error(data.error || 'Could not complete this request.');
    return data;
  }

  async function loadBooks() {
    const loading = document.getElementById('loadingMsg');
    allBooks = [];
    csrfToken = '';
    document.getElementById('countBadge').style.display = 'none';
    loading.textContent = 'Loading books…';
    loading.hidden = false;
    document.getElementById('retryBtn').hidden = true;
    document.getElementById('booksTable').style.display = 'none';
    document.getElementById('pagination').hidden = true;
    document.getElementById('exportBtn').disabled = true;
    document.getElementById('addBookBtn').disabled = true;
    try {
      const data = await fetch('backend/books-data.php', { credentials: 'same-origin', cache: 'no-store' }).then(readResponse);
      if (!Array.isArray(data.books) || !data.csrfToken) throw new Error('The book catalog response is incomplete. Please reload.');
      allBooks = data.books.map(book => ({ ...book, id: Number(book.id) }));
      csrfToken = data.csrfToken;
      populateCategories();
      loading.hidden = true;
      document.getElementById('booksTable').style.display = 'table';
      document.getElementById('countBadge').style.display = 'block';
      document.getElementById('addBookBtn').disabled = false;
      applyFilter(false);
    } catch (error) {
      loading.textContent = error.message;
      document.getElementById('retryBtn').hidden = false;
      document.getElementById('countBadge').style.display = 'none';
    }
  }

  function updateInventoryHelp() {
    const value = document.getElementById('totalInput').value;
    const total = Number(value);
    document.getElementById('inventoryHelp').textContent = Number.isInteger(total) && total >= editingBorrowed && value !== ''
      ? `Borrowed: ${editingBorrowed} · Available: ${total - editingBorrowed}`
      : `Borrowed: ${editingBorrowed} · Total must be a whole number of at least ${editingBorrowed}.`;
  }

  function showBookModal() {
    previousFocus = document.activeElement;
    document.getElementById('modalError').textContent = '';
    updateInventoryHelp();
    overlay.classList.add('show');
    document.getElementById('titleInput').focus();
  }

  function closeBookModal() {
    if (saveBtn.disabled) return;
    overlay.classList.remove('show');
    if (previousFocus) previousFocus.focus();
  }

  function openAddModal() {
    form.reset();
    document.getElementById('bookId').value = '';
    document.getElementById('modalTitle').textContent = 'Add Book';
    editingBorrowed = 0;
    document.getElementById('totalInput').min = 0;
    document.getElementById('legacyMetadataNote').hidden = true;
    photoPreview.hidden = true;
    photoPreview.removeAttribute('src');
    populateCategories();
    showBookModal();
  }

  window.openEditModal = function(id) {
    const book = allBooks.find(book => book.id === id);
    if (!book) return;
    form.reset();
    document.getElementById('modalTitle').textContent = 'Edit Book';
    const fields = { bookId: 'id', titleInput: 'title', authorInput: 'author', editionInput: 'edition',
      placeInput: 'place_of_publication', publisherInput: 'publisher', yearInput: 'year_of_publication',
      isbnInput: 'isbn', classificationNumberInput: 'ddc', accessionNumberInput: 'accession_number', totalInput: 'total_copies' };
    Object.entries(fields).forEach(([element, field]) => { document.getElementById(element).value = book[field] == null ? '' : book[field]; });
    populateCategories(categoryFor(book));
    document.getElementById('callNumberInput').value = callNumberFor(book);
    document.getElementById('legacyMetadataNote').hidden = !book.cutter_number
      && (!book.ddc || /^[0-9]{3}(\.[0-9]+)?$/.test(book.ddc))
      && (!book.year_of_publication || /^[0-9]{4}$/.test(book.year_of_publication));
    editingBorrowed = Number(book.borrowed_copies);
    document.getElementById('totalInput').min = editingBorrowed;
    photoPreview.hidden = !book.image_path;
    if (book.image_path) photoPreview.src = book.image_path;
    else photoPreview.removeAttribute('src');
    showBookModal();
  };

  window.deleteBook = async function(id) {
    const book = allBooks.find(book => book.id === id);
    if (!book || !confirm(`Delete "${book.title}"? Only titles without borrowed copies or transaction history can be deleted. This cannot be undone.`)) return;
    try {
      await fetch('backend/book-delete.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ id }) }).then(readResponse);
      showToast('Book deleted.');
      await loadBooks();
    } catch (error) { showToast(error.message); }
  };

  function restoreStoredPhoto() {
    const id = Number(document.getElementById('bookId').value);
    const book = allBooks.find(book => book.id === id);
    photoPreview.hidden = !book || !book.image_path;
    if (book && book.image_path) photoPreview.src = book.image_path;
    else photoPreview.removeAttribute('src');
  }

  photoInput.addEventListener('change', () => {
    const file = photoInput.files && photoInput.files[0];
    if (!file) { restoreStoredPhoto(); return; }
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      document.getElementById('modalError').textContent = 'Choose a JPG, PNG, or WebP picture of 5 MB or smaller.';
      photoInput.value = '';
      restoreStoredPhoto();
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      if (photoInput.files[0] !== file) return;
      photoPreview.src = reader.result;
      photoPreview.hidden = false;
    };
    reader.readAsDataURL(file);
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const error = document.getElementById('modalError');
    error.textContent = '';
    const category = categoryInput.value === newCategoryValue ? newCategoryInput.value.trim() : categoryInput.value;
    if (categoryInput.value === newCategoryValue && !category) { error.textContent = 'Enter the new category name.'; return; }
    const bookId = document.getElementById('bookId').value;
    const formData = new FormData();
    const fields = { title: 'titleInput', author: 'authorInput', edition: 'editionInput',
      place_of_publication: 'placeInput', publisher: 'publisherInput', year_of_publication: 'yearInput',
      isbn: 'isbnInput', classification_number: 'classificationNumberInput', call_number: 'callNumberInput',
      accession_number: 'accessionNumberInput', total_copies: 'totalInput' };
    Object.entries(fields).forEach(([field, element]) => formData.append(field, document.getElementById(element).value.trim()));
    if (bookId) formData.append('id', bookId);
    const existingBook = allBooks.find(book => book.id === Number(bookId));
    if (!existingBook || category !== categoryFor(existingBook)) formData.append('category', category);
    if (photoInput.files && photoInput.files[0]) formData.append('photo', photoInput.files[0]);
    saveBtn.disabled = true;
    document.getElementById('cancelBtn').disabled = true;
    saveBtn.textContent = 'Saving…';
    try {
      await fetch('backend/book-save.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken }, body: formData }).then(readResponse);
      overlay.classList.remove('show');
      showToast(bookId ? 'Book updated.' : 'Book added.');
      await loadBooks();
      document.getElementById('addBookBtn').focus();
    } catch (failure) { error.textContent = failure.message; }
    finally {
      saveBtn.disabled = false;
      document.getElementById('cancelBtn').disabled = false;
      saveBtn.textContent = 'Save';
    }
  });

  const qrOverlay = document.getElementById('qrOverlay');
  let qrBlobUrl = null;
  let currentQrId = null;
  function closeQrModal() {
    qrOverlay.classList.remove('show');
    currentQrId = null;
    if (qrBlobUrl) URL.revokeObjectURL(qrBlobUrl);
    qrBlobUrl = null;
    document.getElementById('qrImage').removeAttribute('src');
    if (previousFocus) previousFocus.focus();
  }

  window.openQrModal = function(id) {
    const book = allBooks.find(book => book.id === id);
    if (!book) return;
    if (qrBlobUrl) URL.revokeObjectURL(qrBlobUrl);
    qrBlobUrl = null;
    previousFocus = document.activeElement;
    currentQrId = id;
    const target = new URL('browse-books.html', window.location.href);
    target.searchParams.set('book', id);
    const imageUrl = `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(target.href)}`;
    document.getElementById('qrModalTitle').textContent = `QR Code — ${book.title}`;
    document.getElementById('qrUrlText').textContent = target.href;
    document.getElementById('qrLocalNote').hidden = !['localhost', '127.0.0.1', '[::1]'].includes(target.hostname);
    document.getElementById('qrError').textContent = '';
    const image = document.getElementById('qrImage');
    image.onerror = () => { document.getElementById('qrError').textContent = 'QR image unavailable. Check your internet connection; the catalog link above is still valid.'; };
    image.src = imageUrl;
    const download = document.getElementById('qrDownloadBtn');
    download.href = imageUrl;
    download.download = `qr-${book.title.replace(/[^a-z0-9]+/gi, '-').toLowerCase() || id}.png`;
    fetch(imageUrl).then(response => {
      if (!response.ok) throw new Error('QR unavailable');
      return response.blob();
    }).then(blob => {
      if (currentQrId !== id || !qrOverlay.classList.contains('show')) return;
      qrBlobUrl = URL.createObjectURL(blob);
      download.href = qrBlobUrl;
    }).catch(() => {});
    qrOverlay.classList.add('show');
    document.getElementById('qrCloseBtn').focus();
  };

  searchInput.addEventListener('input', () => applyFilter());
  categoryFilter.addEventListener('change', () => applyFilter());
  availabilityFilter.addEventListener('change', () => applyFilter());
  pageSize.addEventListener('change', () => applyFilter());
  categoryInput.addEventListener('change', updateCategoryInput);
  document.getElementById('totalInput').addEventListener('input', updateInventoryHelp);
  document.getElementById('clearFiltersBtn').addEventListener('click', () => {
    searchInput.value = ''; categoryFilter.value = ''; availabilityFilter.value = ''; applyFilter();
  });
  document.getElementById('prevPageBtn').addEventListener('click', () => { currentPage--; applyFilter(false); });
  document.getElementById('nextPageBtn').addEventListener('click', () => { currentPage++; applyFilter(false); });
  document.getElementById('exportBtn').addEventListener('click', exportCsv);
  document.getElementById('retryBtn').addEventListener('click', loadBooks);
  document.getElementById('addBookBtn').addEventListener('click', openAddModal);
  document.getElementById('cancelBtn').addEventListener('click', closeBookModal);
  overlay.addEventListener('click', event => { if (event.target === overlay) closeBookModal(); });
  document.getElementById('qrCloseBtn').addEventListener('click', closeQrModal);
  qrOverlay.addEventListener('click', event => { if (event.target === qrOverlay) closeQrModal(); });
  document.addEventListener('keydown', event => {
    const activeOverlay = overlay.classList.contains('show') ? overlay : qrOverlay.classList.contains('show') ? qrOverlay : null;
    if (!activeOverlay) return;
    if (event.key === 'Escape') {
      if (activeOverlay === overlay) closeBookModal();
      else closeQrModal();
    }
    if (event.key === 'Tab') {
      const controls = [...activeOverlay.querySelectorAll('input, select, button, a[href]')].filter(element => !element.disabled && element.offsetParent !== null);
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  loadBooks();
