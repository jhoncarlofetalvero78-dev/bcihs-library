
  let allBooks = [];
  let myRequestByBook = {};  // book_id -> status ('pending' | 'approved' | 'rejected' | 'returned')
  let myReservationsByBook = {};  // book_id -> active reservation
  const urlParams = new URLSearchParams(window.location.search);
  const hasScannedBook = urlParams.has('book');
  const scannedBookValue = urlParams.get('book');
  const scannedBookId = /^\d+$/.test(scannedBookValue || '')
    ? Number(scannedBookValue)
    : null;
  let pickupBookId = null;
  let pickupReservation = null;
  let pickupMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let pickupDate = '';
  let pickupTime = '';

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
  }

  function showBanner(message, kind) {
    const el = document.getElementById('statusBanner');
    el.textContent = message;
    el.className = `status-banner show ${kind}`;
    setTimeout(() => el.classList.remove('show'), 3200);
  }

  function buttonFor(book) {
    const available = book.total_copies - book.borrowed_copies;
    const myStatus = myRequestByBook[book.id];
    const myReservation = myReservationsByBook[book.id];

    if (myStatus === 'pending') {
      return `<button class="borrow-btn pending" disabled>Request Pending</button>`;
    }
    if (myStatus === 'approved') {
      return `<button class="borrow-btn approved" disabled>Already Borrowed</button>`;
    }
    if (myReservation && myReservation.status === 'waiting') {
      const actionLabel = myReservation.pickup_at ? 'Change Preferred Pickup' : 'Choose Pickup Time';
      return `<button class="borrow-btn available" onclick="openPickupDialog(${book.id}, 'schedule')">${actionLabel}</button><p class="pickup-info">Schedule can be changed while waiting. It is final once ready.</p>${pickupInfo(myReservation)}`;
    }
    if (myReservation && myReservation.status === 'ready') {
      return `<button class="borrow-btn approved" disabled>Ready to Pick Up</button><p class="pickup-info">Pickup time is confirmed and cannot be changed.</p>${pickupInfo(myReservation)}`;
    }
    if (available <= 0) {
      return `<button class="borrow-btn available" onclick="reserveBook(${book.id})">Reserve</button>`;
    }
    return `<button class="borrow-btn available" onclick="requestBook(${book.id})">Request to Borrow</button>`;
  }

  function pickupInfo(reservation) {
    if (!reservation.pickup_at) return '<p class="pickup-info">No pickup time selected.</p>';
    const date = new Date(reservation.pickup_at.replace(' ', 'T'));
    const formatted = isNaN(date)
      ? reservation.pickup_at
      : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    const label = reservation.status === 'waiting' ? 'Preferred pickup' : 'Confirmed pickup';
    return `<p class="pickup-info">${label}: ${escapeHtml(formatted)}</p>`;
  }

  function callNumberFor(book) {
    const classification = String(book.ddc || '').trim();
    const cutter = String(book.cutter_number || '').trim();
    const year = String(book.year_of_publication || '').trim();
    const parts = [classification, cutter].filter(Boolean);
    if (year && !parts.some(part => part.split(/\s+/).includes(year))) parts.push(year);
    return parts.join(' ') || book.call_number || '';
  }

  function render(books) {
    const grid = document.getElementById('bookGrid');
    if (books.length === 0) {
      grid.innerHTML = hasScannedBook
        ? '<p class="empty-msg">No book was found for this QR code.</p>'
        : '<p class="empty-msg">No books match your search.</p>';
      return;
    }
    grid.innerHTML = books.map(b => {
      const available = b.total_copies - b.borrowed_copies;
      const availClass = available <= 0 ? 'zero' : (available <= 2 ? 'low' : 'ok');
      const isScannedBook = Number(b.id) === scannedBookId;
      return `
        <div class="book-card${isScannedBook ? ' qr-selected' : ''}"${isScannedBook ? ` id="scannedBook" tabindex="-1"` : ''}>
          <div class="book-cover">
            ${b.image_path
              ? `<img src="${escapeHtml(b.image_path)}" alt="Cover of ${escapeHtml(b.title)}">`
              : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 4.5c2.2-1 4.6-1 7 0v13.5c-2.4-1-4.8-1-7 0V4.5z"/><path d="M22 4.5c-2.2-1-4.6-1-7 0v13.5c2.4-1 4.8-1 7 0V4.5z"/></svg>'}
          </div>
          <h3>${escapeHtml(b.title)}</h3>
          <div class="author">${escapeHtml(b.author)}</div>
          ${callNumberFor(b) ? `<div class="call-number">Call No.: ${escapeHtml(callNumberFor(b))}</div>` : ''}
          ${b.isbn ? `<div class="isbn">${escapeHtml(b.isbn)}</div>` : ''}
          <div class="genre">${escapeHtml(b.genre) || 'Uncategorized'}</div>
          ${isScannedBook ? `
            <div class="book-details">
              ${b.edition ? `<p><strong>Edition:</strong> ${escapeHtml(b.edition)}</p>` : ''}
              ${b.publisher ? `<p><strong>Publisher:</strong> ${escapeHtml(b.publisher)}</p>` : ''}
              ${b.place_of_publication ? `<p><strong>Place:</strong> ${escapeHtml(b.place_of_publication)}</p>` : ''}
              ${b.year_of_publication ? `<p><strong>Year:</strong> ${escapeHtml(b.year_of_publication)}</p>` : ''}
              ${b.accession_number ? `<p><strong>Accession No.:</strong> ${escapeHtml(b.accession_number)}</p>` : ''}
            </div>
          ` : ''}
          <div class="avail-row">
            <span class="avail-count ${availClass}">${available} of ${b.total_copies} available</span>
          </div>
          ${buttonFor(b)}
        </div>
      `;
    }).join('');

    const scannedCard = document.getElementById('scannedBook');
    if (scannedCard) {
      requestAnimationFrame(() => scannedCard.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }
  }

  function applyFilter() {
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    const books = hasScannedBook
      ? allBooks.filter(book => Number(book.id) === scannedBookId)
      : allBooks;
    const filtered = !q ? books : books.filter(b =>
      [b.title, b.author, b.isbn, b.genre, b.ddc, b.cutter_number, b.year_of_publication, b.call_number]
        .filter(Boolean).join(' ').toLowerCase().includes(q)
    );
    render(filtered);
  }

  window.requestBook = function(bookId) {
    fetch('backend/borrow-request-process.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ book_id: bookId })
    })
      .then(async res => {
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Could not submit that request.');
        return data;
      })
      .then(() => {
        showBanner('Request submitted! Library librarian will review it soon.', 'success');
        loadEverything();
      })
      .catch(err => showBanner(err.message, 'error'));
  };

  window.reserveBook = function(bookId) {
    openPickupDialog(bookId, 'reserve');
  };

  function pickupSlots() {
    const slots = [];
    for (let minutes = 7 * 60; minutes <= 16 * 60; minutes += 30) {
      slots.push(`${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`);
    }
    return slots;
  }

  function pickupSlotIsFuture(dateKey, time) {
    const selected = new Date(`${dateKey}T${time}:00`);
    return selected.getTime() > Date.now();
  }

  function renderPickupTimes() {
    const select = document.getElementById('pickupTime');
    select.disabled = !pickupDate;
    select.innerHTML = `<option value="">${pickupDate ? 'Select a time' : 'Select a date first'}</option>`;
    if (!pickupDate) {
      pickupTime = '';
      return;
    }
    pickupSlots().forEach(time => {
      if (!pickupSlotIsFuture(pickupDate, time)) return;
      const option = document.createElement('option');
      option.value = time;
      option.textContent = new Date(`2000-01-01T${time}:00`).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit'
      });
      option.selected = time === pickupTime;
      select.appendChild(option);
    });
    if (!Array.from(select.options).some(option => option.value === pickupTime)) pickupTime = '';
    select.value = pickupTime;
  }

  function renderPickupCalendar() {
    const grid = document.getElementById('pickupCalendar');
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const year = pickupMonth.getFullYear();
    const month = pickupMonth.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const now = new Date();
    const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    document.getElementById('pickupMonth').textContent = pickupMonth.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'long'
    });
    grid.innerHTML = weekdays.map(day => `<span class="pickup-weekday" aria-hidden="true">${day}</span>`).join('');

    for (let blank = 0; blank < firstWeekday; blank += 1) {
      grid.insertAdjacentHTML('beforeend', '<button type="button" class="pickup-day" disabled aria-hidden="true"></button>');
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
      const date = new Date(year, month, day);
      const key = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const isWeekday = date.getDay() >= 1 && date.getDay() <= 5;
      const hasFutureSlot = pickupSlots().some(time => pickupSlotIsFuture(key, time));
      const disabled = key < todayKey || !isWeekday || !hasFutureSlot;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `pickup-day${key === pickupDate ? ' selected' : ''}`;
      button.textContent = day;
      button.dataset.date = key;
      button.disabled = disabled;
      button.setAttribute('aria-pressed', String(key === pickupDate));
      button.setAttribute('aria-label', `${date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      })}${disabled ? ', unavailable' : ', available'}`);
      grid.appendChild(button);
    }

    grid.querySelectorAll('[data-date]:not(:disabled)').forEach(button => {
      button.addEventListener('click', () => {
        pickupDate = button.dataset.date;
        pickupTime = '';
        renderPickupCalendar();
        renderPickupTimes();
      });
    });
  }

  function openPickupDialog(bookId, mode) {
    pickupBookId = bookId;
    pickupReservation = mode === 'schedule' ? myReservationsByBook[bookId] : null;
    pickupDate = pickupReservation && pickupReservation.pickup_at
      ? pickupReservation.pickup_at.slice(0, 10)
      : '';
    pickupTime = pickupReservation && pickupReservation.pickup_at
      ? pickupReservation.pickup_at.slice(11, 16)
      : '';
    pickupMonth = pickupDate
      ? new Date(`${pickupDate}T00:00:00`)
      : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    document.getElementById('pickupDialogTitle').textContent =
      mode === 'reserve' ? 'Choose your preferred pickup time' : 'Change your preferred pickup time';
    document.getElementById('pickupSave').textContent = mode === 'reserve' ? 'Reserve book' : 'Save preferred time';
    document.getElementById('pickupError').textContent = '';
    renderPickupCalendar();
    renderPickupTimes();
    document.getElementById('pickupDialog').showModal();
  }

  document.getElementById('pickupPreviousMonth').addEventListener('click', () => {
    pickupMonth = new Date(pickupMonth.getFullYear(), pickupMonth.getMonth() - 1, 1);
    renderPickupCalendar();
  });
  document.getElementById('pickupNextMonth').addEventListener('click', () => {
    pickupMonth = new Date(pickupMonth.getFullYear(), pickupMonth.getMonth() + 1, 1);
    renderPickupCalendar();
  });
  document.getElementById('pickupTime').addEventListener('change', event => {
    pickupTime = event.target.value;
  });
  document.getElementById('pickupCancel').addEventListener('click', () => {
    document.getElementById('pickupDialog').close();
  });
  document.getElementById('pickupForm').addEventListener('submit', async event => {
    event.preventDefault();
    const error = document.getElementById('pickupError');
    if (!pickupDate || !pickupTime) {
      error.textContent = 'Choose an available date and pickup time.';
      return;
    }

    const mode = pickupReservation ? 'schedule' : 'reserve';
    const endpoint = mode === 'reserve' ? 'backend/reservation-process.php' : 'backend/reservation-action.php';
    const payload = mode === 'reserve'
      ? { book_id: pickupBookId, pickup_at: `${pickupDate}T${pickupTime}` }
      : { id: pickupReservation.id, action: 'schedule', pickup_at: `${pickupDate}T${pickupTime}` };
    const saveButton = document.getElementById('pickupSave');
    saveButton.disabled = true;
    error.textContent = '';

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not save the pickup time.');
      document.getElementById('pickupDialog').close();
      showBanner(
        mode === 'reserve'
          ? 'Book reserved! Your preferred pickup time has been saved.'
          : 'Preferred pickup time updated. It will be final once the reservation is ready.',
        'success'
      );
      loadEverything();
    } catch (err) {
      error.textContent = err.message;
    } finally {
      saveButton.disabled = false;
    }
  });

  function loadEverything() {
    Promise.all([
      fetch('backend/books-data.php', { credentials: 'same-origin' }).then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      }),
      fetch('backend/my-requests-data.php', { credentials: 'same-origin' }).then(res => res.ok ? res.json() : { requests: [] }),
      fetch('backend/my-reservations-data.php', { credentials: 'same-origin' }).then(res => res.ok ? res.json() : { reservations: [] })
    ])
      .then(([booksData, requestsData, reservationsData]) => {
        allBooks = booksData.books || [];
        myRequestByBook = {};
        (requestsData.requests || []).forEach(r => {
          if (r.status === 'pending' || r.status === 'approved') {
            myRequestByBook[r.book_id] = r.status;
          }
        });
        myReservationsByBook = {};
        (reservationsData.reservations || []).forEach(r => {
          if (r.status === 'waiting' || r.status === 'ready') {
            myReservationsByBook[r.book_id] = r;
          }
        });
        document.getElementById('loadingMsg').style.display = 'none';
        document.getElementById('bookGrid').style.display = 'grid';
        applyFilter();
      })
      .catch(err => {
        if (err.message === 'not_logged_in') {
          const returnUrl = Number.isSafeInteger(scannedBookId) && scannedBookId > 0
            ? `?book=${encodeURIComponent(scannedBookId)}`
            : '';
          window.location.href = `login.html${returnUrl}`;
          return;
        }
        document.getElementById('loadingMsg').textContent = 'Something went wrong loading books.';
      });
  }

  document.getElementById('searchInput').addEventListener('input', applyFilter);
  loadEverything();
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadEverything();
  });

  // Profile button: check auth and go to profile or the shared sign-in page.
  document.getElementById('profileBtn').addEventListener('click', () => {
    fetch('backend/session-status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(res => res.json())
      .then(data => {
        window.location.href = data.loggedIn ? 'profile.html' : 'login.html';
      })
      .catch(() => {
        window.location.href = 'login.html';
      });
  });
