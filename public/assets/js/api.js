(function () {
  'use strict';

  class ApiError extends Error {
    constructor(status, code, message, fields) {
      super(message);
      this.status = status;
      this.code = code;
      this.fields = fields || {};
    }
  }

  async function send(method, path, body, isForm) {
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const init = { method, headers, credentials: 'same-origin' };
    if (body !== undefined && body !== null) {
      if (isForm) {
        init.body = body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }
    // Resolve against the document location (not '/') so the app also works when deployed
    // under a sub-folder, e.g. XAMPP htdocs/testimonials-manager/public/.
    const url = new URL(path.replace(/^\//, ''), document.baseURI).toString();
    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw new ApiError(0, 'network_error', 'Network error — the server could not be reached');
    }
    if (res.status === 204) return null;
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON body */ }
    if (!res.ok) {
      const err = (json && json.error) || {};
      const ex = new ApiError(res.status, err.code || 'http_error', err.message || res.statusText, err.fields);
      if (res.status === 401 && window.App && window.App.onUnauthorized && path !== '/api/auth/me') window.App.onUnauthorized();
      throw ex;
    }
    return json;
  }

  window.ApiError = ApiError;
  window.Api = {
    get: (path) => send('GET', path),
    post: (path, body) => send('POST', path, body === undefined ? {} : body),
    patch: (path, body) => send('PATCH', path, body),
    del: (path) => send('DELETE', path),
    upload: (path, formData) => send('POST', path, formData, true),
  };
})();
