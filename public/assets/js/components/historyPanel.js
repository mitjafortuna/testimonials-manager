(function () {
  'use strict';
  let el, modal;

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'history-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">History</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><ul class="list-group list-group-flush" id="history-list"></ul></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }

  const VERBS = { created: 'created', updated: 'updated', deleted: 'deleted', image_added: 'added an image', image_removed: 'removed an image' };

  function describe(entry) {
    const who = entry.user_name || 'System';
    const when = new Date(entry.created_at.replace(' ', 'T') + 'Z').toLocaleString();
    let detail = '';
    if (entry.action === 'updated' && entry.changes) {
      detail = Object.entries(entry.changes).map(([k, v]) => `${esc(k)}: “${esc(String(v.old))}” → “${esc(String(v.new))}”`).join('<br>');
    }
    return `<li class="list-group-item"><div class="d-flex justify-content-between"><strong>${esc(who)} ${esc(VERBS[entry.action] || entry.action)}</strong><small class="text-muted">${esc(when)}</small></div>${detail ? `<div class="small text-muted mt-1">${detail}</div>` : ''}</li>`;
  }

  window.HistoryPanel = {
    async open(testimonialId) {
      ensure();
      const list = el.querySelector('#history-list');
      list.innerHTML = '<li class="list-group-item text-muted">Loading…</li>';
      modal.show();
      try {
        const res = await Api.get(`/api/testimonials/${testimonialId}/history`);
        list.innerHTML = res.data.length ? res.data.map(describe).join('') : '<li class="list-group-item text-muted">No history yet.</li>';
      } catch (err) {
        list.innerHTML = `<li class="list-group-item text-danger">Could not load history: ${esc(err.message)}</li>`;
      }
    },
  };
})();
