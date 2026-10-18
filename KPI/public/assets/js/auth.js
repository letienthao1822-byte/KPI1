(() => {
  'use strict';

  const script = document.currentScript || document.querySelector('script[src$="auth.js"]');
  const scriptPath = script ? new URL(script.src, window.location.href).pathname : '';
  const basePath = scriptPath.replace(/\/public\/assets\/js\/auth\.js$/, '').replace(/\/$/, '');
  const apiUrl = (path) => `${basePath}${path}`;

  async function readJson(response) {
    try {
      return await response.json();
    } catch {
      return {};
    }
  }

  async function checkAuth() {
    try {
      const response = await fetch(apiUrl('/api/auth/check'), {
        method: 'GET',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      if (response.status === 401) {
        window.location.replace(apiUrl('/public/login.html'));
        return false;
      }
      if (!response.ok) {
        throw new Error('Không thể xác thực phiên đăng nhập.');
      }
      const result = await readJson(response);
      const requiredRole = document.body.dataset.requiredRole;
      if (requiredRole && result.user?.role !== requiredRole) {
        const destinationByRole = {
          Admin: '/public/admin-dashboard.html',
          Manager: '/manager-dashboard.html',
          Employee: '/employee-kpi.html',
        };
        window.location.replace(apiUrl(destinationByRole[result.user?.role] || '/public/login.html'));
        return false;
      }
      return true;
    } catch (error) {
      console.error(error);
      return false;
    }
  }

  window.checkAuth = checkAuth;

  const form = document.getElementById('login-form');
  if (form) {
    const errorBox = document.getElementById('login-error');
    const submitButton = document.getElementById('login-submit');

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      errorBox.classList.remove('visible');
      submitButton.disabled = true;
      submitButton.textContent = 'Đang đăng nhập...';

      const formData = new FormData(form);
      try {
        const response = await fetch(apiUrl('/api/auth/login'), {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({
            username: formData.get('username'),
            password: formData.get('password'),
          }),
        });
        const result = await readJson(response);
        if (!response.ok) {
          throw new Error(result.message || 'Sai tài khoản hoặc mật khẩu.');
        }

        const role = result.user?.role || result.data?.user?.role;
        const destinations = {
          Admin: '/public/admin-dashboard.html',
          Manager: '/manager-dashboard.html',
          Employee: '/employee-kpi.html',
        };
        if (!destinations[role]) {
          throw new Error('Tài khoản chưa được gán vai trò truy cập hợp lệ.');
        }
        window.location.assign(apiUrl(destinations[role]));
      } catch (error) {
        errorBox.textContent = error.message || 'Đăng nhập thất bại. Vui lòng thử lại.';
        errorBox.classList.add('visible');
        submitButton.disabled = false;
        submitButton.textContent = 'Đăng nhập';
      }
    });
  }

  if (document.body.dataset.authGuard === 'true') {
    checkAuth();
  }
})();
