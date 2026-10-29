// Both catalog and librarian pages use the same Philippine-time pickup picker.
window.reservationScheduling = {
  today() {
    return new Intl.DateTimeFormat('en-CA', { timeZone:'Asia/Manila', year:'numeric', month:'2-digit', day:'2-digit' }).format(new Date());
  },
  format(value) {
    if (!value) return 'Not scheduled';
    const date = borrowing.date(value);
    if (!date || Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('en-PH', { timeZone:'Asia/Manila', year:'numeric', month:'short',
      day:'numeric', hour:'numeric', minute:'2-digit' });
  },
  slots() {
    const slots = [];
    for (let minute = 7 * 60; minute <= 16 * 60; minute += 30) {
      slots.push(`${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`);
    }
    return slots;
  },
  slotAvailable(data, date, time, now) {
    if (!reservationScheduling.slots().includes(time)) return false;
    const weekday = new Date(`${date}T12:00:00+08:00`).getUTCDay();
    return weekday >= 1 && weekday <= 5 && Date.parse(`${date}T${time}:00+08:00`) > now
      && (data.settings.daily_limit === null || (data.days[date] || 0) < data.settings.daily_limit)
      && (data.settings.slot_limit === null || (data.slots[`${date} ${time}:00`] || 0) < data.settings.slot_limit);
  },
  createPicker(onSave) {
    const dialog = document.getElementById('pickupDialog');
    const grid = document.getElementById('pickupCalendar');
    const select = document.getElementById('pickupTime');
    const save = document.getElementById('pickupSave');
    const error = document.getElementById('pickupError');
    const retry = document.getElementById('pickupRetry');
    let target = null, month = '', date = '', time = '', data = null, receivedAt = 0, generation = 0, saving = false;

    function now() { return Date.parse(data.now) + Date.now() - receivedAt; }
    function renderTimes() {
      select.replaceChildren(new Option(date ? 'Select a time' : 'Select a date first', ''));
      select.disabled = !date || !data;
      if (!date || !data) return;
      reservationScheduling.slots().forEach(slot => {
        if (Date.parse(`${date}T${slot}:00+08:00`) <= now()) return;
        const available = reservationScheduling.slotAvailable(data, date, slot, now());
        const option = new Option(`${slot} (Philippine time)${available ? '' : ' — Full'}`, slot);
        option.disabled = !available;
        select.add(option);
      });
      if (!Array.from(select.options).some(option => option.value === time && !option.disabled)) time = '';
      select.value = time;
      save.disabled = !time || saving;
    }
    function render() {
      const [year, number] = month.split('-').map(Number);
      const first = new Date(year, number - 1, 1);
      const last = new Date(year, number, 0).getDate();
      document.getElementById('pickupMonth').textContent = first.toLocaleDateString('en-PH', { month:'long', year:'numeric' });
      document.getElementById('pickupPreviousMonth').disabled = month <= data.now.slice(0, 7);
      document.getElementById('pickupNextMonth').disabled = month === '9999-12';
      grid.innerHTML = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(day => `<span class="pickup-weekday" aria-hidden="true">${day}</span>`).join('');
      for (let blank = 0; blank < first.getDay(); blank++) grid.insertAdjacentHTML('beforeend', '<span></span>');
      for (let day = 1; day <= last; day++) {
        const key = `${month}-${String(day).padStart(2, '0')}`;
        const weekday = new Date(year, number - 1, day).getDay();
        const available = reservationScheduling.slots().some(slot => reservationScheduling.slotAvailable(data, key, slot, now()));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `pickup-day${key === date ? ' selected' : ''}`;
        button.textContent = day;
        button.disabled = !available;
        button.setAttribute('aria-pressed', String(key === date));
        const state = weekday === 0 || weekday === 6 ? 'Closed' : key < data.now.slice(0, 10) ? 'Past date' : available ? 'Available' : 'Full or no future times';
        button.setAttribute('aria-label', `${key}, ${state}`);
        button.title = state;
        button.addEventListener('click', () => { date = key; time = ''; render(); });
        grid.appendChild(button);
      }
      const daily = data.settings.daily_limit === null ? 'unlimited per day' : `${data.settings.daily_limit} per day`;
      const slot = data.settings.slot_limit === null ? 'unlimited per half-hour' : `${data.settings.slot_limit} per half-hour`;
      document.getElementById('pickupCapacityNote').textContent = `Pickup capacity: ${daily}; ${slot}. A schedule does not guarantee a book copy.`;
      renderTimes();
    }
    async function load() {
      const current = ++generation;
      data = null;
      save.disabled = true;
      select.disabled = true;
      grid.textContent = 'Loading pickup availability…';
      error.textContent = '';
      retry.hidden = true;
      try {
        const exclude = target.reservation ? `&exclude_id=${target.reservation.id}` : '';
        const response = await fetch(`backend/reservation-availability.php?month=${month}${exclude}`, { credentials:'same-origin', cache:'no-store' });
        const result = await response.json();
        if (current !== generation) return;
        if (!response.ok) throw new Error(result.error || 'Could not load pickup availability.');
        data = result;
        receivedAt = Date.now();
        render();
      } catch (exception) {
        if (current !== generation) return;
        grid.textContent = '';
        error.textContent = exception.message;
        retry.hidden = false;
      }
    }
    function move(offset) {
      if (saving) return;
      const [year, number] = month.split('-').map(Number);
      const next = new Date(year, number - 1 + offset, 1);
      month = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
      date = time = '';
      load();
    }
    document.getElementById('pickupPreviousMonth').addEventListener('click', () => move(-1));
    document.getElementById('pickupNextMonth').addEventListener('click', () => move(1));
    document.getElementById('pickupCancel').addEventListener('click', () => { if (!saving) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    retry.addEventListener('click', load);
    select.addEventListener('change', () => { time = select.value; save.disabled = !time || saving; });
    document.getElementById('pickupForm').addEventListener('submit', async event => {
      event.preventDefault();
      if (saving || !data) return;
      if (!date || !time || !reservationScheduling.slotAvailable(data, date, time, now())) {
        error.textContent = 'Choose a future pickup date and available time.';
        return;
      }
      saving = true;
      save.disabled = true;
      try {
        await onSave(target, `${date}T${time}`);
        dialog.close();
      } catch (exception) {
        await load();
        error.textContent = exception.message;
      } finally {
        saving = false;
        save.disabled = !time || !data;
      }
    });
    return {
      open(value, title, saveLabel) {
        if (saving) return;
        target = value;
        date = value.reservation?.pickup_at?.slice(0, 10) || '';
        time = value.reservation?.pickup_at?.slice(11, 16) || '';
        const today = reservationScheduling.today();
        if (date < today) date = time = '';
        month = (date || today).slice(0, 7);
        document.getElementById('pickupDialogTitle').textContent = title;
        save.textContent = saveLabel;
        dialog.showModal();
        load();
      }
    };
  }
};
