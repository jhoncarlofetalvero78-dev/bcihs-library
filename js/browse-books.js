
  let allBooks = [];
  let csrfToken = '';
  let canBorrow = true;
  const submittingRequests = new Set();
  let myRequestByBook = {};  // Active request/loan status by book ID.
  let myReservationsByBook = {};  // book_id -> active reservation
  const urlParams = new URLSearchParams(window.location.search);
  const hasScannedBook = urlParams.has('book');
  const scannedBookValue = urlParams.get('book');
  const scannedBookId = /^\d+$/.test(scannedBookValue || '')
    ? Number(scannedBookValue)
    : null;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  function showBanner(message, kind) {
    const el = document.getElementById('statusBanner');
    el.textContent = message;
    el.className = `status-banner show ${kind}`;
    setTimeout(() => el.classList.remove('show'), 3200);
  }

  function buttonFor(book) {
    if (!canBorrow) return '<p class="pickup-info">Catalog viewing only. Ask library staff about borrower eligibility. Walk-in attendance does not authorize borrowing.</p>';
    if (submittingRequests.has(book.id)) return '<button class="borrow-btn" disabled>Submitting request…</button>';
    const available = borrowing.requestable(book);
    const myStatus = myRequestByBook[book.id];
    const myReservation = myReservationsByBook[book.id];

    if (myStatus === 'pending') {
      return `<button class="borrow-btn pending" disabled>Request Pending</button>`;
    }
    if (myStatus === 'awaiting_pickup') {
      return '<button class="borrow-btn approved" disabled>Approved — Awaiting Pickup</button><p class="pickup-info">A copy is held. The loan starts when staff confirms handover.</p>';
    }
    if (borrowing.isCheckedOut(myStatus)) {
      return `<button class="borrow-btn approved" disabled>Already Borrowed</button>`;
    }
    if (myReservation && myReservation.status === 'waiting') {
      const actionLabel = myReservation.pickup_at ? 'Change Preferred Pickup' : 'Choose Pickup Time';
      return `<button class="borrow-btn available" onclick="openPickupDialog(${book.id}, 'schedule')">${actionLabel}</button><p class="pickup-info">Schedule can be changed while waiting. It is final once ready.</p>${pickupInfo(myReservation)}${cancelReservationButton(myReservation)}`;
    }
    if (myReservation && myReservation.status === 'fulfilled') {
      return '<button class="borrow-btn approved" disabled>Checked Out</button>';
    }
    if (myReservation && myReservation.status === 'ready') {
      return `<button class="borrow-btn approved" disabled>Ready to Pick Up</button><p class="pickup-info">A copy is held. Bring your library identification; staff confirms physical checkout. Contact staff if you cannot attend.</p>${pickupInfo(myReservation)}${cancelReservationButton(myReservation)}`;
    }
    if (available <= 0) {
      return `<button class="borrow-btn available" onclick="reserveBook(${book.id})">Reserve</button>`;
    }
    return `<button class="borrow-btn available" onclick="requestBook(${book.id})">Request to Borrow</button>`;
  }

  function pickupInfo(reservation) {
    if (!reservation.pickup_at) return '<p class="pickup-info">No pickup time selected.</p>';
    const formatted = reservationScheduling.format(reservation.pickup_at);
    const label = reservation.status === 'waiting' ? 'Preferred pickup' : 'Confirmed pickup';
    return `<p class="pickup-info">${label}: ${escapeHtml(formatted)}</p>`;
  }

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

  function render(books) {
    const grid = document.getElementById('bookGrid');
    if (books.length === 0) {
      grid.innerHTML = hasScannedBook
        ? '<p class="empty-msg">No book was found for this QR code.</p>'
        : '<p class="empty-msg">No books match your search.</p>';
      return;
    }
    grid.innerHTML = books.map(b => {
      const available = borrowing.requestable(b);
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
          <div class="genre">Category: ${escapeHtml(b.category || b.genre) || 'Uncategorized'}</div>
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
            <span class="avail-count ${availClass}">${available} available for new requests</span>
            <small class="availability-detail"><span>Physical copies available: ${Number(b.total_copies) - Number(b.borrowed_copies)}</span><span>Held for pickup: ${Number(b.held_copies) || 0}</span></small>
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
      [b.title, b.author, b.isbn, b.category, b.genre, b.ddc, b.cutter_number, b.year_of_publication, b.call_number]
        .filter(Boolean).join(' ').toLowerCase().includes(q)
    );
    render(filtered);
  }

  window.requestBook = function(bookId) {
    if (!canBorrow || submittingRequests.has(bookId)) return;
    submittingRequests.add(bookId);
    applyFilter();

    fetch('backend/borrow-request-process.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
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
      .catch(err => showBanner(err.message, 'error'))
      .finally(() => { submittingRequests.delete(bookId); applyFilter(); });
  };

  const pickupPicker = reservationScheduling.createPicker(async (target, pickupAt) => {
    const reservation = target.reservation;
    const response = await fetch(reservation ? 'backend/reservation-action.php' : 'backend/reservation-process.php', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrfToken},
      body:JSON.stringify(reservation
        ? {id:reservation.id, action:'schedule', pickup_at:pickupAt}
        : {book_id:target.bookId, pickup_at:pickupAt})
    });
    const data = await response.json();
    if (!response.ok) {
      loadEverything();
      throw new Error(data.error || 'Could not save that reservation.');
    }
    showBanner(reservation ? 'Preferred pickup updated. Wait for library confirmation.'
      : 'Added to the waitlist. Your preferred time does not guarantee a copy; wait for Ready for Pickup.', 'success');
    loadEverything();
  });

  function openPickupDialog(bookId, mode) {
    const reservation = mode === 'schedule' ? myReservationsByBook[bookId] : null;
    pickupPicker.open({bookId, reservation}, reservation ? 'Change preferred pickup' : 'Choose preferred pickup',
      reservation ? 'Save preferred time' : 'Join waitlist');
  }
  window.reserveBook = bookId => openPickupDialog(bookId, 'reserve');

  const cancellingReservations = new Set();
  window.cancelReservation = async function(id, button) {
    if (cancellingReservations.has(id) || !await window.libraryUI.confirm('Cancel this reservation and release any pickup hold?', {title:'Cancel reservation',confirmLabel:'Cancel reservation',danger:true})) return;
    cancellingReservations.add(id);
    button.disabled = true;
    try {
      const response = await fetch('backend/reservation-action.php', {method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrfToken}, body:JSON.stringify({id, action:'cancel'})});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Could not cancel that reservation.');
      showBanner('Reservation cancelled. Any pickup hold has been released.', 'success');
      loadEverything();
    } catch (exception) { showBanner(exception.message, 'error'); }
    finally { cancellingReservations.delete(id); button.disabled = false; }
  };

  function cancelReservationButton(reservation) {
    return `<button type="button" class="reservation-cancel-btn" onclick="cancelReservation(${reservation.id}, this)">Cancel reservation</button>`;
  }

  function readCatalogResponse(response) {
    if (response.status === 401) throw new Error('not_logged_in');
    if (!response.ok) throw new Error('failed');
    return response.json();
  }

  function loadEverything() {
    Promise.all([
      fetch('backend/books-data.php', { credentials: 'same-origin' }).then(res => {
        if (res.status === 401) throw new Error('not_logged_in');
        if (!res.ok) throw new Error('failed');
        return res.json();
      }),
      fetch('backend/my-requests-data.php', { credentials: 'same-origin' }).then(readCatalogResponse),
      fetch('backend/my-reservations-data.php', { credentials: 'same-origin' }).then(readCatalogResponse)
    ])
      .then(([booksData, requestsData, reservationsData]) => {
        if (!Array.isArray(booksData.books) || !Array.isArray(requestsData.requests) || !Array.isArray(reservationsData.reservations)) throw new Error('invalid_response');
        allBooks = booksData.books;
        canBorrow = booksData.canBorrow !== false;
        csrfToken = booksData.csrfToken || '';
        myRequestByBook = {};
        (requestsData.requests || []).forEach(r => {
          if (['pending','awaiting_pickup','checked_out','approved'].includes(r.status)) {
            myRequestByBook[r.book_id] = r.status;
          }
        });
        myReservationsByBook = {};
        (reservationsData.reservations || []).forEach(r => {
          if (['waiting','ready','fulfilled'].includes(r.status)) {
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
        document.getElementById('bookGrid').style.display = 'none';
        document.getElementById('loadingMsg').style.display = 'block';
        document.getElementById('loadingMsg').textContent = 'Could not load books and your request/reservation status. Reload to try again.';
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
