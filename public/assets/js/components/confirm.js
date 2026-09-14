(function () {
  'use strict';
  let modal, el;
  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'confirm-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>
      <div class="modal-body"></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirm-ok">Confirm</button></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }
  window.Confirm = {
    ask({ title, body, confirmLabel = 'Confirm', danger = true }) {
      ensure();
      el.querySelector('.modal-title').textContent = title;
      el.querySelector('.modal-body').textContent = body;
      const ok = el.querySelector('#confirm-ok');
      ok.textContent = confirmLabel;
      ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
      return new Promise((resolve) => {
        let decided = false;
        const onOk = () => { decided = true; modal.hide(); };
        const onHidden = () => { ok.removeEventListener('click', onOk); el.removeEventListener('hidden.bs.modal', onHidden); resolve(decided); };
        ok.addEventListener('click', onOk);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
      });
    },
  };
})();
