// Shared labels keep legacy loans distinct from new approvals awaiting pickup.
window.borrowing = {
  label(status) {
    return ({pending:'Pending', awaiting_pickup:'Approved / Awaiting Pickup', checked_out:'Checked Out',
      approved:'Checked Out', returned:'Returned', rejected:'Rejected', waiting:'Waiting', ready:'Ready for Pickup',
      fulfilled:'Checked Out', cancelled:'Cancelled', borrowed:'Checked Out'})[status] || status || 'Unknown';
  },
  isCheckedOut(status) {
    return ['checked_out', 'approved', 'fulfilled', 'borrowed'].includes(status);
  },
  loanStart(record) {
    return record.borrowed_at || record.checked_out_at
      || (['approved', 'returned'].includes(record.status) ? record.approved_at : null);
  },
  requestable(book) {
    return Number(book.requestable_copies == null ? Number(book.total_copies) - Number(book.borrowed_copies) : book.requestable_copies);
  },
  date(value) {
    if (!value) return null;
    // MySQL API timestamps are returned in Philippine library time.
    const text = String(value).replace(' ', 'T');
    return new Date(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(text) ? text + '+08:00' : text);
  }
};
