(function () {
  'use strict';
  const container = document.getElementById('toasts');
  function show(message, variant, delay) {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + variant + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    el.querySelector('.toast-body').textContent = message;
    container.appendChild(el);
    const t = new bootstrap.Toast(el, { delay });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  }
  window.Toast = {
    success: (m) => show(m, 'success', 3000),
    info: (m) => show(m, 'dark', 3000),
    error: (m) => show(m, 'danger', 8000),
  };
})();
