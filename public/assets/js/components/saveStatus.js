(function () {
  'use strict';
  window.SaveStatus = {
    bind(el) {
      const set = (cls, html) => { clearTimeout(el._tmSaveTimer); el.className = 'tm-save-status ' + cls; el.innerHTML = html; };
      return {
        saving() { set('saving', '<span class="spinner-border spinner-border-sm me-1"></span>Saving…'); },
        saved() { set('saved', '<i class="bi bi-check-circle-fill me-1"></i>Saved'); el._tmSaveTimer = setTimeout(() => { el.className = 'tm-save-status'; el.innerHTML = ''; }, 2500); },
        failed(message, retry) {
          set('failed', `<i class="bi bi-exclamation-triangle-fill me-1"></i>Failed${retry ? ' · <a href="#" class="tm-retry">Retry</a>' : ''}`);
          el.title = message;
          if (retry) el.querySelector('.tm-retry').addEventListener('click', (e) => { e.preventDefault(); retry(); });
        },
      };
    },
  };
})();
