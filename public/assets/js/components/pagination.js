(function () {
  'use strict';
  window.Pagination = {
    render(meta) {
      const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
      if (pages <= 1) return '';
      let html = '<nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">';
      const btn = (p, label, disabled, active) =>
        `<li class="page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}"><button type="button" class="page-link" data-page="${p}">${label}</button></li>`;
      html += btn(meta.page - 1, '&laquo;', meta.page <= 1, false);
      const from = Math.max(1, meta.page - 2), to = Math.min(pages, meta.page + 2);
      for (let p = from; p <= to; p++) html += btn(p, p, false, p === meta.page);
      html += btn(meta.page + 1, '&raquo;', meta.page >= pages, false);
      return html + '</ul></nav>';
    },
  };
})();
