(function () {
  'use strict';
  let el, modal;
  let markDirty = () => {};
  let currentCountry = '';

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'testimonial-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" id="testimonial-form" novalidate>
      <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="alert alert-danger d-none" id="tf-error" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="tf-author">Author name</label><input class="form-control" id="tf-author" name="author_name" maxlength="128" required><div class="invalid-feedback"></div></div>
          <div class="col-md-3"><label class="form-label" for="tf-rating">Rating</label><select class="form-select" id="tf-rating" name="rating"><option value="random">Random (4–5 at display)</option><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option></select><div class="invalid-feedback"></div></div>
          <div class="col-md-3"><label class="form-label" for="tf-sort">Sort order</label><input class="form-control" id="tf-sort" name="sort_order" type="number" min="0" step="1" placeholder="auto"><div class="invalid-feedback"></div></div>
          <div class="col-12"><label class="form-label" for="tf-text">Text <span class="text-muted small" id="tf-count">0 / 2000</span></label><textarea class="form-control" id="tf-text" name="text" rows="5" maxlength="2000" required></textarea><div class="invalid-feedback"></div></div>
          <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <select class="form-select form-select-sm w-auto" id="tf-ai-provider" aria-label="AI provider"></select>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="tf-ai-translate"><i class="bi bi-translate me-1"></i>Translate from EN</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="tf-ai-name"><i class="bi bi-magic me-1"></i>Suggest name</button>
              <span class="text-muted small" id="tf-ai-status"></span>
            </div>
          </div>
          <div class="col-md-6"><label class="form-label d-block">Gender</label>
            <div class="btn-group" role="group" aria-label="Gender">
              <input type="radio" class="btn-check" name="gender" id="tf-g-m" value="male"><label class="btn btn-outline-secondary" for="tf-g-m">Male</label>
              <input type="radio" class="btn-check" name="gender" id="tf-g-f" value="female"><label class="btn btn-outline-secondary" for="tf-g-f">Female</label>
              <input type="radio" class="btn-check" name="gender" id="tf-g-u" value="unisex" checked><label class="btn btn-outline-secondary" for="tf-g-u">Unisex</label>
            </div><div class="invalid-feedback d-block"></div></div>
          <div class="col-md-6"><label class="form-label" for="tf-url">Link (URL)</label><input class="form-control" id="tf-url" name="url" type="url" placeholder="https://"><div class="invalid-feedback"></div></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="tf-active" name="is_active" checked><label class="form-check-label" for="tf-active">Active (shown on the landing page)</label></div><div class="invalid-feedback d-block"></div></div>
          <div class="col-12" id="testimonial-images-slot"></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <span id="tf-status" class="tm-save-status"></span>
        <div><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button> <button type="submit" class="btn btn-primary" id="tf-save">Save</button></div>
      </div>
    </form></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el, { backdrop: 'static' });
    el.querySelector('#tf-text').addEventListener('input', (e) => { el.querySelector('#tf-count').textContent = `${e.target.value.length} / 2000`; });
    const form = el.querySelector('#testimonial-form');
    form.addEventListener('input', () => markDirty());
    form.addEventListener('change', () => markDirty());

    const providerSelect = el.querySelector('#tf-ai-provider');
    Api.get('/api/ai/providers').then((res) => {
      providerSelect.innerHTML = res.data.map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
    }).catch(() => { providerSelect.innerHTML = '<option value="">AI unavailable</option>'; });

    const aiStatus = el.querySelector('#tf-ai-status');
    el.querySelector('#tf-ai-translate').addEventListener('click', async () => {
      const f = el.querySelector('#testimonial-form');
      if (!f.text.value.trim()) { Toast.error('Type some text first'); return; }
      aiStatus.textContent = 'Translating…';
      try {
        const res = await Api.post('/api/ai/translate', { provider: providerSelect.value, text: f.text.value, target_country: currentCountry });
        f.text.value = res.text;
        el.querySelector('#tf-count').textContent = `${res.text.length} / 2000`;
        aiStatus.textContent = '';
        markDirty();
      } catch (err) {
        aiStatus.textContent = '';
        Toast.error('Translate failed: ' + err.message);
      }
    });
    el.querySelector('#tf-ai-name').addEventListener('click', async () => {
      const f = el.querySelector('#testimonial-form');
      aiStatus.textContent = 'Suggesting…';
      try {
        const res = await Api.post('/api/ai/author-name', { provider: providerSelect.value, country: currentCountry, gender: f.gender.value });
        f.author_name.value = res.name;
        aiStatus.textContent = '';
        markDirty();
      } catch (err) {
        aiStatus.textContent = '';
        Toast.error('Suggest name failed: ' + err.message);
      }
    });
  }

  function clearErrors() {
    el.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
    el.querySelectorAll('.invalid-feedback').forEach((f) => { f.textContent = ''; });
    const box = el.querySelector('#tf-error'); box.classList.add('d-none'); box.textContent = '';
  }

  function showFieldErrors(fields) {
    for (const [name, msg] of Object.entries(fields)) {
      const input = el.querySelector(`[name="${name}"]`);
      if (!input) continue;
      input.classList.add('is-invalid');
      const fb = input.closest('.col-12, .col-md-6, .col-md-3')?.querySelector('.invalid-feedback');
      if (fb) fb.textContent = msg;
    }
  }

  function read() {
    const f = el.querySelector('#testimonial-form');
    return {
      author_name: f.author_name.value,
      text: f.text.value,
      rating: f.rating.value,
      gender: f.gender.value,
      url: f.url.value,
      is_active: f.is_active.checked,
      sort_order: f.sort_order.value === '' ? null : f.sort_order.value,
    };
  }

  function fill(t) {
    const f = el.querySelector('#testimonial-form');
    f.author_name.value = t ? t.author_name : '';
    f.text.value = t ? t.text : '';
    f.rating.value = t && t.rating !== null ? String(t.rating) : 'random';
    f.url.value = t && t.url ? t.url : '';
    f.is_active.checked = t ? t.is_active : true;
    f.sort_order.value = t ? t.sort_order : '';
    el.querySelector(`#tf-g-${t ? t.gender[0] : 'u'}`).checked = true;
    el.querySelector('#tf-count').textContent = `${f.text.value.length} / 2000`;
  }

  window.TestimonialForm = {
    open({ landingId, country, testimonial, onSaved }) {
      ensure();
      currentCountry = country || '';
      clearErrors();
      fill(testimonial);
      el.querySelector('.modal-title').textContent = testimonial ? `Edit testimonial #${testimonial.id}` : 'New testimonial';
      const status = SaveStatus.bind(el.querySelector('#tf-status'));
      el.querySelector('#tf-status').innerHTML = '';
      const form = el.querySelector('#testimonial-form');
      const slot = el.querySelector('#testimonial-images-slot');
      slot.innerHTML = '';
      document.dispatchEvent(new CustomEvent('tm:testimonial-form-open', { detail: { slot, testimonial } }));
      const onChange = () => { el.querySelector('#tf-status').className = 'tm-save-status saving'; el.querySelector('#tf-status').innerHTML = '<i class="bi bi-pencil me-1"></i>Unsaved changes'; };
      markDirty = onChange;
      form.onsubmit = async (e) => {
        e.preventDefault();
        clearErrors();
        const btn = el.querySelector('#tf-save');
        btn.disabled = true;
        status.saving();
        try {
          const res = testimonial
            ? await Api.patch(`/api/testimonials/${testimonial.id}`, read())
            : await Api.post(`/api/landings/${landingId}/testimonials`, read());
          status.saved();
          Toast.success(testimonial ? 'Testimonial saved' : 'Testimonial created');
          modal.hide();
          onSaved(res.testimonial);
        } catch (err) {
          status.failed(err.message);
          if (err.status === 422) {
            showFieldErrors(err.fields);
          } else {
            const box = el.querySelector('#tf-error'); box.textContent = 'Could not save: ' + err.message; box.classList.remove('d-none');
            Toast.error('Could not save: ' + err.message);
          }
        } finally {
          btn.disabled = false;
        }
      };
      modal.show();
    },
    hide() { if (modal) modal.hide(); },
  };
})();
