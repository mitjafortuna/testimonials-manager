(function () {
  'use strict';
  window.Views = window.Views || {};

  window.Views.countries = async function (params) {
    const sku = params.sku;
    App.el.innerHTML = `
      <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="#/products">Products</a></li><li class="breadcrumb-item active">${esc(sku)}</li></ol></nav>
      <div id="countries"><div class="text-muted">Loading…</div></div>`;
    let res;
    try {
      res = await Api.get(`/api/products/${encodeURIComponent(sku)}/landings`);
    } catch (e) {
      document.getElementById('countries').innerHTML = `<div class="alert alert-danger">${esc(e.status === 404 ? 'Product not found.' : 'Could not load landings: ' + e.message)}</div>`;
      return;
    }
    const master = res.data.find((l) => l.is_master);
    const cards = res.data.map((l) => {
      const badge = l.is_master
        ? `<span class="badge bg-dark">EN master</span>`
        : l.inherits_from_master
          ? `<span class="badge bg-warning text-dark" title="No own testimonials — the English set is shown on this landing">inherits EN (${l.inherited_count})</span>`
          : `<span class="badge bg-success">${l.testimonial_count} own</span>`;
      return `
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
          <a class="card h-100 text-decoration-none tm-country-card" href="#/landings/${l.id}">
            <div class="card-body d-flex flex-column gap-2">
              <div class="d-flex justify-content-between align-items-center">
                <span class="tm-country-code">${esc(l.country)}</span>
                <span class="badge rounded-pill ${l.testimonial_count ? 'bg-primary' : 'bg-secondary'}">${l.testimonial_count}</span>
              </div>
              ${badge}
              <small class="text-muted text-truncate" title="${esc(l.url)}">${esc(l.status || '')}</small>
            </div>
          </a>
        </div>`;
    }).join('');
    document.getElementById('countries').innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><h1 class="h3 mb-1">${esc(master ? master.title : sku)}</h1><div class="text-muted small">${res.data.length} landings · pick a country to manage its testimonials</div></div>
      </div>
      <div class="row g-3">${cards}</div>`;
  };
})();
