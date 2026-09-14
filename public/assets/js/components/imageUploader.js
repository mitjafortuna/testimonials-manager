(function () {
  'use strict';
  function thumbCard(img) {
    return `<div class="tm-img-card" data-image-id="${img.id}">
      <img src="media/${esc(img.thumb_filename)}" alt="" width="96" height="96">
      <button type="button" class="btn btn-sm btn-danger tm-img-delete" title="Delete image" aria-label="Delete image"><i class="bi bi-x-lg"></i></button>
      <small class="text-muted d-block text-truncate">${img.width}×${img.height}</small>
    </div>`;
  }

  window.ImageUploader = {
    mount(slot, testimonial) {
      if (!testimonial) {
        slot.innerHTML = '<label class="form-label">Images</label><div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Save the testimonial first, then add images.</div>';
        return;
      }
      const images = [...(testimonial.images || [])];
      slot.innerHTML = `
        <label class="form-label">Images <span class="text-muted small">(JPG, PNG, WebP · max 5 MB each)</span></label>
        <div class="tm-img-grid" id="iu-grid">${images.map(thumbCard).join('')}</div>
        <div class="tm-dropzone" id="iu-drop" tabindex="0" role="button" aria-label="Add images">
          <i class="bi bi-cloud-arrow-up fs-3 d-block"></i><span>Drop images here or <u>choose files</u></span>
          <input type="file" id="iu-input" accept="image/jpeg,image/png,image/webp" multiple hidden>
        </div>
        <div class="d-flex align-items-center gap-2 mt-1"><span id="iu-status" class="tm-save-status"></span></div>`;
      const grid = slot.querySelector('#iu-grid');
      const drop = slot.querySelector('#iu-drop');
      const input = slot.querySelector('#iu-input');
      const status = SaveStatus.bind(slot.querySelector('#iu-status'));

      async function send(fileList) {
        const files = [...fileList];
        if (!files.length) return;
        const fd = new FormData();
        files.forEach((f) => fd.append('images[]', f, f.name));
        status.saving();
        try {
          const res = await Api.upload(`/api/testimonials/${testimonial.id}/images`, fd);
          res.images.forEach((img) => { images.push(img); grid.insertAdjacentHTML('beforeend', thumbCard(img)); });
          testimonial.images = images;
          status.saved();
          Toast.success(`${res.images.length} image${res.images.length === 1 ? '' : 's'} uploaded`);
        } catch (err) {
          status.failed(err.message);
          Toast.error('Upload failed: ' + (err.fields && err.fields.images ? err.fields.images : err.message));
        } finally {
          input.value = '';
        }
      }
      drop.addEventListener('click', () => input.click());
      drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
      input.addEventListener('change', () => send(input.files));
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => send(e.dataTransfer.files));

      grid.addEventListener('click', async (e) => {
        const btn = e.target.closest('.tm-img-delete');
        if (!btn) return;
        const card = btn.closest('.tm-img-card');
        const id = parseInt(card.dataset.imageId, 10);
        const ok = await Confirm.ask({ title: 'Delete image', body: 'Remove this image from the testimonial?', confirmLabel: 'Delete' });
        if (!ok) return;
        try {
          await Api.del(`/api/images/${id}`);
          card.remove();
          const i = images.findIndex((x) => x.id === id);
          if (i >= 0) images.splice(i, 1);
          testimonial.images = images;
          Toast.success('Image deleted');
        } catch (err) {
          Toast.error('Could not delete image: ' + err.message);
        }
      });
    },
  };

  document.addEventListener('tm:testimonial-form-open', (e) => ImageUploader.mount(e.detail.slot, e.detail.testimonial));
})();
