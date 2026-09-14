(function () {
  'use strict';
  window.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  window.Views = window.Views || {};

  const COLS = [['sku', 'SKU'], ['title', 'Product'], ['landings', 'Landings'], ['testimonials', 'Testimonials']];

  function hashFor(q) {
    const p = new URLSearchParams();
    if (q.search) p.set('search', q.search);
    if (q.page && q.page !== 1) p.set('page', q.page);
    if (q.sort && q.sort !== 'sku') p.set('sort', q.sort);
    if (q.dir && q.dir !== 'asc') p.set('dir', q.dir);
    const s = p.toString();
    return '#/products' + (s ? '?' + s : '');
  }

  window.Views.products = async function (params, query) {
    const q = { search: query.search || '', page: parseInt(query.page || '1', 10), sort: query.sort || 'sku', dir: query.dir || 'asc' };
    App.el.innerHTML = `
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Products</h1>
        <form id="product-search" class="d-flex gap-2" role="search">
          <input id="product-search-input" class="form-control" type="search" placeholder="Search SKU or description" value="${esc(q.search)}" aria-label="Search products">
          <button class="btn btn-primary" type="submit">Search</button>
        </form>
      </div>
      <div id="products-table"><div class="text-muted">Loading…</div></div>`;
    document.getElementById('product-search').addEventListener('submit', (e) => {
      e.preventDefault();
      Router.navigate(hashFor({ ...q, search: document.getElementById('product-search-input').value.trim(), page: 1 }));
    });

    let res;
    try {
      res = await Api.get(`/api/products?search=${encodeURIComponent(q.search)}&page=${q.page}&per_page=20&sort=${q.sort}&dir=${q.dir}`);
    } catch (e) {
      document.getElementById('products-table').innerHTML = `<div class="alert alert-danger">Could not load products: ${esc(e.message)}</div>`;
      Toast.error('Could not load products: ' + e.message);
      return;
    }
    const head = COLS.map(([key, label]) => {
      const active = q.sort === key;
      const nextDir = active && q.dir === 'asc' ? 'desc' : 'asc';
      const icon = active ? (q.dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
      return `<th class="tm-sortable${active ? ' active' : ''}" data-sort="${key}" data-dir="${nextDir}">${label}${icon}</th>`;
    }).join('');
    const rows = res.data.map((p) => `
      <tr class="tm-row-link" data-href="#/products/${encodeURIComponent(p.sku)}">
        <td><code>${esc(p.sku)}</code></td>
        <td class="d-flex align-items-center gap-2">${p.image ? `<img src="${esc(p.image)}" alt="" width="40" height="40" class="rounded object-fit-cover">` : ''}<span>${esc(p.title)}</span></td>
        <td><span class="badge tm-count-badge">${p.landing_count}</span></td>
        <td><span class="badge tm-count-badge">${p.testimonial_count}</span></td>
      </tr>`).join('');
    document.getElementById('products-table').innerHTML = `
      <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr>${head}</tr></thead>
        <tbody>${rows || '<tr><td colspan="4" class="text-muted text-center py-4">No products match.</td></tr>'}</tbody>
      </table></div>
      <div class="card-body d-flex justify-content-between align-items-center">
        <span class="text-muted small">${res.meta.total} products</span>${Pagination.render(res.meta)}
      </div></div>`;
    document.querySelectorAll('#products-table th.tm-sortable').forEach((th) =>
      th.addEventListener('click', () => Router.navigate(hashFor({ ...q, sort: th.dataset.sort, dir: th.dataset.dir, page: 1 }))));
    document.querySelectorAll('#products-table [data-page]').forEach((b) =>
      b.addEventListener('click', () => Router.navigate(hashFor({ ...q, page: parseInt(b.dataset.page, 10) }))));
    document.querySelectorAll('#products-table .tm-row-link').forEach((tr) =>
      tr.addEventListener('click', () => Router.navigate(tr.dataset.href)));
  };
})();
