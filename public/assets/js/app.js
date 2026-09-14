(function () {
  'use strict';
  window.App = {
    el: document.getElementById('app'),
    user: null,
    setNav(html) { document.getElementById('nav-right').innerHTML = html; },
    setUser(user) {
      App.user = user;
      if (!user) { App.setNav(''); return; }
      Sync.mount();
      document.getElementById('nav-right').insertAdjacentHTML('beforeend',
        `<span class="text-white-50 small ms-2"><i class="bi bi-person-circle me-1"></i>${esc(user.display_name)}</span>
         <button id="logout-btn" class="btn btn-sm btn-outline-light ms-1">Sign out</button>`);
      document.getElementById('logout-btn').addEventListener('click', async () => {
        try { await Api.post('/api/auth/logout'); } catch (e) { /* session is gone either way */ }
        App.setUser(null);
        Router.navigate('#/login');
      });
    },
    onUnauthorized() {
      if ((location.hash || '').startsWith('#/login')) return;
      const next = location.hash || '#/products';
      App.setUser(null);
      Toast.error('Session expired — please sign in again');
      Router.navigate('#/login?next=' + encodeURIComponent(next));
    },
  };

  Router.register('#/login', (params, query) => Views.login(params, query));
  Router.register('#/products', (params, query) => Views.products(params, query));
  Router.register('#/products/{sku}', (params) => Views.countries(params));
  Router.register('#/landings/{id}', (params) => Views.testimonials(params));

  (async function boot() {
    try {
      const me = await Api.get('/api/auth/me');
      App.setUser(me.user);
      if ((location.hash || '').startsWith('#/login')) location.hash = '#/products';
    } catch (e) {
      App.setUser(null);
      if (!(location.hash || '').startsWith('#/login')) {
        location.hash = '#/login?next=' + encodeURIComponent(location.hash || '#/products');
      }
    }
    Router.start();
  })();
})();
