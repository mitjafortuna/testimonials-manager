(function () {
  'use strict';
  window.Views = window.Views || {};
  window.Views.login = function (params, query) {
    const next = query.next || '#/products';
    App.el.innerHTML = `
      <div class="row justify-content-center"><div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="card mt-5"><div class="card-body p-4">
          <h1 class="h4 mb-3">Sign in</h1>
          <form id="login-form" novalidate>
            <div class="alert alert-danger d-none" id="login-error" role="alert"></div>
            <div class="mb-3"><label class="form-label" for="login-username">Username</label><input class="form-control" id="login-username" name="username" autocomplete="username" autofocus required><div class="invalid-feedback"></div></div>
            <div class="mb-3"><label class="form-label" for="login-password">Password</label><input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required><div class="invalid-feedback"></div></div>
            <button class="btn btn-primary w-100" type="submit" id="login-submit">Sign in</button>
          </form>
          <p class="text-muted small mt-3 mb-0">Demo credentials: <code>admin</code> / <code>admin123</code></p>
        </div></div>
      </div></div>`;
    const form = document.getElementById('login-form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('login-submit');
      const errBox = document.getElementById('login-error');
      errBox.classList.add('d-none');
      form.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Signing in…';
      try {
        const res = await Api.post('/api/auth/login', { username: form.username.value, password: form.password.value });
        App.setUser(res.user);
        Router.navigate(next);
      } catch (err) {
        if (err.status === 422) {
          for (const [name, msg] of Object.entries(err.fields)) {
            const input = form[name];
            if (!input) continue;
            input.classList.add('is-invalid');
            input.parentElement.querySelector('.invalid-feedback').textContent = msg;
          }
        } else {
          errBox.textContent = err.status === 401 ? 'Invalid username or password.' : 'Could not sign in: ' + err.message;
          errBox.classList.remove('d-none');
        }
      } finally {
        btn.disabled = false;
        btn.textContent = 'Sign in';
      }
    });
  };
})();
