(function () {
  'use strict';
  window.App = {
    el: document.getElementById('app'),
    setNav(html) { document.getElementById('nav-right').innerHTML = html; },
    onUnauthorized: null,
  };

  Router.register('#/products', function () {
    App.el.innerHTML = '<h1 class="h3">Products</h1><p class="text-muted">Product search arrives in phase 4.</p>';
  });

  Router.start();
})();
