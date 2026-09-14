(function () {
  'use strict';
  const routes = [];
  function compile(pattern) {
    const names = [];
    const re = new RegExp('^' + pattern.replace(/\{(\w+)\}/g, (_, n) => { names.push(n); return '([^/?]+)'; }) + '$');
    return { re, names };
  }
  function parse(hash) {
    const h = hash || '#/products';
    const [path, qs] = h.split('?');
    const query = {};
    if (qs) new URLSearchParams(qs).forEach((v, k) => { query[k] = v; });
    return { path, query };
  }
  function dispatch() {
    const { path, query } = parse(location.hash);
    for (const r of routes) {
      const m = r.re.exec(path);
      if (m) {
        const params = {};
        r.names.forEach((n, i) => { params[n] = decodeURIComponent(m[i + 1]); });
        r.handler(params, query);
        return;
      }
    }
    document.getElementById('app').innerHTML = '<div class="alert alert-warning">Page not found.</div>';
  }
  window.Router = {
    register(pattern, handler) { routes.push(Object.assign(compile(pattern), { handler })); },
    navigate(hash) { if (location.hash === hash) dispatch(); else location.hash = hash; },
    start() { window.addEventListener('hashchange', dispatch); dispatch(); },
  };
})();
