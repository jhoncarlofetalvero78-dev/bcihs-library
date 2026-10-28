(() => {
  let confirmationOpen = false;
  async function confirmAction(message, options = {}) {
    // A second click cannot stack another confirmation or submit another operation.
    if (confirmationOpen) return false;
    confirmationOpen = true;
    const previous = document.activeElement;
    const dialog = document.createElement('dialog');
    dialog.className = 'app-dialog';
    const title = document.createElement('h2');
    title.id = 'app-confirm-title';
    title.textContent = options.title || 'Confirm library action';
    const description = document.createElement('p');
    description.id = 'app-confirm-description';
    description.textContent = message;
    const actions = document.createElement('div');
    actions.className = 'app-dialog-actions';
    const cancel = document.createElement('button');
    cancel.type = 'button'; cancel.className = 'ui-secondary'; cancel.textContent = 'Cancel';
    const accept = document.createElement('button');
    accept.type = 'button'; accept.className = options.danger ? 'ui-danger' : 'ui-primary';
    accept.textContent = options.confirmLabel || 'Confirm';
    actions.append(cancel, accept);
    dialog.append(title, description, actions);
    dialog.setAttribute('aria-labelledby', title.id);
    dialog.setAttribute('aria-describedby', description.id);
    document.body.append(dialog);
    return new Promise(resolve => {
      let completed = false;
      const finish = result => {
        if (completed) return;
        completed = true;
        dialog.close(); dialog.remove(); confirmationOpen = false;
        if (previous?.isConnected) previous.focus();
        resolve(result);
      };
      cancel.addEventListener('click', () => finish(false));
      accept.addEventListener('click', () => finish(true));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
      dialog.showModal(); cancel.focus();
    });
  }
  function dialogFeedback(message) {
    const dialog = document.querySelector('dialog[data-managed-confirmation][open]:not([hidden])');
    if (!dialog) return;
    let feedback = dialog.querySelector('.dialog-feedback');
    if (!feedback) {
      feedback = document.createElement('p'); feedback.className = 'dialog-feedback';
      feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
      dialog.append(feedback);
    }
    feedback.textContent = message;
  }
  window.libraryUI = { confirm: confirmAction, dialogFeedback };

  function enhancePage() {
    const profileMenu = document.getElementById('profilePill');
    const dropdown = document.getElementById('profileDropdown');
    if (profileMenu && dropdown) {
      const toggle = document.createElement('button');
      toggle.type = 'button'; toggle.className = 'profile-toggle';
      toggle.setAttribute('aria-label', 'Account menu'); toggle.setAttribute('aria-controls', dropdown.id);
      [...profileMenu.children].filter(node => node !== dropdown).forEach(node => toggle.append(node));
      profileMenu.prepend(toggle);
      const expanded = () => toggle.setAttribute('aria-expanded', String(dropdown.classList.contains('show')));
      expanded(); new MutationObserver(expanded).observe(dropdown, {attributes:true,attributeFilter:['class']});
      profileMenu.addEventListener('keydown', event => {
        if (event.key === 'Escape' && dropdown.classList.contains('show')) { toggle.click(); toggle.focus(); }
      });
    }
    const menuButton = document.getElementById('hamburger'), navigation = document.getElementById('mainNav');
    if (menuButton && navigation) {
      menuButton.setAttribute('aria-controls', navigation.id);
      const expanded = () => menuButton.setAttribute('aria-expanded', String(navigation.classList.contains('open')));
      expanded(); new MutationObserver(expanded).observe(navigation,{attributes:true,attributeFilter:['class']});
    }
    document.querySelectorAll('[role="tablist"]').forEach(list => {
      list.addEventListener('keydown', event => {
        if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        const tabs = [...list.querySelectorAll('[role="tab"]')], current = tabs.indexOf(document.activeElement);
        if (current < 0) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length-1
          : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next].click(); tabs[next].focus();
      });
    });
    const main = document.querySelector('main');
    if (main) {
      main.id ||= 'main-content'; main.tabIndex = -1;
      const skip = document.createElement('a'); skip.href = '#' + main.id;
      skip.className = 'skip-link'; skip.textContent = 'Skip to content'; document.body.prepend(skip);
    }
    document.querySelectorAll('[data-soon]').forEach(button => {
      button.disabled = true;
      button.title = 'Not available in this version';
      button.setAttribute('aria-disabled', 'true');
    });
    const enhanceTables = () => {
      document.querySelectorAll('table').forEach(table => {
        if (table.closest('.table-scroll,.table-wrap,.borrowing-table-wrap,.history-card,.sms-table-scroll,.ui-table-scroll')) return;
        const wrapper = document.createElement('div'); wrapper.className = 'ui-table-scroll';
        table.before(wrapper); wrapper.append(table);
      });
      document.querySelectorAll('.table-scroll,.table-wrap,.borrowing-table-wrap,.history-card,.sms-table-scroll,.ui-table-scroll').forEach(region => {
        if (!region.querySelector('table') || region.dataset.scrollReady) return;
        region.dataset.scrollReady = 'true';
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', 'Table; scroll sideways for more columns');
        const existingHint = region.previousElementSibling;
        const reuseHint = existingHint?.tagName === 'P' && /scroll/i.test(existingHint.textContent);
        const hint = reuseHint ? existingHint : document.createElement('p');
        hint.classList.add('table-overflow-hint');
        if (!reuseHint) { hint.textContent = 'Scroll sideways to view all columns and actions.'; region.before(hint); }
        const update = () => { hint.hidden = region.scrollWidth <= region.clientWidth + 1; };
        new ResizeObserver(update).observe(region);
        new MutationObserver(update).observe(region, { childList: true, subtree: true });
        update();
      });
    };
    enhanceTables();
    new MutationObserver(records => {
      if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1
          && (node.matches('table') || node.querySelector('table'))))) enhanceTables();
    }).observe(document.body, {childList:true, subtree:true});
    document.querySelectorAll('dialog[data-managed-confirmation]').forEach(dialog => {
      const previous = { node: null };
      const cancel = dialog.querySelector('[id^="cancel"]');
      const busy = () => [...dialog.querySelectorAll('button')].some(button => button.disabled);
      dialog.classList.add('app-dialog');
      dialog.setAttribute('aria-label', dialog.dataset.managedConfirmation);
      dialog.addEventListener('cancel', event => {
        event.preventDefault();
        if (!busy()) cancel?.click();
      });
      new MutationObserver(() => {
        if (!dialog.hidden && !dialog.open) {
          if (document.querySelector('dialog[open]')) { dialog.hidden = true; return; }
          previous.node = document.activeElement;
          dialog.querySelector('.dialog-feedback')?.remove();
          dialog.showModal(); (cancel || dialog.querySelector('button'))?.focus();
        } else if (dialog.hidden && dialog.open) {
          dialog.close(); if (previous.node?.isConnected) previous.node.focus();
        }
      }).observe(dialog, { attributes: true, attributeFilter: ['hidden'] });
    });
    // Existing edit/detail overlays retain their form logic; add keyboard focus containment.
    document.querySelectorAll('.modal-overlay,.request-details-overlay,#borrowModal').forEach(overlay => {
      const card = overlay.querySelector('.modal,.modal-card,.request-details-card') || overlay;
      const semanticDialog = overlay.getAttribute('role') === 'dialog' ? overlay : card;
      semanticDialog.setAttribute('role', 'dialog'); semanticDialog.setAttribute('aria-modal', 'true');
      const heading = card.querySelector('h2,h3');
      if (heading) { heading.id ||= 'dialog-' + overlay.id; semanticDialog.setAttribute('aria-labelledby', heading.id); }
      let previous = null, wasVisible = false;
      new MutationObserver(() => {
        const visible = getComputedStyle(overlay).display !== 'none' && !overlay.hidden;
        if (visible && !wasVisible) { previous = document.activeElement; card.querySelector('button,input,select,textarea')?.focus(); }
        if (!visible && wasVisible && previous?.isConnected) previous.focus();
        wasVisible = visible;
      }).observe(overlay, { attributes: true, attributeFilter: ['class','style','hidden'] });
      overlay.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
          const saving = [...card.querySelectorAll('button[type="submit"]')].some(button => button.disabled);
          if (!saving) card.querySelector('[id^="cancel"],.modal-close-btn,[id^="close"]')?.click();
          return;
        }
        if (event.key !== 'Tab') return;
        const focusable = [...card.querySelectorAll('button,a[href],input,select,textarea,[tabindex="0"]')]
          .filter(node => !node.disabled && node.getClientRects().length);
        const first = focusable[0], last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      });
    });
    document.querySelectorAll('.toast,.status-banner,.form-message,.error-msg').forEach(node => {
      node.setAttribute('role', 'status'); node.setAttribute('aria-live', 'polite');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhancePage);
  else enhancePage();
})();
