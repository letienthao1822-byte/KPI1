(() => {
  'use strict';

  const script = document.currentScript || document.querySelector('script[src$="admin.js"]');
  const scriptPath = script ? new URL(script.src, window.location.href).pathname : '';
  const basePath = scriptPath.replace(/\/public\/assets\/js\/admin\.js$/, '').replace(/\/$/, '');
  const apiUrl = (path) => `${basePath}${path}`;
  let sessionCsrfToken = '';

  async function responsePayload(response) {
    try {
      return await response.json();
    } catch {
      return {};
    }
  }

  function showMessage(element, message, type) {
    element.textContent = message;
    element.className = `message visible ${type}`;
  }

  async function loadAdminSession() {
    const response = await fetch(apiUrl('/api/auth/check'), {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    });
    const payload = await responsePayload(response);
    if (!response.ok) {
      throw new Error(payload.message || 'Phiên đăng nhập không hợp lệ.');
    }
    if (payload.user?.role !== 'Admin') {
      throw new Error('Bạn không có quyền quản trị hệ thống.');
    }
    sessionCsrfToken = payload.csrf_token || '';
    if (!sessionCsrfToken) {
      throw new Error('Không lấy được mã bảo vệ thao tác. Hãy tải lại trang.');
    }
  }

  const now = new Date();
  const month = now.getMonth() + 1;
  const year = now.getFullYear();
  document.getElementById('current-period-label').textContent = new Intl.DateTimeFormat('vi-VN', {
    month: 'long',
    year: 'numeric',
  }).format(now);

  const criteriaForm = document.getElementById('criteria-form');
  const criteriaButton = document.getElementById('criteria-submit');
  const criteriaMessage = document.getElementById('criteria-message');
  criteriaForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    criteriaMessage.className = 'message';
    criteriaButton.disabled = true;
    criteriaButton.textContent = 'Đang lưu...';
    const formData = new FormData(criteriaForm);
    try {
      await loadAdminSession();
      const response = await fetch(apiUrl('/api/admin/criteria'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': sessionCsrfToken },
        body: JSON.stringify({
          name: String(formData.get('name') || '').trim(),
          weight: Number(formData.get('weight')),
          target_type: formData.get('target_type'),
          description: String(formData.get('description') || '').trim(),
        }),
      });
      const payload = await responsePayload(response);
      if (!response.ok) {
        throw new Error(payload.message || 'Không thể thêm tiêu chí.');
      }
      criteriaForm.reset();
      showMessage(criteriaMessage, payload.message || 'Đã thêm tiêu chí KPI.', 'success');
    } catch (error) {
      showMessage(criteriaMessage, error.message || 'Có lỗi khi thêm tiêu chí.', 'error');
    } finally {
      criteriaButton.disabled = false;
      criteriaButton.textContent = '＋ Thêm tiêu chí';
    }
  });

  const periodButton = document.getElementById('open-period-button');
  const periodMessage = document.getElementById('period-message');
  periodButton.addEventListener('click', async () => {
    periodMessage.className = 'message';
    periodButton.disabled = true;
    periodButton.textContent = 'Đang khởi tạo...';
    try {
      await loadAdminSession();
      const response = await fetch(apiUrl('/api/admin/period'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': sessionCsrfToken },
        body: JSON.stringify({ month, year }),
      });
      const payload = await responsePayload(response);
      if (!response.ok) {
        throw new Error(payload.message || 'Không thể mở kỳ đánh giá.');
      }
      const count = Number(payload.evaluations_created || 0);
      showMessage(periodMessage, `${payload.message || 'Đã khởi tạo kỳ đánh giá.'} Số phiếu nháp: ${count}.`, 'success');
    } catch (error) {
      showMessage(periodMessage, error.message || 'Có lỗi khi mở kỳ đánh giá.', 'error');
    } finally {
      periodButton.disabled = false;
      periodButton.textContent = 'Khởi tạo kỳ đánh giá tháng này';
    }
  });

  const exportButton = document.getElementById('export-report-button');
  exportButton.addEventListener('click', async () => {
    exportButton.disabled = true;
    exportButton.textContent = 'Đang tạo báo cáo...';
    try {
      const response = await fetch(apiUrl('/api/report/export'), {
        method: 'GET',
        credentials: 'include',
        headers: { Accept: 'text/csv, application/json' },
      });
      if (!response.ok) {
        const payload = await responsePayload(response);
        throw new Error(payload.message || 'Không thể xuất báo cáo KPI.');
      }

      const csvBlob = await response.blob();
      const objectUrl = URL.createObjectURL(csvBlob);
      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^";]+)"?/i);
      const filename = match ? match[1] : `Bao_Cao_KPI_${Date.now()}.csv`;
      const downloadLink = document.createElement('a');
      downloadLink.href = objectUrl;
      downloadLink.download = filename;
      document.body.appendChild(downloadLink);
      downloadLink.click();
      downloadLink.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (error) {
      window.alert(error.message || 'Có lỗi khi tải báo cáo.');
    } finally {
      exportButton.disabled = false;
      exportButton.textContent = '⇩ Xuất báo cáo CSV';
    }
  });

  loadAdminSession().catch((error) => {
    console.error(error);
  });
})();
