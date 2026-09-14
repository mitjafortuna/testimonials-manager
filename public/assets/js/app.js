(function () {
  'use strict';
  window.App = {
    el: document.getElementById('app'),
    setNav(html) { document.getElementById('nav-right').innerHTML = html; },
    onUnauthorized: null,
  };

  Router.register('#/products', (params, query) => Views.products(params, query));
  Router.register('#/products/{sku}', (params) => Views.countries(params));

  Sync.mount();
  Router.start();
})();
