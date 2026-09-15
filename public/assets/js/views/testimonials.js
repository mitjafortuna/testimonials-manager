(function () {
  'use strict';
  window.Views = window.Views || {};

  const genderIcon = (g) => ({ male: 'bi-gender-male', female: 'bi-gender-female', unisex: 'bi-gender-ambiguous' }[g] || 'bi-gender-ambiguous');

  let dragEl = null;
  function enableReorder(tbody, onReordered) {
    tbody.querySelectorAll('tr[draggable="true"]').forEach((tr) => {
      tr.addEventListener('dragstart', () => { dragEl = tr; tr.classList.add('tm-dragging'); });
      tr.addEventListener('dragend', () => { tr.classList.remove('tm-dragging'); dragEl = null; });
      tr.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!dragEl || dragEl === tr) return;
        const rect = tr.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        tr.parentNode.insertBefore(dragEl, before ? tr : tr.nextSibling);
      });
      tr.addEventListener('drop', async (e) => {
        e.preventDefault();
        const ids = [...tbody.querySelectorAll('tr[data-id]')].map((r) => parseInt(r.dataset.id, 10));
        try {
          await onReordered(ids);
        } catch (err) {
          Toast.error('Could not save order: ' + err.message);
        }
      });
    });
  }

  function row(t, inherited) {
    const stars = `<span class="tm-stars" title="${t.rating === null ? 'Random: shown between 4.0 and 5.0' : 'Fixed rating'}">★ ${t.rating_display.toFixed(1)}</span>${t.rating === null ? ' <span class="badge bg-warning text-dark" title="Random rating">🎲</span>' : ''}`;
    const thumbs = (t.images || []).slice(0, 3).map((i) => `<img src="media/${esc(i.thumb_filename)}" alt="" class="tm-thumb">`).join('');
    return `
      <tr data-id="${t.id}" ${inherited ? '' : 'draggable="true"'}>
        <td class="tm-drag-handle text-muted" title="${inherited ? '' : 'Drag to reorder'}">${inherited ? '' : '<i class="bi bi-grip-vertical"></i>'}</td>
        <td><input type="checkbox" class="form-check-input tm-select" ${inherited ? 'disabled' : ''} aria-label="Select"></td>
        <td class="text-muted tabular">${t.sort_order}</td>
        <td><i class="bi ${genderIcon(t.gender)} me-1 text-muted"></i>${esc(t.author_name)}${t.url ? ` <a href="${esc(t.url)}" target="_blank" rel="noopener" title="${esc(t.url)}"><i class="bi bi-box-arrow-up-right small"></i></a>` : ''}</td>
        <td class="tm-text" title="${esc(t.text)}">${esc(t.text)}</td>
        <td class="text-nowrap">${stars}</td>
        <td>${thumbs}${(t.images || []).length > 3 ? `<span class="badge tm-count-badge">+${t.images.length - 3}</span>` : ''}</td>
        <td><div class="d-flex align-items-center gap-2"><div class="form-check form-switch m-0"><input class="form-check-input tm-active" type="checkbox" ${t.is_active ? 'checked' : ''} ${inherited ? 'disabled' : ''} aria-label="Active"></div><span class="tm-save-status"></span></div></td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-primary tm-edit" ${inherited ? 'disabled' : ''}><i class="bi bi-pencil"></i> Edit</button>
          <button class="btn btn-sm btn-outline-danger tm-delete" ${inherited ? 'disabled' : ''}><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;
  }

  function wireBulkToolbar(landingId, reload) {
    function updateToolbar() {
      const checked = [...document.querySelectorAll('.tm-select:checked')];
      const toolbar = document.getElementById('bulk-toolbar');
      if (!checked.length) { toolbar.classList.add('d-none'); toolbar.innerHTML = ''; return; }
      toolbar.classList.remove('d-none');
      toolbar.innerHTML = `<strong>${checked.length}</strong> selected
        <button class="btn btn-sm btn-outline-success" id="bulk-activate">Activate</button>
        <button class="btn btn-sm btn-outline-secondary" id="bulk-deactivate">Deactivate</button>
        <button class="btn btn-sm btn-outline-danger ms-auto" id="bulk-delete">Delete</button>`;
      const ids = () => [...document.querySelectorAll('.tm-select:checked')].map((c) => parseInt(c.closest('tr').dataset.id, 10));
      const run = async (action) => {
        try {
          await Api.post(`/api/landings/${landingId}/testimonials/bulk`, { ids: ids(), action });
          Toast.success('Updated');
          reload();
        } catch (err) {
          Toast.error('Could not update: ' + err.message);
        }
      };
      document.getElementById('bulk-activate').addEventListener('click', () => run('activate'));
      document.getElementById('bulk-deactivate').addEventListener('click', () => run('deactivate'));
      document.getElementById('bulk-delete').addEventListener('click', async () => {
        const ok = await Confirm.ask({ title: 'Delete testimonials', body: `Delete ${checked.length} testimonial(s)? This cannot be undone.`, confirmLabel: 'Delete' });
        if (ok) run('delete');
      });
    }
    document.getElementById('testimonials-table').addEventListener('change', (e) => {
      if (e.target.id === 'tm-select-all') {
        document.querySelectorAll('.tm-select:not(:disabled)').forEach((c) => { c.checked = e.target.checked; });
        updateToolbar();
      } else if (e.target.classList.contains('tm-select')) {
        updateToolbar();
      }
    });
  }

  window.Views.testimonials = async function (params) {
    const landingId = params.id;
    App.el.innerHTML = '<div class="text-muted">Loading…</div>';
    let res;
    try {
      res = await Api.get(`/api/landings/${encodeURIComponent(landingId)}/testimonials`);
    } catch (e) {
      App.el.innerHTML = `<div class="alert alert-danger">${esc(e.status === 404 ? 'Landing not found.' : 'Could not load testimonials: ' + e.message)}</div>`;
      return;
    }
    const L = res.meta.landing;
    const inherited = res.meta.inherited;
    const sku = (L.url.match(/\/([^/]+)\/?$/) || [])[1] || '';
    App.el.innerHTML = `
      <nav aria-label="breadcrumb"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="#/products">Products</a></li>
        <li class="breadcrumb-item"><a href="#/products/${encodeURIComponent(sku)}">${esc(sku)}</a></li>
        <li class="breadcrumb-item active">${esc(L.country)}</li></ol></nav>
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><h1 class="h3 mb-1"><span class="tm-country-code me-2">${esc(L.country)}</span>${esc(L.title)}</h1><a class="small" href="${esc(L.url)}" target="_blank" rel="noopener">${esc(L.url)}</a></div>
        <button class="btn btn-primary" id="add-testimonial"><i class="bi bi-plus-lg me-1"></i>Add testimonial</button>
        <button class="btn btn-outline-primary" id="copy-testimonials"><i class="bi bi-files me-1"></i>Copy…</button>
      </div>
      <div id="bulk-toolbar" class="alert alert-secondary d-none d-flex align-items-center gap-2 py-2 mb-3"></div>
      ${inherited ? `<div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill"></i><div><strong>Inherited from the English master.</strong> This landing has no testimonials of its own, so the EN set below is what visitors see. Add a testimonial here to start a local set.</div></div>` : ''}
      <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0" id="testimonials-table">
        <thead><tr><th></th><th><input type="checkbox" class="form-check-input" id="tm-select-all" aria-label="Select all"></th><th>#</th><th>Author</th><th>Text</th><th>Rating</th><th>Images</th><th>Active</th><th></th></tr></thead>
        <tbody>${res.data.map((t) => row(t, inherited)).join('') || '<tr><td colspan="9" class="text-muted text-center py-4">No testimonials yet.</td></tr>'}</tbody>
      </table></div></div>`;

    const reload = () => Router.navigate(`#/landings/${landingId}`);
    document.getElementById('add-testimonial').addEventListener('click', () =>
      TestimonialForm.open({ landingId, country: L.country, testimonial: null, onSaved: reload }));
    document.getElementById('copy-testimonials').addEventListener('click', () =>
      CopyTestimonials.open({ landingId, sku, onCopied: reload }));

    const byId = Object.fromEntries(res.data.map((t) => [t.id, t]));
    document.getElementById('testimonials-table').addEventListener('click', async (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;
      const t = byId[tr.dataset.id];
      if (e.target.closest('.tm-edit')) {
        TestimonialForm.open({ landingId, country: L.country, testimonial: t, onSaved: reload });
      } else if (e.target.closest('.tm-delete')) {
        const ok = await Confirm.ask({ title: 'Delete testimonial', body: `Delete the testimonial by ${t.author_name}? This cannot be undone.`, confirmLabel: 'Delete' });
        if (!ok) return;
        try {
          await Api.del(`/api/testimonials/${t.id}`);
          tr.remove();
          Toast.success('Testimonial deleted');
        } catch (err) {
          Toast.error('Could not delete: ' + err.message);
        }
      }
    });
    document.getElementById('testimonials-table').addEventListener('change', async (e) => {
      if (!e.target.classList.contains('tm-active')) return;
      const tr = e.target.closest('tr[data-id]');
      const t = byId[tr.dataset.id];
      const status = SaveStatus.bind(tr.querySelector('.tm-save-status'));
      const value = e.target.checked;
      const attempt = async () => {
        status.saving();
        e.target.disabled = true;
        try {
          const r = await Api.patch(`/api/testimonials/${t.id}`, { is_active: value });
          byId[t.id] = r.testimonial;
          status.saved();
        } catch (err) {
          e.target.checked = !value;
          status.failed(err.message, attempt);
          Toast.error('Could not save: ' + err.message);
        } finally {
          e.target.disabled = false;
        }
      };
      attempt();
    });

    if (!inherited) {
      enableReorder(document.querySelector('#testimonials-table tbody'), async (ids) => {
        await Api.patch(`/api/landings/${landingId}/testimonials/reorder`, { ids });
        Toast.success('Order saved');
        reload();
      });
    }

    wireBulkToolbar(landingId, reload);
  };
})();
