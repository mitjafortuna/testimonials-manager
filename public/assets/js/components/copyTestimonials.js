(function () {
  'use strict';
  let el, modal;

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'copy-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Copy testimonials</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="cp-source">Copy from</label><select class="form-select" id="cp-source"></select></div>
        <div class="mb-3">
          <div class="form-check"><input class="form-check-input" type="radio" name="cp-mode" id="cp-append" value="append" checked><label class="form-check-label" for="cp-append">Append — add to the existing testimonials</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="cp-mode" id="cp-replace" value="replace"><label class="form-check-label" for="cp-replace">Replace — remove the existing testimonials first</label></div>
        </div>
        <div id="cp-preview" class="small text-muted"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="cp-go" disabled>Copy</button></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }

  window.CopyTestimonials = {
    async open({ landingId, sku, onCopied }) {
      ensure();
      const select = el.querySelector('#cp-source');
      const preview = el.querySelector('#cp-preview');
      const go = el.querySelector('#cp-go');
      select.innerHTML = '<option>Loading…</option>';
      preview.textContent = '';
      go.disabled = true;
      modal.show();

      let landings;
      try {
        landings = (await Api.get(`/api/products/${encodeURIComponent(sku)}/landings`)).data.filter((l) => String(l.id) !== String(landingId));
      } catch (err) {
        select.innerHTML = '';
        preview.textContent = 'Could not load landings: ' + err.message;
        return;
      }
      if (!landings.length) {
        select.innerHTML = '';
        preview.textContent = 'No other landings for this product yet.';
        return;
      }
      select.innerHTML = landings.map((l) => `<option value="${l.id}">${esc(l.country)} — ${esc(l.title)} (${l.testimonial_count})</option>`).join('');

      async function refreshPreview() {
        const mode = el.querySelector('input[name="cp-mode"]:checked').value;
        preview.textContent = 'Loading preview…';
        go.disabled = true;
        try {
          const p = await Api.get(`/api/landings/${landingId}/testimonials/copy-preview?source_landing_id=${select.value}&mode=${mode}`);
          preview.innerHTML = mode === 'replace'
            ? `Will remove <strong>${p.will_remove}</strong> existing and add <strong>${p.will_add}</strong> from ${esc(p.source.country)}.`
            : `Will add <strong>${p.will_add}</strong> testimonials from ${esc(p.source.country)}.`;
          go.disabled = p.will_add === 0;
        } catch (err) {
          preview.textContent = 'Could not preview: ' + err.message;
        }
      }
      select.onchange = refreshPreview;
      el.querySelectorAll('input[name="cp-mode"]').forEach((r) => { r.onchange = refreshPreview; });
      refreshPreview();

      go.onclick = async () => {
        go.disabled = true;
        const mode = el.querySelector('input[name="cp-mode"]:checked').value;
        try {
          await Api.post(`/api/landings/${landingId}/testimonials/copy`, { source_landing_id: parseInt(select.value, 10), mode });
          Toast.success('Testimonials copied');
          modal.hide();
          onCopied();
        } catch (err) {
          Toast.error('Could not copy: ' + err.message);
          go.disabled = false;
        }
      };
    },
  };
})();
