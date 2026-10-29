// Compatibility cleanup for older local staging pages; no banner or network requests.
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('stagingIndicator')?.remove();
});
