(function () {
  'use strict';
  function fmt(run) {
    if (!run) return 'never synced';
    const when = run.finished_at || run.started_at;
    return (run.status === 'ok' ? 'Last sync ' : 'Last sync FAILED ') + when + ' UTC';
  }
  async function refresh() {
    const label = document.getElementById('sync-status');
    if (!label) return;
    try {
      const res = await Api.get('/api/sync/last');
      label.textContent = fmt(res.run);
    } catch (e) {
      label.textContent = e.status === 404 ? 'never synced' : '';
    }
  }
  async function runSync(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing…';
    try {
      const res = await Api.post('/api/landings/sync');
      Toast.success(`Sync done: ${res.run.added} added, ${res.run.updated} updated, ${res.run.removed} removed`);
      document.dispatchEvent(new CustomEvent('tm:synced'));
    } catch (e) {
      Toast.error('Sync failed: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sync landings';
      refresh();
    }
  }
  window.Sync = {
    mount() {
      App.setNav('<span id="sync-status" class="text-white-50 small me-2"></span><button id="sync-btn" class="btn btn-sm btn-secondary-yellow"><i class="bi bi-arrow-repeat me-1"></i>Sync landings</button>');
      document.getElementById('sync-btn').addEventListener('click', (e) => runSync(e.currentTarget));
      refresh();
    },
  };
})();
