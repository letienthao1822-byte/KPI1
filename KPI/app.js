function notifySelfEvaluation(message) {
  const toast = document.getElementById('toast');
  if (toast) {
    toast.textContent = message;
    toast.classList.add('visible');
    window.clearTimeout(toast._selfEvaluationTimeout);
    toast._selfEvaluationTimeout = window.setTimeout(() => toast.classList.remove('visible'), 3500);
  } else {
    window.alert(message);
  }
}

function selfEvaluationEndpoint(form) {
  if (form.dataset.apiUrl) return form.dataset.apiUrl;
  if (form.getAttribute('action') && form.getAttribute('action') !== '#') return form.action;

  const appScript = [...document.scripts].find(script => /(?:^|\/)app\.js(?:\?|$)/.test(script.src));
  const appRoot = appScript ? new URL('.', appScript.src) : new URL('.', window.location.href);
  return new URL('evaluations/self', appRoot).toString();
}

async function submitSelfEvaluation(form, submitter) {
  const submitButton = submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement
    ? submitter
    : form.querySelector('[type="submit"]');
  const originalLabel = submitButton?.tagName === 'INPUT' ? submitButton.value : submitButton?.textContent;
  const formData = new FormData(form);

  form.querySelectorAll('[data-self-score][data-criteria-id]').forEach(input => {
    const key = `self_scores[${input.dataset.criteriaId}]`;
    if (!formData.has(key)) formData.append(key, input.value);
  });
  form.querySelectorAll('[data-evidence][data-criteria-id]').forEach(input => {
    const key = `evidences[${input.dataset.criteriaId}]`;
    const file = input.files?.[0];
    if (file && !formData.has(key)) formData.append(key, file);
  });

  const csrfToken = formData.get('_token') || document.querySelector('meta[name="csrf-token"]')?.content;
  if (csrfToken && !formData.has('_token')) formData.append('_token', csrfToken);

  if (submitButton) {
    submitButton.disabled = true;
    if (submitButton.tagName === 'INPUT') submitButton.value = 'Đang xử lý...';
    else submitButton.textContent = 'Đang xử lý...';
  }

  try {
    const response = await fetch(selfEvaluationEndpoint(form), {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.success !== true) {
      throw new Error(result.message || `Gửi phiếu thất bại (HTTP ${response.status}).`);
    }

    form.reset();
    const status = form.querySelector('[data-form-status]');
    if (status) {
      status.textContent = result.message || 'Đã gửi phiếu tự đánh giá thành công.';
      status.hidden = false;
    }
    notifySelfEvaluation(result.message || 'Đã gửi phiếu tự đánh giá thành công.');
    form.dispatchEvent(new CustomEvent('self-evaluation:success', { bubbles: true, detail: result }));
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Không thể gửi phiếu tự đánh giá.';
    const status = form.querySelector('[data-form-status]');
    if (status) {
      status.textContent = message;
      status.hidden = false;
    }
    notifySelfEvaluation(message);
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      if (submitButton.tagName === 'INPUT') submitButton.value = originalLabel || 'Gửi tự đánh giá';
      else submitButton.textContent = originalLabel || 'Gửi tự đánh giá';
    }
  }
}

document.addEventListener('submit', event => {
  const form = event.target.closest('[data-self-evaluation-form]');
  if (!form) return;
  event.preventDefault();
  submitSelfEvaluation(form, event.submitter);
});

let pendingEvaluationDetails = new Map();

function escapeApiHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[character]);
}

function approvalApiRoot() {
  return document.querySelector('[data-approval-api]')?.dataset.approvalApi || '/api/evaluate';
}

async function loadPendingEvaluations() {
  const body = document.getElementById('pending-evaluations-body');
  if (!body) return;
  body.innerHTML = '<tr><td colspan="4">Đang tải danh sách...</td></tr>';

  try {
    const response = await fetch(`${approvalApiRoot()}/pending`, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.success !== true) {
      throw new Error(result.message || `Không tải được danh sách (HTTP ${response.status}).`);
    }

    const evaluations = Array.isArray(result.data) ? result.data : [];
    pendingEvaluationDetails = new Map(evaluations.map(item => [String(item.evaluation_id), item.details || []]));
    if (evaluations.length === 0) {
      body.innerHTML = '<tr><td colspan="4">Không có phiếu nào đang chờ duyệt.</td></tr>';
      return;
    }

    body.innerHTML = evaluations.map(item => {
      const period = `${item.period_start || ''} – ${item.period_end || ''}`;
      const score = item.self_score === null || item.self_score === undefined
        ? 'Chưa có điểm'
        : `${escapeApiHtml(Number(item.self_score).toLocaleString('vi-VN', { maximumFractionDigits: 2 }))} / 100`;
      return `<tr id="evaluation-row-${escapeApiHtml(item.evaluation_id)}">
        <td><strong>${escapeApiHtml(item.full_name)}</strong><small>${escapeApiHtml(item.employee_code)} · ${escapeApiHtml(item.position_title)}</small></td>
        <td>${escapeApiHtml(period)}</td>
        <td>${score}</td>
        <td><button type="button" class="button button-primary" data-open-approval="${escapeApiHtml(item.evaluation_id)}">Duyệt</button></td>
      </tr>`;
    }).join('');
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Không thể tải danh sách phiếu.';
    body.innerHTML = `<tr><td colspan="4" class="approval-error">${escapeApiHtml(message)}</td></tr>`;
  }
}

function openApprovalModal(evaluationId, detailsData) {
  const modal = document.getElementById('approval-modal');
  const form = document.getElementById('approval-form');
  const fields = document.getElementById('approval-details');
  if (!modal || !form || !fields) return;

  const details = Array.isArray(detailsData) ? detailsData : (detailsData?.details || []);
  if (details.length === 0) {
    notifySelfEvaluation('Phiếu này chưa có chi tiết tiêu chí để chấm.');
    return;
  }

  form.dataset.evaluationId = String(evaluationId);
  const baseScore = (detail, key) => {
    const number = Number(detail[key]);
    return Number.isFinite(number) ? number : '';
  };
  fields.innerHTML = details.map(detail => {
    const criterionId = escapeApiHtml(detail.criteria_id);
    const maxScore = Math.min(100, Number(detail.max_score) || 100);
    const evidence = detail.evidence_path
      ? `<a href="${escapeApiHtml(detail.evidence_path)}" target="_blank" rel="noopener noreferrer">Xem minh chứng</a>`
      : '<span class="muted">Không có file minh chứng</span>';
    return `<section class="approval-detail">
      <div><strong>${escapeApiHtml(detail.criterion_name)}</strong><small>Điểm tự chấm: ${escapeApiHtml(baseScore(detail, 'self_score'))} · Trọng số ${escapeApiHtml(detail.weight)}%</small>${evidence}</div>
      <label>Điểm quản lý<input type="number" name="manager_score_${criterionId}" data-manager-score data-criteria-id="${criterionId}" min="0" max="${maxScore}" step="0.01" required></label>
    </section>`;
  }).join('');
  const status = form.querySelector('[data-approval-status]');
  if (status) { status.hidden = true; status.textContent = ''; }
  modal.hidden = false;
  fields.querySelector('input')?.focus();
}

async function submitApproval(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const modal = document.getElementById('approval-modal');
  const status = form.querySelector('[data-approval-status]');
  const button = form.querySelector('[type="submit"]');
  const originalText = button?.textContent;
  const scores = [...form.querySelectorAll('[data-manager-score]')].map(input => ({
    criteria_id: Number(input.dataset.criteriaId),
    manager_score: Number(input.value)
  }));

  if (!form.reportValidity()) return;
  if (scores.some(item => !Number.isInteger(item.criteria_id) || !Number.isFinite(item.manager_score) || item.manager_score < 0 || item.manager_score > 100)) {
    if (status) { status.textContent = 'Điểm phải nằm trong khoảng từ 0 đến 100.'; status.hidden = false; }
    return;
  }

  if (button) { button.disabled = true; button.textContent = 'Đang xử lý...'; }
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(`${approvalApiRoot()}/approve`, {
      method: 'PUT',
      credentials: 'include',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ evaluation_id: Number(form.dataset.evaluationId), scores, _token: csrfToken })
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.success !== true) {
      throw new Error(result.message || `Duyệt phiếu thất bại (HTTP ${response.status}).`);
    }

    document.getElementById(`evaluation-row-${String(form.dataset.evaluationId)}`)?.remove();
    pendingEvaluationDetails.delete(String(form.dataset.evaluationId));
    modal.hidden = true;
    notifySelfEvaluation(result.message || 'Đã duyệt phiếu KPI thành công.');
    const body = document.getElementById('pending-evaluations-body');
    if (body && !body.querySelector('tr')) body.innerHTML = '<tr><td colspan="4">Không có phiếu nào đang chờ duyệt.</td></tr>';
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Không thể duyệt phiếu.';
    if (status) { status.textContent = message; status.hidden = false; }
    else notifySelfEvaluation(message);
  } finally {
    if (button) { button.disabled = false; button.textContent = originalText || 'Xác nhận duyệt'; }
  }
}

document.addEventListener('click', event => {
  const approveButton = event.target.closest('[data-open-approval]');
  if (approveButton) {
    const evaluationId = approveButton.dataset.openApproval;
    openApprovalModal(evaluationId, pendingEvaluationDetails.get(String(evaluationId)) || []);
    return;
  }
  if (event.target.closest('[data-close-approval]')) {
    const modal = document.getElementById('approval-modal');
    if (modal) modal.hidden = true;
  }
});

document.addEventListener('submit', event => {
  if (event.target.matches('#approval-form')) submitApproval(event);
});

if (document.getElementById('pending-evaluations-body')) loadPendingEvaluations();

if (document.getElementById('page-content')) {
const pageContent = document.getElementById('page-content');
const breadcrumbCurrent = document.getElementById('breadcrumb-current');
const toast = document.getElementById('toast');
const modalBackdrop = document.getElementById('modal-backdrop');
const storageKey = 'kpi-portal-data-v1';
const seedOrganizations = [
  { initials:'EVN', name:'Tập đoàn Điện lực Việt Nam', type:'Doanh nghiệp nhà nước', score:92, target:'18/20', color:'#e9efff', ink:'#456bd1', status:'Đạt tốt' },
  { initials:'VN', name:'Tổng công ty Hàng không VN', type:'Doanh nghiệp nhà nước', score:86, target:'16/19', color:'#fff1e8', ink:'#d27b45', status:'Đạt' },
  { initials:'BH', name:'Bảo hiểm Xã hội Việt Nam', type:'Đơn vị sự nghiệp', score:89, target:'17/19', color:'#e9f7f0', ink:'#3e9b72', status:'Đạt' },
  { initials:'TCT', name:'Tổng công ty Đường sắt VN', type:'Doanh nghiệp nhà nước', score:74, target:'13/18', color:'#f1edff', ink:'#8166c7', status:'Cần cải thiện' },
  { initials:'TCHQ', name:'Tổng cục Hải quan', type:'Cơ quan hành chính', score:94, target:'19/20', color:'#fff4e4', ink:'#cf963c', status:'Đạt tốt' }
];
const seedKpis = [
  { name:'Tỷ lệ hoàn thành kế hoạch sản xuất', category:'Tài chính', unit:'%', target:'≥ 95%', owner:'Khối doanh nghiệp', weight:'25%', icon:'↗', color:'blue' },
  { name:'Hiệu quả sử dụng vốn nhà nước', category:'Tài chính', unit:'ROE (%)', target:'≥ 10%', owner:'Khối doanh nghiệp', weight:'20%', icon:'◈', color:'green' },
  { name:'Tỷ lệ hồ sơ giải quyết đúng hạn', category:'Dịch vụ công', unit:'%', target:'≥ 98%', owner:'Cơ quan hành chính', weight:'20%', icon:'◷', color:'amber' },
  { name:'Mức độ hài lòng của người dân', category:'Chất lượng', unit:'Điểm / 100', target:'≥ 90', owner:'Cơ quan hành chính', weight:'15%', icon:'♡', color:'purple' },
  { name:'Tiến độ thực hiện nhiệm vụ trọng tâm', category:'Chiến lược', unit:'%', target:'100%', owner:'Tất cả đơn vị', weight:'20%', icon:'◎', color:'blue' },
  { name:'Tỷ lệ nhiệm vụ hoàn thành đúng hạn', category:'Tiến độ', unit:'%', target:'≥ 95%', owner:'Cán bộ, công chức', weight:'20%', icon:'✓', color:'green' }
];
const seedStaff = [
  { initials:'TL', name:'Trần Minh Tuấn', sub:'Chuyên viên chính · Vụ Tổng hợp', department:'Vụ Tổng hợp', score:96, progress:100, status:'Đã hoàn thành', color:'#e9efff', ink:'#5273d1' },
  { initials:'NA', name:'Nguyễn Thu An', sub:'Chuyên viên · Vụ Kinh tế ngành', department:'Vụ Kinh tế ngành', score:88, progress:82, status:'Đang đánh giá', color:'#fceee8', ink:'#cc7853' },
  { initials:'LH', name:'Lê Quốc Huy', sub:'Trưởng phòng · Vụ Tổ chức cán bộ', department:'Vụ Tổ chức cán bộ', score:92, progress:100, status:'Đã hoàn thành', color:'#e7f5ef', ink:'#3d9b75' },
  { initials:'PM', name:'Phạm Ngọc Mai', sub:'Chuyên viên · Văn phòng Bộ', department:'Văn phòng Bộ', score:0, progress:48, status:'Chờ tự đánh giá', color:'#f1edff', ink:'#856dc2' }
];
function readSavedState() {
  try {
    const value = JSON.parse(localStorage.getItem(storageKey) || '{}');
    return value && typeof value === 'object' ? value : {};
  } catch (error) {
    console.error('Không thể đọc dữ liệu KPI đã lưu.', error);
    return {};
  }
}
const savedState = readSavedState();
let organizations = Array.isArray(savedState.organizations) ? savedState.organizations : seedOrganizations;
let kpis = Array.isArray(savedState.kpis) ? savedState.kpis : seedKpis;
let staff = Array.isArray(savedState.staff) ? savedState.staff : seedStaff;
let assessments = Array.isArray(savedState.assessments) ? savedState.assessments : [];
let preferences = savedState.preferences && typeof savedState.preferences === 'object' ? savedState.preferences : {};
organizations = organizations.map(item => ({ ...item, id:item.id || makeId() }));
kpis = kpis.map(item => ({ ...item, id:item.id || makeId() }));
staff = staff.map(item => ({ ...item, id:item.id || makeId() }));
const quarterNames=['I','II','III','IV'];
function periodForDate(date) {
  return `Quý ${quarterNames[Math.floor(date.getMonth()/3)]} · ${date.getFullYear()}`;
}
const defaultPeriod=periodForDate(new Date());
const previousQuarterDate=new Date(new Date().getFullYear(),new Date().getMonth()-3,1);
const periodOptions=[...new Set([defaultPeriod,periodForDate(previousQuarterDate),`Năm ${new Date().getFullYear()-1}`,preferences.period,...assessments.map(item=>item.period)].filter(Boolean))];
const routeLabels = { dashboard:'Tổng quan', kpis:'Chỉ tiêu KPI', organizations:'Đơn vị & doanh nghiệp', staff:'Cán bộ', assessments:'Kỳ đánh giá', reports:'Báo cáo & phân tích', settings:'Cài đặt', help:'Trợ giúp' };
let currentAssessmentTab = 'organizations';
let toastTimeout;

function persistState() {
  try {
    localStorage.setItem(storageKey, JSON.stringify({ organizations, kpis, staff, assessments, preferences }));
    return true;
  } catch (error) {
    console.error('Không thể lưu dữ liệu KPI vào trình duyệt.', error);
    showToast('Không lưu được dữ liệu. Hãy kiểm tra dung lượng lưu trữ của trình duyệt.');
    return false;
  }
}
function escapeHTML(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[character]);
}
function makeId() {
  return crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
function initialsFromName(name) {
  return String(name).trim().split(/\s+/).slice(-2).map(part => part[0]).join('').toLocaleUpperCase('vi') || 'DV';
}
function recordFor(type, id, period=preferences.period||defaultPeriod) {
  return assessments.find(record => record.subjectType === type && record.subjectId === id && record.period === period);
}
function createAssessmentStatus(type, id) {
  const record = recordFor(type, id);
  return record?.status || (type === 'staff' ? 'Chờ tự đánh giá' : 'Chưa đánh giá');
}
function persistAndRender(message, route) {
  if (!persistState()) return;
  closeModal();
  renderRoute(route || currentRoute);
  showToast(message);
}
let currentRoute = 'dashboard';
function heading(title, subtitle, actions = '') {
  return `<div class="page-heading"><div><h1>${title}</h1><p>${subtitle}</p></div><div class="heading-actions">${actions}</div></div>`;
}
function periodSelector() {
  const selected = preferences.period || defaultPeriod;
  return `<label class="period-control"><span>Kỳ đánh giá</span><select class="select-control period-select" aria-label="Chọn kỳ đánh giá">${periodOptions.map(period=>`<option ${period===selected?'selected':''}>${period}</option>`).join('')}</select></label>`;
}
function button(label, options = {}) {
  const cls = options.primary ? 'button button-primary' : 'button button-secondary';
  const action = options.action ? `data-action="${options.action}"` : '';
  const icon = options.icon ? `<span class="${options.icon === '+' ? 'plus' : ''}">${options.icon}</span>` : '';
  return `<button class="${cls}" ${action}>${icon}${label}</button>`;
}
function statusPill(status) {
  const kind = status === 'Đạt tốt' || status === 'Đã hoàn thành' ? 'good' :
    status === 'Đang đánh giá' || status === 'Cần cải thiện' || status === 'Chờ phê duyệt' ? 'review' :
      status === 'Chờ tự đánh giá' ? 'late' : 'progress';
  return `<span class="status-pill ${kind}">${escapeHTML(status)}</span>`;
}
function avatar(initials, color, ink) {
  return `<span class="avatar" style="background:${escapeHTML(color)};color:${escapeHTML(ink)}">${escapeHTML(initials)}</span>`;
}
function organizationStatus(item) {
  const record=recordFor('organization',item.id);
  if(!record)return item.status||'Chưa đánh giá';
  if(record.status!=='Đã hoàn thành')return record.status;
  return Number(record.score)>=90?'Đạt tốt':Number(record.score)>=80?'Đạt':'Cần cải thiện';
}
function organizationClassification(item) {
  const record=recordFor('organization',item.id);
  if(record&&record.status!=='Đã hoàn thành')return 'Chưa đánh giá';
  return organizationStatus(item);
}
function evaluationMetrics() {
  const organizationsWithScores=organizations.map(item=>Number(recordFor('organization',item.id)?.score??item.score)).filter(score=>Number.isFinite(score)&&score>0);
  const staffWithScores=staff.map(item=>Number(recordFor('staff',item.id)?.score??item.score)).filter(score=>Number.isFinite(score)&&score>0);
  const records=assessments.filter(item=>item.period===(preferences.period||defaultPeriod));
  const completed=[...organizations,...staff].filter(item=>{
    const type=organizations.includes(item)?'organization':'staff';
    const record=recordFor(type,item.id);
    return record?.status==='Đã hoàn thành'||(!record&&item.status==='Đã hoàn thành')||(!record&&type==='organization'&&['Đạt','Đạt tốt','Cần cải thiện'].includes(item.status));
  }).length;
  const total=organizations.length+staff.length;
  const scores=[...organizationsWithScores,...staffWithScores];
  return { totalOrganizations:organizations.length,totalStaff:staff.length,totalKPIs:kpis.length,completion:total?Math.round(completed/total*100):0,average:scores.length?scores.reduce((sum,value)=>sum+value,0)/scores.length:0,pending:records.filter(item=>item.status==='Chờ phê duyệt').length,inProgress:records.filter(item=>item.status==='Đang đánh giá'||item.status==='Chờ tự đánh giá').length,completed };
}
function organizationsTable(rows = organizations.slice(0, 4)) {
  const body = rows.map(item => {
    const assessment = recordFor('organization',item.id);
    const status = organizationStatus(item);
    const score = assessment?.score ?? item.score ?? 0;
    return `<tr data-entity-kind="organization" data-entity-id="${escapeHTML(item.id)}">
    <td><div class="entity-cell">${avatar(item.initials,item.color,item.ink)}<span class="entity-title"><strong>${escapeHTML(item.name)}</strong><small>${escapeHTML(item.type)}</small></span></div></td>
    <td>${escapeHTML(item.target || '0/0')} chỉ tiêu</td>
    <td><div class="progress-cell"><div class="progress-track"><div class="progress-fill" style="width:${Math.max(0,Math.min(100,Number(score)||0))}%"></div></div><span class="progress-label">${escapeHTML(score)}%</span></div></td>
    <td>${statusPill(status)}</td><td><button class="row-menu" aria-label="Sửa ${escapeHTML(item.name)}">···</button></td>
  </tr>`}).join('');
  return `<div class="table-scroll"><table class="data-table"><thead><tr><th>ĐƠN VỊ</th><th>CHỈ TIÊU</th><th>TIẾN ĐỘ</th><th>XẾP LOẠI</th><th></th></tr></thead><tbody>${body || '<tr><td colspan="5"><div class="empty-state">Không tìm thấy đơn vị phù hợp.</div></td></tr>'}</tbody></table></div>`;
}
function kpiRows(items) {
  return items.map(item=>`<tr data-entity-kind="kpi" data-entity-id="${escapeHTML(item.id)}"><td><div class="entity-cell"><span class="metric-icon ${escapeHTML(item.color || 'blue')}">${escapeHTML(item.icon || '◎')}</span><span class="entity-title"><strong>${escapeHTML(item.name)}</strong><small>${escapeHTML(item.category)} · ${escapeHTML(item.unit)}</small></span></div></td><td>${escapeHTML(item.target)}</td><td>${escapeHTML(String(item.weight).replace('%',''))}%</td><td>${escapeHTML(item.owner)}</td><td>${statusPill('Đang áp dụng')}</td><td><button class="row-menu" aria-label="Sửa ${escapeHTML(item.name)}">···</button></td></tr>`).join('') || '<tr><td colspan="6"><div class="empty-state">Không tìm thấy chỉ tiêu phù hợp.</div></td></tr>';
}
function staffTable(list = staff) {
  return `<div class="table-scroll"><table class="data-table"><thead><tr><th>CÁN BỘ, CÔNG CHỨC, VIÊN CHỨC</th><th>ĐƠN VỊ</th><th>TIẾN ĐỘ</th><th>ĐIỂM</th><th>TRẠNG THÁI</th><th></th></tr></thead><tbody>${list.map(item=>{const record=recordFor('staff',item.id);const score=record?.score??(item.score||null);const progress=record?record.status==='Đã hoàn thành'?100:record.status==='Đang đánh giá'?75:35:item.progress;const status=record?.status||item.status||'Chờ tự đánh giá';return `<tr data-entity-kind="staff" data-entity-id="${escapeHTML(item.id)}"><td><div class="entity-cell">${avatar(item.initials,item.color,item.ink)}<span class="entity-title"><strong>${escapeHTML(item.name)}</strong><small>${escapeHTML(item.sub || item.title || '')}</small></span></div></td><td>${escapeHTML(item.department)}</td><td><div class="progress-cell"><div class="progress-track"><div class="progress-fill" style="width:${Math.max(0,Math.min(100,Number(progress)||0))}%"></div></div><span class="progress-label">${progress}%</span></div></td><td><strong class="score">${score??'—'}</strong></td><td>${statusPill(status)}</td><td><button class="row-menu" aria-label="Sửa hoặc đánh giá ${escapeHTML(item.name)}">···</button></td></tr>`}).join('')||'<tr><td colspan="6"><div class="empty-state">Chưa có cán bộ. Hãy thêm cán bộ để bắt đầu.</div></td></tr>'}</tbody></table></div>`;
}
function dashboardPage() {
  const metrics=evaluationMetrics();
  const classification=['Đạt tốt','Đạt','Cần cải thiện','Chưa đánh giá'].map(status=>organizations.filter(item=>organizationClassification(item)===status).length);
  const orgTotal=Math.max(organizations.length,1);
  const a=classification[0]/orgTotal*100;const b=(classification[0]+classification[1])/orgTotal*100;const c=(classification[0]+classification[1]+classification[2])/orgTotal*100;
  const donut=`conic-gradient(#3868e8 0 ${a}%,#46b98f ${a}% ${b}%,#f1ad4a ${b}% ${c}%,#e9edf4 ${c}% 100%)`;
  const chartScores=organizations.map(item=>({item,record:recordFor('organization',item.id)})).filter(({record})=>record?.score!==undefined&&record?.score!==null&&record.score!=='').map(({item,record})=>({item,score:Math.max(0,Math.min(100,Number(record.score)||0))}));
  const chartPoints=chartScores.map(({item,score},index)=>({item,score,x:chartScores.length===1?300:55+index*500/(chartScores.length-1),y:144-score*1.2}));
  const chartPath=chartPoints.map((point,index)=>`${index?'L':'M'}${point.x} ${point.y}`).join(' ');
  const chartArea=chartPoints.length?`${chartPath} L${chartPoints[chartPoints.length-1].x} 154 L${chartPoints[0].x} 154 Z`:'';
  const organizationAverage=chartScores.length?chartScores.reduce((sum,point)=>sum+point.score,0)/chartScores.length:0;
  return `${heading('Tổng quan','Theo dõi hiệu suất và kết quả đánh giá của toàn tổ chức.',`${periodSelector()}${button('Xuất báo cáo',{action:'export',icon:'↥'})}`)}
  <div class="demo-notice"><strong>Bản demo:</strong> tên đơn vị, điểm số và kết quả ban đầu là dữ liệu minh họa; thao tác được lưu trên trình duyệt hiện tại, chưa phải dữ liệu chính thức.</div>
  <div class="metrics-grid">
    <article class="metric-card"><div class="metric-top"><span class="metric-label">Tổng số đơn vị</span><span class="metric-icon blue">▤</span></div><div class="metric-value">${metrics.totalOrganizations}</div><div class="metric-bottom"><span class="metric-change neutral">Trong danh sách quản lý</span></div></article>
    <article class="metric-card"><div class="metric-top"><span class="metric-label">Đánh giá hoàn thành</span><span class="metric-icon green">✓</span></div><div class="metric-value">${metrics.completion}%</div><div class="metric-bottom"><span class="metric-change neutral">${metrics.completed} / ${metrics.totalOrganizations+metrics.totalStaff} đối tượng</span></div></article>
    <article class="metric-card"><div class="metric-top"><span class="metric-label">Điểm đánh giá trung bình</span><span class="metric-icon amber">◎</span></div><div class="metric-value">${metrics.average.toLocaleString('vi-VN',{maximumFractionDigits:1})}<span style="font-size:13px;color:#8290a5">/100</span></div><div class="metric-bottom"><span class="metric-change neutral">Từ dữ liệu có điểm</span></div></article>
    <article class="metric-card"><div class="metric-top"><span class="metric-label">Chờ phê duyệt</span><span class="metric-icon purple">◷</span></div><div class="metric-value">${metrics.pending}</div><div class="metric-bottom"><span class="metric-change neutral">Phiếu đánh giá trong kỳ</span></div></article>
  </div>
  <div class="dashboard-grid">
    <section class="panel"><div class="panel-heading"><div><h2>Điểm đánh giá theo đơn vị</h2><p>Điểm tổng hợp trong kỳ hiện tại · thang 100</p></div></div>
      <div class="chart-legend"><span class="legend-item"><i class="legend-dot"></i>Điểm đơn vị</span><span class="legend-item"><i class="legend-dot secondary"></i>Mốc 80 điểm</span></div>
      <div class="chart-wrap"><svg viewBox="0 0 600 190" role="img" aria-label="Biểu đồ điểm đánh giá hiện tại theo từng đơn vị"><line class="chart-gridline" x1="42" y1="24" x2="590" y2="24"/><line class="chart-gridline" x1="42" y1="48" x2="590" y2="48"/><line class="chart-gridline" x1="42" y1="72" x2="590" y2="72"/><line class="chart-gridline" x1="42" y1="96" x2="590" y2="96"/><line class="chart-gridline" x1="42" y1="120" x2="590" y2="120"/><line class="chart-gridline" x1="42" y1="144" x2="590" y2="144"/>
      <text class="chart-label" x="5" y="27">100</text><text class="chart-label" x="12" y="51">80</text><text class="chart-label" x="12" y="75">60</text><text class="chart-label" x="12" y="99">40</text><text class="chart-label" x="12" y="123">20</text><text class="chart-label" x="20" y="147">0</text>
      <path class="chart-line-secondary" d="M42 48 L590 48" stroke-dasharray="4 4"/><path class="chart-area" d="${chartArea}"/><path class="chart-line" d="${chartPath}"/>
      ${chartPoints.map(point=>`<circle class="chart-point" cx="${point.x}" cy="${point.y}" r="3"><title>${escapeHTML(point.item.name)}: ${point.score}/100</title></circle><text class="chart-label" text-anchor="middle" x="${point.x}" y="174">${escapeHTML(point.item.initials)}</text>`).join('')||'<text class="chart-label" x="205" y="90">Chưa có điểm đánh giá trong kỳ này</text>'}</svg><div class="chart-tooltip">Điểm trung bình<strong>${chartScores.length?`${organizationAverage.toLocaleString('vi-VN',{maximumFractionDigits:1})} / 100`:'—'}</strong></div></div>
    </section>
    <section class="panel"><div class="panel-heading"><div><h2>Tổng quan xếp loại</h2><p>Kết quả đánh giá các đơn vị</p></div><button class="text-link" data-route="reports">Chi tiết →</button></div>
      <div class="donut-area"><div class="donut" style="background:${donut}" role="img" aria-label="Biểu đồ xếp loại của ${organizations.length} đơn vị"><div class="donut-center"><strong>${organizations.length}</strong><span>đơn vị</span></div></div><div class="status-legend"><div class="status-row"><i class="legend-dot"></i>Đạt tốt <b>${classification[0]}</b></div><div class="status-row"><i class="legend-dot" style="background:#46b98f"></i>Đạt <b>${classification[1]}</b></div><div class="status-row"><i class="legend-dot" style="background:#f1ad4a"></i>Cần cải thiện <b>${classification[2]}</b></div><div class="status-row"><i class="legend-dot" style="background:#e9edf4"></i>Chưa đánh giá <b>${classification[3]}</b></div></div></div>
    </section>
  </div>
  <section class="panel table-panel"><div class="panel-heading"><div><h2>Hiệu suất đơn vị</h2><p>Theo dõi kết quả KPI của các đơn vị trong kỳ</p></div><div class="table-tools"><input class="table-search" type="search" placeholder="Tìm đơn vị..." aria-label="Tìm đơn vị"><button class="button filter-button" data-action="filter">☷ Bộ lọc</button><button class="text-link" data-route="organizations">Xem tất cả →</button></div></div><div id="organization-table">${organizationsTable()}</div><div class="table-footer"><span>Hiển thị ${Math.min(4,organizations.length)} trên ${organizations.length} đơn vị</span></div></section>`;
}
function kpisPage() {
  const rows = kpiRows(kpis);
  const categories=new Set(kpis.map(item=>item.category)).size;
  return `${heading('Chỉ tiêu KPI','Xây dựng, phân bổ và theo dõi bộ chỉ tiêu hiệu suất.',`${periodSelector()}${button('Tạo chỉ tiêu',{primary:true,action:'new-kpi',icon:'+'})}`)}
  <div class="metrics-grid"><article class="metric-card"><div class="metric-top"><span class="metric-label">Tổng chỉ tiêu</span><span class="metric-icon blue">◫</span></div><div class="metric-value">${kpis.length}</div><div class="metric-bottom"><span class="metric-change neutral">Trong danh mục này</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đang áp dụng</span><span class="metric-icon green">✓</span></div><div class="metric-value">${kpis.length}</div><div class="metric-bottom"><span class="metric-change neutral">Có thể chỉnh sửa theo kỳ</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Chờ phê duyệt</span><span class="metric-icon amber">◷</span></div><div class="metric-value">0</div><div class="metric-bottom"><span class="metric-change neutral">Chưa có chỉ tiêu chờ duyệt</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Nhóm chỉ tiêu</span><span class="metric-icon purple">▦</span></div><div class="metric-value">${categories}</div><div class="metric-bottom"><span class="metric-change neutral">Nhóm có phát sinh KPI</span></div></article></div>
  <div class="panel table-panel"><div class="panel-heading"><div><h2>Danh sách chỉ tiêu</h2><p>Quản lý danh mục KPI đang được sử dụng</p></div><div class="table-tools"><input class="table-search" type="search" placeholder="Tìm chỉ tiêu..." aria-label="Tìm chỉ tiêu"><select class="select-control" id="kpi-category"><option value="">Tất cả nhóm</option><option>Tài chính</option><option>Dịch vụ công</option><option>Chất lượng</option><option>Chiến lược</option><option>Tiến độ</option></select></div></div><div class="table-scroll"><table class="data-table"><thead><tr><th>TÊN CHỈ TIÊU</th><th>MỤC TIÊU</th><th>TRỌNG SỐ</th><th>ĐỐI TƯỢNG</th><th>TRẠNG THÁI</th><th></th></tr></thead><tbody id="kpi-table-body">${rows}</tbody></table></div><div class="table-footer"><span>Hiển thị ${kpis.length} chỉ tiêu</span></div></div>`;
}
function organizationCards(list = organizations) {
  return list.map(item=>{const record=recordFor('organization',item.id);const score=record?.score??item.score??0;const status=organizationStatus(item);return `<article class="panel org-card" data-entity-kind="organization" data-entity-id="${escapeHTML(item.id)}"><div class="org-card-head">${avatar(item.initials,item.color,item.ink)}<div><h3>${escapeHTML(item.name)}</h3><p>${escapeHTML(item.type)}</p></div><button class="row-menu" style="margin-left:auto" aria-label="Sửa ${escapeHTML(item.name)}">···</button></div><div class="org-score"><strong>${escapeHTML(score)}<span style="font-size:11px;color:#95a1b2">/100</span></strong>${statusPill(status)}</div><div class="progress-track"><div class="progress-fill" style="width:${Math.max(0,Math.min(100,Number(score)||0))}%"></div></div><div class="org-card-foot"><span>${escapeHTML(item.target || '0/0')} chỉ tiêu hoàn thành</span><span>${escapeHTML(record?.period || preferences.period || defaultPeriod)}</span></div></article>`}).join('');
}
function organizationsPage() {
  const scores=organizations.map(item=>Number(recordFor('organization',item.id)?.score??item.score)).filter(score=>Number.isFinite(score)&&score>0);
  const stateCounts={
    enterprises:organizations.filter(item=>item.type==='Doanh nghiệp nhà nước').length,
    agencies:organizations.filter(item=>item.type==='Cơ quan hành chính').length,
    average:scores.length?scores.reduce((sum,value)=>sum+value,0)/scores.length:0
  };
  return `${heading('Đơn vị & doanh nghiệp','Theo dõi hiệu quả hoạt động của doanh nghiệp nhà nước và đơn vị trực thuộc.',`${periodSelector()}${button('Thêm đơn vị',{primary:true,action:'add-org',icon:'+'})}`)}
  <div class="metrics-grid"><article class="metric-card"><div class="metric-top"><span class="metric-label">Tổng số đơn vị</span><span class="metric-icon blue">▤</span></div><div class="metric-value">${organizations.length}</div><div class="metric-bottom"><span class="metric-change neutral">Trong danh sách quản lý</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Doanh nghiệp nhà nước</span><span class="metric-icon green">▥</span></div><div class="metric-value">${stateCounts.enterprises}</div><div class="metric-bottom"><span class="metric-change neutral">Theo loại hình đơn vị</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Cơ quan hành chính</span><span class="metric-icon amber">▦</span></div><div class="metric-value">${stateCounts.agencies}</div><div class="metric-bottom"><span class="metric-change neutral">Theo loại hình đơn vị</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Điểm trung bình</span><span class="metric-icon purple">◎</span></div><div class="metric-value">${stateCounts.average.toLocaleString('vi-VN',{maximumFractionDigits:1})}</div><div class="metric-bottom"><span class="metric-change neutral">Trên thang điểm 100</span></div></article></div>
  <div class="page-heading" style="align-items:center;margin:18px 0 12px"><div><h2 class="section-title" style="margin:0">Danh sách đơn vị</h2></div><div class="table-tools"><input class="table-search" id="org-search" type="search" placeholder="Tìm đơn vị..." aria-label="Tìm đơn vị"><select class="select-control" id="org-filter"><option value="">Tất cả loại hình</option><option>Doanh nghiệp nhà nước</option><option>Cơ quan hành chính</option><option>Đơn vị sự nghiệp</option></select></div></div>
  <div class="org-grid" id="org-grid">${organizationCards()}</div>`;
}
function assessmentsPage() {
  const period=preferences.period||defaultPeriod;
  const periodRecords=assessments.filter(item=>item.period===period);
  const completed=periodRecords.filter(item=>item.status==='Đã hoàn thành').length;
  const inProgress=periodRecords.filter(item=>item.status==='Đang đánh giá'||item.status==='Chờ tự đánh giá').length;
  const waiting=periodRecords.filter(item=>item.status==='Chờ phê duyệt').length;
  return `${heading('Kỳ đánh giá','Theo dõi tiến độ, phản hồi và phê duyệt kết quả đánh giá.',`${periodSelector()}${button('Tạo phiếu đánh giá',{primary:true,action:'new-assessment',icon:'+'})}`)}
  <div class="metrics-grid"><article class="metric-card"><div class="metric-top"><span class="metric-label">Kỳ đánh giá hiện tại</span><span class="metric-icon blue">◷</span></div><div class="metric-value" style="font-size:19px">${escapeHTML(period)}</div><div class="metric-bottom"><span class="metric-change neutral">${periodRecords.length} phiếu đã tạo</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đã hoàn thành</span><span class="metric-icon green">✓</span></div><div class="metric-value">${completed}</div><div class="metric-bottom"><span class="metric-change neutral">Phiếu đã hoàn tất</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đang thực hiện</span><span class="metric-icon amber">◷</span></div><div class="metric-value">${inProgress}</div><div class="metric-bottom"><span class="metric-change neutral">Đơn vị / cá nhân</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Chờ phê duyệt</span><span class="metric-icon purple">✓</span></div><div class="metric-value">${waiting}</div><div class="metric-bottom"><span class="metric-change neutral">Cần xử lý</span></div></article></div>
  <section class="panel table-panel"><div class="panel-heading"><div><h2>Tiến độ đánh giá</h2><p>Chọn nhóm đối tượng để theo dõi kết quả</p></div><div class="assessment-tabs"><button class="tab-button ${currentAssessmentTab==='organizations'?'active':''}" data-tab="organizations">Doanh nghiệp &amp; đơn vị</button><button class="tab-button ${currentAssessmentTab==='staff'?'active':''}" data-tab="staff">Cán bộ, công chức, viên chức</button></div></div>
  <div class="panel-heading" style="padding-top:0"><div></div><input class="table-search" id="assessment-search" type="search" placeholder="Tìm kiếm..." aria-label="Tìm kiếm đối tượng"></div>
  <div id="assessment-table">${currentAssessmentTab==='organizations'?organizationsTable(organizations):staffTable(staff)}</div><div class="table-footer"><span>Đang hiển thị ${currentAssessmentTab==='organizations'?organizations.length+' đơn vị':staff.length+' cán bộ'}</span><span>Nhấn dấu ··· để nhập điểm hoặc cập nhật trạng thái</span></div></section>`;
}
function staffPage() {
  const completed=staff.filter(item=>recordFor('staff',item.id)?.status==='Đã hoàn thành'||(!recordFor('staff',item.id)&&item.status==='Đã hoàn thành')).length;
  const active=staff.filter(item=>['Đang đánh giá','Chờ tự đánh giá'].includes(recordFor('staff',item.id)?.status||item.status)).length;
  const scores=staff.map(item=>Number(recordFor('staff',item.id)?.score??item.score)).filter(score=>Number.isFinite(score)&&score>0);
  const average=scores.length?scores.reduce((sum,value)=>sum+value,0)/scores.length:0;
  return `${heading('Cán bộ','Quản lý danh sách và theo dõi kết quả đánh giá cá nhân.',`${periodSelector()}${button('Thêm cán bộ',{primary:true,action:'add-staff',icon:'+'})}`)}
  <div class="metrics-grid"><article class="metric-card"><div class="metric-top"><span class="metric-label">Tổng số cán bộ</span><span class="metric-icon blue">♙</span></div><div class="metric-value">${staff.length}</div><div class="metric-bottom"><span class="metric-change neutral">Trong danh sách quản lý</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đã hoàn thành đánh giá</span><span class="metric-icon green">✓</span></div><div class="metric-value">${completed}</div><div class="metric-bottom"><span class="metric-change neutral">Trong kỳ đang chọn</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đang thực hiện</span><span class="metric-icon amber">◷</span></div><div class="metric-value">${active}</div><div class="metric-bottom"><span class="metric-change neutral">Cần theo dõi</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Điểm trung bình</span><span class="metric-icon purple">◎</span></div><div class="metric-value">${average.toLocaleString('vi-VN',{maximumFractionDigits:1})}</div><div class="metric-bottom"><span class="metric-change neutral">Trên thang điểm 100</span></div></article></div>
  <section class="panel table-panel"><div class="panel-heading"><div><h2>Danh sách cán bộ</h2><p>Thông tin và tiến độ đánh giá theo kỳ</p></div><div class="table-tools"><input class="table-search" id="staff-search" type="search" placeholder="Tìm cán bộ..." aria-label="Tìm cán bộ"><button class="button filter-button" data-action="export-staff">↥ Xuất danh sách</button></div></div><div id="staff-table">${staffTable()}</div><div class="table-footer"><span>Hiển thị ${staff.length} cán bộ</span><span>Điểm đánh giá cần được cấp có thẩm quyền phê duyệt</span></div></section>`;
}
function reportsPage() {
  const metrics=evaluationMetrics();
  const classification=['Đạt tốt','Đạt','Cần cải thiện','Chưa đánh giá'].map(status=>organizations.filter(item=>organizationClassification(item)===status).length);
  const orgTotal=Math.max(organizations.length,1);
  const a=classification[0]/orgTotal*100;const b=(classification[0]+classification[1])/orgTotal*100;const c=(classification[0]+classification[1]+classification[2])/orgTotal*100;
  const donut=`conic-gradient(#3868e8 0 ${a}%,#46b98f ${a}% ${b}%,#f1ad4a ${b}% ${c}%,#e9edf4 ${c}% 100%)`;
  return `${heading('Báo cáo & phân tích','Phân tích kết quả KPI, xu hướng hiệu suất và mức độ hoàn thành.',`${periodSelector()}${button('Tải báo cáo',{primary:true,action:'export',icon:'↥'})}`)}
  <div class="metrics-grid"><article class="metric-card"><div class="metric-top"><span class="metric-label">Điểm trung bình</span><span class="metric-icon blue">◎</span></div><div class="metric-value">${metrics.average.toLocaleString('vi-VN',{maximumFractionDigits:1})}</div><div class="metric-bottom"><span class="metric-change neutral">Từ đối tượng có điểm</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đánh giá hoàn thành</span><span class="metric-icon green">✓</span></div><div class="metric-value">${metrics.completion}%</div><div class="metric-bottom"><span class="metric-change neutral">${metrics.completed} / ${metrics.totalOrganizations+metrics.totalStaff} đối tượng</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Đạt / đạt tốt</span><span class="metric-icon amber">↗</span></div><div class="metric-value">${organizations.length?Math.round((classification[0]+classification[1])/organizations.length*100):0}%</div><div class="metric-bottom"><span class="metric-change neutral">${classification[0]+classification[1]} / ${organizations.length} đơn vị</span></div></article><article class="metric-card"><div class="metric-top"><span class="metric-label">Cần cải thiện</span><span class="metric-icon purple">△</span></div><div class="metric-value">${classification[2]}</div><div class="metric-bottom"><span class="metric-change neutral">Đơn vị có điểm dưới 80</span></div></article></div>
  <div class="report-grid"><section class="panel"><div class="panel-heading"><div><h2>Điểm đánh giá theo đơn vị</h2><p>Điểm tổng hợp hiện tại trên thang 100</p></div></div><div class="report-stat"><div><strong>${metrics.average.toLocaleString('vi-VN',{maximumFractionDigits:1})}</strong><span>Điểm trung bình toàn hệ thống</span></div><div><strong>${organizations.length}</strong><span>Đơn vị</span></div></div><div class="bar-chart">${organizations.map(item=>{const score=Number(recordFor('organization',item.id)?.score??item.score)||0;return `<div class="bar-group"><div class="bar-pair"><span class="bar" title="${escapeHTML(item.name)}: ${score}" style="height:${Math.max(2,Math.min(100,score))}%"></span></div><span class="bar-label" title="${escapeHTML(item.name)}">${escapeHTML(item.initials)}</span></div>`}).join('')||'<p class="empty-state">Chưa có dữ liệu để lập biểu đồ.</p>'}</div></section><section class="panel"><div class="panel-heading"><div><h2>Cơ cấu xếp loại</h2><p>Kết quả theo nhóm đơn vị</p></div></div><div class="donut-area"><div class="donut" style="background:${donut}"><div class="donut-center"><strong>${organizations.length}</strong><span>đơn vị</span></div></div><div class="status-legend"><div class="status-row"><i class="legend-dot"></i>Đạt tốt <b>${classification[0]}</b></div><div class="status-row"><i class="legend-dot" style="background:#46b98f"></i>Đạt <b>${classification[1]}</b></div><div class="status-row"><i class="legend-dot" style="background:#f1ad4a"></i>Cần cải thiện <b>${classification[2]}</b></div><div class="status-row"><i class="legend-dot" style="background:#e9edf4"></i>Chưa đánh giá <b>${classification[3]}</b></div></div></div></section></div>
  <h2 class="section-title">Kết quả theo đơn vị</h2><section class="panel table-panel"><div class="panel-heading"><div><h2>Bảng xếp hạng hiệu suất</h2><p>Điểm tổng hợp theo bộ KPI được giao</p></div><button class="button filter-button" data-action="export">↥ Xuất dữ liệu</button></div>${organizationsTable()}</section>`;
}
function settingsPage() {
  return `${heading('Cài đặt','Quản lý cấu hình đánh giá và tùy chọn không gian làm việc.')}
  <div class="settings-layout"><nav class="panel settings-nav"><button class="active" data-settings-section="workflow">Quy trình đánh giá</button><button data-settings-section="backup">Dữ liệu &amp; sao lưu</button></nav><section class="panel settings-content"><h2 id="workflow">Quy trình đánh giá</h2><p>Tùy chỉnh các bước và yêu cầu trong quy trình đánh giá hiệu suất.</p>
  <div class="setting-row"><div><strong>Yêu cầu bằng chứng cho mỗi KPI</strong><span>Người đánh giá cần đính kèm minh chứng kết quả.</span></div><button class="toggle ${preferences.evidence!==false?'on':''}" data-setting="evidence" role="switch" aria-checked="${preferences.evidence!==false}" aria-label="Yêu cầu bằng chứng"></button></div>
  <div class="setting-row"><div><strong>Cho phép tự đánh giá</strong><span>Cán bộ tự cập nhật kết quả trước khi quản lý rà soát.</span></div><button class="toggle ${preferences.selfAssessment!==false?'on':''}" data-setting="selfAssessment" role="switch" aria-checked="${preferences.selfAssessment!==false}" aria-label="Cho phép tự đánh giá"></button></div>
  <div class="setting-row"><div><strong>Yêu cầu giải trình khi điều chỉnh mục tiêu</strong><span>Lưu lý do và lịch sử thay đổi chỉ tiêu trong kỳ.</span></div><button class="toggle ${preferences.explanation!==false?'on':''}" data-setting="explanation" role="switch" aria-checked="${preferences.explanation!==false}" aria-label="Yêu cầu giải trình"></button></div>
  <div class="setting-row"><div><strong>Gửi nhắc việc trước hạn</strong><span>Thông báo cho người phụ trách trước hạn 5 ngày.</span></div><button class="toggle ${preferences.reminders?'on':''}" data-setting="reminders" role="switch" aria-checked="${Boolean(preferences.reminders)}" aria-label="Gửi nhắc việc"></button></div>
  <div class="settings-data" id="backup"><div><strong>Sao lưu dữ liệu</strong><span>Xuất bản sao lưu JSON hoặc khôi phục từ bản sao lưu đã tạo.</span></div><div class="settings-data-actions"><button class="button" data-action="backup">Tải bản sao lưu</button><button class="button" data-action="restore">Khôi phục</button><button class="button button-danger" data-action="reset-data">Đặt lại dữ liệu mẫu</button></div></div>
  <div style="display:flex;justify-content:flex-end;margin-top:17px">${button('Lưu thay đổi',{primary:true,action:'save-settings'})}</div></section></div>`;
}
function helpPage() {
  return `${heading('Trợ giúp','Tìm hiểu cách sử dụng hệ thống KPI Portal.')}
  <div class="panel" style="margin-bottom:15px;padding:20px"><h2 style="font:700 13px Manrope;margin:0 0 9px">Bắt đầu với KPI Portal</h2><p style="font-size:10px;color:#8290a5;line-height:1.7;margin:0;max-width:680px">Hệ thống hỗ trợ quản lý mục tiêu, theo dõi kết quả và quy trình đánh giá cho doanh nghiệp nhà nước, cơ quan hành chính và cán bộ, công chức, viên chức. Dữ liệu trong website này là dữ liệu minh họa.</p></div>
  <div class="help-grid"><article class="panel help-card"><span class="metric-icon blue">◫</span><h3>Thiết lập chỉ tiêu KPI</h3><p>Tạo chỉ tiêu đo lường rõ ràng, quy định nguồn dữ liệu, kỳ đo và trọng số trước khi giao nhiệm vụ.</p></article><article class="panel help-card"><span class="metric-icon green">▤</span><h3>Quản lý đơn vị</h3><p>Phân biệt bộ chỉ tiêu doanh nghiệp với chỉ tiêu cơ quan, tổ chức theo chức năng và mục tiêu.</p></article><article class="panel help-card"><span class="metric-icon amber">◷</span><h3>Thực hiện đánh giá</h3><p>Cập nhật kết quả kèm bằng chứng, để quản lý rà soát và cấp có thẩm quyền phê duyệt.</p></article></div>`;
}
const pageRenderers = { dashboard:dashboardPage, kpis:kpisPage, organizations:organizationsPage, staff:staffPage, assessments:assessmentsPage, reports:reportsPage, settings:settingsPage, help:helpPage };

function renderRoute(route) {
  const render = pageRenderers[route] || dashboardPage;
  currentRoute = pageRenderers[route] ? route : 'dashboard';
  breadcrumbCurrent.textContent = routeLabels[route] || routeLabels.dashboard;
  pageContent.innerHTML = render();
  document.querySelectorAll('.nav-item').forEach(item=>item.classList.toggle('active',item.dataset.route===route));
  bindPageControls(route);
  window.scrollTo({top:0,behavior:'smooth'});
}
function showToast(message) {
  toast.textContent=message;
  toast.classList.add('visible');
  clearTimeout(toastTimeout);
  toastTimeout=setTimeout(()=>toast.classList.remove('visible'),2800);
}
function bindSearch(input, callback) {
  if(input) input.addEventListener('input',()=>callback(input.value.trim().toLocaleLowerCase('vi')));
}
function bindPageControls(route) {
  if(route==='dashboard') {
    bindSearch(pageContent.querySelector('.table-search'), query=>{
      const filtered=organizations.filter(item=>(item.name+' '+item.type).toLocaleLowerCase('vi').includes(query));
      document.getElementById('organization-table').innerHTML=organizationsTable(filtered.slice(0,4));
      const label=pageContent.querySelector('.table-footer>span');
      if(label) label.textContent=`Hiển thị ${Math.min(filtered.length,4)} trên ${filtered.length} đơn vị`;
    });
  }
  if(route==='kpis') {
    const refresh=()=> {
      const query=(pageContent.querySelector('.table-search')?.value||'').trim().toLocaleLowerCase('vi');
      const category=document.getElementById('kpi-category')?.value||'';
      const filtered=kpis.filter(item=>(item.name+' '+item.category+' '+item.owner).toLocaleLowerCase('vi').includes(query)&&(!category||item.category===category));
      document.getElementById('kpi-table-body').innerHTML=kpiRows(filtered);
      const label=pageContent.querySelector('.table-footer>span');
      if(label)label.textContent=`Hiển thị ${filtered.length} chỉ tiêu`;
    };
    bindSearch(pageContent.querySelector('.table-search'),refresh);
    document.getElementById('kpi-category')?.addEventListener('change',refresh);
  }
  if(route==='organizations') {
    const refresh=()=> {
      const query=(document.getElementById('org-search')?.value||'').trim().toLocaleLowerCase('vi');
      const type=document.getElementById('org-filter')?.value||'';
      const filtered=organizations.filter(item=>(item.name+' '+item.type).toLocaleLowerCase('vi').includes(query)&&(!type||item.type===type));
      document.getElementById('org-grid').innerHTML=organizationCards(filtered)||'<div class="panel empty-state">Không tìm thấy đơn vị phù hợp.</div>';
    };
    bindSearch(document.getElementById('org-search'),refresh);
    document.getElementById('org-filter')?.addEventListener('change',refresh);
  }
  if(route==='assessments') {
    pageContent.querySelectorAll('[data-tab]').forEach(tab=>tab.addEventListener('click',()=>{
      currentAssessmentTab=tab.dataset.tab;
      renderRoute('assessments');
    }));
    bindSearch(document.getElementById('assessment-search'),query=>{
      if(currentAssessmentTab==='organizations') {
        const filtered=organizations.filter(item=>(item.name+' '+item.type).toLocaleLowerCase('vi').includes(query));
        document.getElementById('assessment-table').innerHTML=organizationsTable(filtered);
        const label=pageContent.querySelector('.table-footer>span');if(label)label.textContent=`Đang hiển thị ${filtered.length} đơn vị`;
      } else {
        const filtered=staff.filter(item=>(item.name+' '+item.sub+' '+item.department).toLocaleLowerCase('vi').includes(query));
        document.getElementById('assessment-table').innerHTML=staffTable(filtered);
        const label=pageContent.querySelector('.table-footer>span');if(label)label.textContent=`Đang hiển thị ${filtered.length} cán bộ`;
      }
    });
  }
  if(route==='staff') {
    bindSearch(document.getElementById('staff-search'),query=>{
      const filtered=staff.filter(item=>(item.name+' '+item.sub+' '+item.department).toLocaleLowerCase('vi').includes(query));
      document.getElementById('staff-table').innerHTML=staffTable(filtered);
      const label=pageContent.querySelector('.table-footer>span');
      if(label)label.textContent=`Hiển thị ${filtered.length} cán bộ`;
    });
  }
  pageContent.querySelectorAll('.period-select').forEach(select=>select.addEventListener('change',()=>{
    preferences.period=select.value;
    persistState();
    renderRoute(currentRoute);
    showToast(`Đã chọn kỳ ${select.value}.`);
  }));
}
function textField(label,name,value='',type='text',required=true,attrs='') {
  return `<label class="field-label" for="field-${name}">${label}${required?' <span class="required">*</span>':''}</label><input class="form-control" id="field-${name}" name="${name}" type="${type}" value="${escapeHTML(value)}" ${required?'required':''} ${attrs}>`;
}
function selectField(label,name,options,value='',required=true) {
  const available=value&&!options.includes(value)?[value,...options]:options;
  return `<label class="field-label" for="field-${name}">${label}${required?' <span class="required">*</span>':''}</label><select class="form-control" id="field-${name}" name="${name}" ${required?'required':''}><option value="">Chọn ${label.toLocaleLowerCase('vi')}</option>${available.map(option=>`<option value="${escapeHTML(option)}" ${option===value?'selected':''}>${escapeHTML(option)}</option>`).join('')}</select>`;
}
function noteField(value='') {
  return `<label class="field-label" for="field-note">Ghi chú / giải trình</label><textarea class="form-control" id="field-note" name="note" rows="3" placeholder="Nhập ghi chú, căn cứ hoặc liên kết minh chứng">${escapeHTML(value)}</textarea>`;
}
function kpisForSubject(type) {
  return kpis.filter(item=>item.owner==='Tất cả đơn vị'||(type==='staff'?item.owner.toLocaleLowerCase('vi').includes('cán bộ'):!item.owner.toLocaleLowerCase('vi').includes('cán bộ')));
}
function weightedScore(results) {
  const scored=results.filter(item=>item.score!==null&&item.score!==undefined&&item.score!==''&&Number.isFinite(Number(item.score)));
  if(!scored.length)return null;
  const totalWeight=scored.reduce((sum,item)=>sum+Math.max(0,Number(item.weight)||0),0);
  if(totalWeight===0)return scored.reduce((sum,item)=>sum+Number(item.score),0)/scored.length;
  return scored.reduce((sum,item)=>sum+Number(item.score)*Math.max(0,Number(item.weight)||0),0)/totalWeight;
}
function updateWeightedPreview() {
  const output=document.getElementById('weighted-score-preview');
  if(!output)return;
  const rows=[...document.querySelectorAll('[data-kpi-score]')].map(input=>({score:input.value,weight:Number(input.dataset.weight)}));
  const score=weightedScore(rows);
  output.textContent=score===null?'—':score.toLocaleString('vi-VN',{maximumFractionDigits:1});
}
function openModal(action, entityId='') {
  const editing=action.startsWith('edit-');
  const kind=action==='add-org'?'organization':action==='add-staff'?'staff':action==='new-kpi'?'kpi':action.replace(/^edit-/,'');
  const source=kind==='organization'?organizations:kind==='kpi'?kpis:staff;
  const entity=editing?source.find(item=>item.id===entityId):null;
  let title=''; let description=''; let fields='';
  if(action==='new-assessment') {
    title='Tạo phiếu đánh giá'; description='Chọn đối tượng và kỳ đánh giá. Kết quả sẽ được lưu trên trình duyệt này.';
    const subjects=[...organizations.map(item=>({id:`organization:${item.id}`,name:item.name,group:'Doanh nghiệp / đơn vị'})),...staff.map(item=>({id:`staff:${item.id}`,name:item.name,group:'Cán bộ, công chức, viên chức'}))];
    fields=`<label class="field-label" for="field-subject">Đối tượng đánh giá <span class="required">*</span></label><select class="form-control" id="field-subject" name="subject" required><option value="">Chọn đối tượng</option><optgroup label="Doanh nghiệp / đơn vị">${subjects.filter(item=>item.group==='Doanh nghiệp / đơn vị').map(item=>`<option value="${escapeHTML(item.id)}">${escapeHTML(item.name)}</option>`).join('')}</optgroup><optgroup label="Cán bộ, công chức, viên chức">${subjects.filter(item=>item.group==='Cán bộ, công chức, viên chức').map(item=>`<option value="${escapeHTML(item.id)}">${escapeHTML(item.name)}</option>`).join('')}</optgroup></select>`+selectField('Kỳ đánh giá','period',periodOptions,preferences.period||defaultPeriod)+noteField();
  } else if(action==='score-assessment') {
    const type=entityId.startsWith('staff:')?'staff':'organization'; const id=entityId.slice(type.length+1);
    const subject=(type==='staff'?staff:organizations).find(item=>item.id===id); const record=recordFor(type,id);
    title='Cập nhật kết quả đánh giá'; description=`Cập nhật điểm và trạng thái cho ${subject?.name||'đối tượng'}.`;
    const assigned=kpisForSubject(type);
    const resultMap=new Map((record?.results||[]).map(item=>[item.kpiId,item]));
    const resultFields=assigned.map(item=>{
      const result=resultMap.get(item.id);
      return `<div class="assessment-kpi"><div class="assessment-kpi-title"><strong>${escapeHTML(item.name)}</strong><span>Mục tiêu: ${escapeHTML(item.target)} · Trọng số ${escapeHTML(String(item.weight).replace('%',''))}%</span></div><div class="assessment-kpi-inputs"><label>Giá trị thực tế<input class="form-control" name="actual-${escapeHTML(item.id)}" value="${escapeHTML(result?.actual||'')}" placeholder="${escapeHTML(item.unit)}"></label><label>Điểm KPI<input class="form-control" data-kpi-score data-kpi-id="${escapeHTML(item.id)}" data-weight="${Number.parseFloat(item.weight)||0}" type="number" min="0" max="100" step="0.1" value="${escapeHTML(result?.score??'')}" placeholder="0–100"></label></div></div>`;
    }).join('');
    fields=assigned.length?`<div class="assessment-score-summary">Điểm tổng hợp theo trọng số <strong id="weighted-score-preview">—</strong><span>/ 100</span></div><div class="assessment-kpi-list">${resultFields}</div>`:textField('Điểm đánh giá (0–100)','score',record?.score??subject?.score??'', 'number',true,'min="0" max="100" step="0.1"');
    fields+=selectField('Trạng thái','status',['Chờ tự đánh giá','Đang đánh giá','Chờ phê duyệt','Đã hoàn thành'],record?.status||'Đang đánh giá')+noteField(record?.note||'');
    modalBackdrop.dataset.entityType=type; modalBackdrop.dataset.entityId=id;
  } else if(kind==='organization') {
    title=editing?'Chỉnh sửa đơn vị':'Thêm đơn vị'; description='Thông tin đơn vị và loại hình áp dụng.';
    fields=textField('Tên đơn vị','name',entity?.name||'')+selectField('Loại hình','type',['Doanh nghiệp nhà nước','Cơ quan hành chính','Đơn vị sự nghiệp'],entity?.type||'')+textField('Số chỉ tiêu đã hoàn thành / tổng chỉ tiêu','target',entity?.target||'0/0', 'text',false)+noteField(entity?.description||'');
  } else if(kind==='staff') {
    title=editing?'Chỉnh sửa hồ sơ cán bộ':'Thêm cán bộ'; description='Quản lý cán bộ, công chức, viên chức trong đơn vị.';
    fields=textField('Họ và tên','name',entity?.name||'')+textField('Chức danh / vị trí công tác','title',entity?.title||entity?.sub?.split(' · ')[0]||'')+textField('Phòng ban / đơn vị','department',entity?.department||'')+noteField(entity?.description||'');
  } else if(kind==='kpi') {
    title=editing?'Chỉnh sửa chỉ tiêu KPI':'Tạo chỉ tiêu KPI'; description='Định nghĩa mục tiêu rõ ràng, đo lường được và có người chịu trách nhiệm.';
    fields=textField('Tên chỉ tiêu','name',entity?.name||'')+selectField('Nhóm chỉ tiêu','category',['Tài chính','Dịch vụ công','Chất lượng','Chiến lược','Tiến độ'],entity?.category||'')+textField('Đơn vị đo','unit',entity?.unit||'')+textField('Mục tiêu','target',entity?.target||'')+textField('Trọng số (%)','weight',parseFloat(entity?.weight)||'', 'number',true,'min="0" max="100" step="0.1"')+textField('Đối tượng áp dụng','owner',entity?.owner||'')+noteField(entity?.description||'');
  }
  document.getElementById('modal-title').textContent=title;
  document.getElementById('modal-description').textContent=description;
  document.getElementById('modal-fields').innerHTML=fields;
  document.getElementById('form-error').hidden=true;
  document.getElementById('modal-confirm').innerHTML=editing?'Lưu thay đổi <span>→</span>':action==='new-assessment'?'Tạo phiếu đánh giá <span>→</span>':'Lưu <span>→</span>';
  document.getElementById('modal-delete').hidden=!editing;
  const storedEntityId=action==='score-assessment'?entityId.slice(entityId.indexOf(':')+1):editing?entityId:'';
  modalBackdrop.dataset.modalAction=action; modalBackdrop.dataset.entityId=storedEntityId;
  modalBackdrop.hidden=false;
  if(action==='score-assessment')updateWeightedPreview();
  modalBackdrop.querySelector('.form-control')?.focus();
}
function closeModal() {
  modalBackdrop.hidden=true;
  document.getElementById('modal-form').reset();
  document.getElementById('form-error').hidden=true;
}
function downloadFile(filename,type,content) {
  const url=URL.createObjectURL(new Blob([content],{type}));
  const link=document.createElement('a');link.href=url;link.download=filename;link.hidden=true;
  document.body.append(link);link.click();link.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
  showToast(`Đã tạo tệp ${filename}.`);
}
function exportCsv(filename,headers,rows) {
  const quote=value=>{
    let text=String(value??'');
    if(/^[\s]*[=+\-@\t\r]/.test(text))text=`'${text}`;
    return `"${text.replace(/"/g,'""')}"`;
  };
  downloadFile(filename,'text/csv;charset=utf-8','\uFEFF'+[headers,...rows].map(row=>row.map(quote).join(',')).join('\r\n'));
  showToast('Đã tải tệp CSV.');
}
function exportOrganizations() {
  exportCsv('kpi-don-vi.csv',['Tên đơn vị','Loại hình','Điểm','Trạng thái','Số chỉ tiêu','Kỳ đánh giá'],organizations.map(item=>{const record=recordFor('organization',item.id);return [item.name,item.type,record?.score??item.score,record?.status??item.status,item.target,record?.period||preferences.period||defaultPeriod]}));
}
function exportStaff() {
  exportCsv('kpi-can-bo.csv',['Họ tên','Chức danh','Đơn vị','Điểm','Trạng thái','Kỳ đánh giá'],staff.map(item=>{const record=recordFor('staff',item.id);return [item.name,item.title||item.sub,item.department,record?.score??item.score,record?.status??item.status,record?.period||preferences.period||defaultPeriod]}));
}
function validateBackup(backup) {
  const isRecord=value=>value&&typeof value==='object'&&!Array.isArray(value);
  const hasStrings=(rows,fields)=>Array.isArray(rows)&&rows.every(item=>isRecord(item)&&fields.every(field=>typeof item[field]==='string'));
  if(!isRecord(backup)||!hasStrings(backup.organizations,['name','type'])||
     !hasStrings(backup.kpis,['name','category','unit','target','owner'])||
     !hasStrings(backup.staff,['name','department'])||!Array.isArray(backup.assessments)||
     !backup.assessments.every(item=>isRecord(item)&&['organization','staff'].includes(item.subjectType)&&typeof item.subjectId==='string'&&typeof item.period==='string'&&typeof item.status==='string'&&(item.score===null||Number.isFinite(Number(item.score))))||
     (backup.preferences!==undefined&&!isRecord(backup.preferences))) {
    throw new Error('Tệp sao lưu không đúng cấu trúc KPI Portal hoặc thiếu trường bắt buộc.');
  }
  if(backup.kpis.some(item=>!/^(?:\d+(?:\.\d+)?|\.\d+)%?$/.test(String(item.weight).trim())||Number.parseFloat(item.weight)<0||Number.parseFloat(item.weight)>100)){
    throw new Error('Tệp sao lưu có trọng số KPI không hợp lệ.');
  }
}
function deleteCurrentEntity() {
  const action=modalBackdrop.dataset.modalAction;const kind=action.replace('edit-','');const id=modalBackdrop.dataset.entityId;
  const label=kind==='organization'?'đơn vị':kind==='staff'?'cán bộ':'chỉ tiêu KPI';
  if(!window.confirm(`Bạn có chắc muốn xóa ${label} này? Các phiếu đánh giá liên quan cũng sẽ bị xóa.`))return;
  if(kind==='organization')organizations=organizations.filter(item=>item.id!==id);
  else if(kind==='staff')staff=staff.filter(item=>item.id!==id);
  else if(kind==='kpi')kpis=kpis.filter(item=>item.id!==id);
  assessments=assessments.filter(item=>!(item.subjectType===kind&&item.subjectId===id));
  persistAndRender(`Đã xóa ${label}.`);
}
function saveModal() {
  const form=document.getElementById('modal-form');
  if(!form.reportValidity())return;
  const values=Object.fromEntries(new FormData(form).entries());
  const action=modalBackdrop.dataset.modalAction;
  const error=document.getElementById('form-error');
  const fail=message=>{error.textContent=message;error.hidden=false};
  error.hidden=true;
  if(action==='new-assessment') {
    const [type,id]=String(values.subject||'').split(':');
    if(!['organization','staff'].includes(type)||(type==='organization'?!organizations.some(item=>item.id===id):!staff.some(item=>item.id===id))){fail('Vui lòng chọn đối tượng hợp lệ.');return}
    const period=values.period||preferences.period||defaultPeriod;
    preferences.period=period;
    const record={id:makeId(),subjectType:type,subjectId:id,period,status:type==='staff'?'Chờ tự đánh giá':'Đang đánh giá',score:null,note:values.note||'',updatedAt:new Date().toISOString()};
    const existingIndex=assessments.findIndex(item=>item.subjectType===type&&item.subjectId===id&&item.period===period);
    record.period=period;
    if(existingIndex>=0)assessments[existingIndex]=record;else assessments.push(record);
    if(type==='staff'){const person=staff.find(item=>item.id===id);person.status=record.status;person.progress=25;}
    persistAndRender('Đã tạo phiếu đánh giá và lưu dữ liệu.','assessments');return;
  }
  if(action==='score-assessment') {
    const type=modalBackdrop.dataset.entityType;const id=modalBackdrop.dataset.entityId;
    const assigned=kpisForSubject(type);
    const resultInputs=[...document.querySelectorAll('[data-kpi-score]')];
    const results=resultInputs.map(input=>({kpiId:input.dataset.kpiId,weight:Number(input.dataset.weight)||0,score:input.value===''?null:Number(input.value),actual:form.elements.namedItem(`actual-${input.dataset.kpiId}`)?.value||''}));
    if(results.some(item=>item.score!==null&&(!Number.isFinite(item.score)||item.score<0||item.score>100))){fail('Điểm của từng KPI phải nằm trong khoảng từ 0 đến 100.');return}
    const missing=results.some(item=>item.score===null);
    if(assigned.length&&missing&&['Chờ phê duyệt','Đã hoàn thành'].includes(values.status)){fail('Hãy nhập điểm cho tất cả KPI trước khi gửi duyệt hoặc hoàn thành đánh giá.');return}
    const score=assigned.length?weightedScore(results):Number(values.score);
    if(!assigned.length&&(!Number.isFinite(score)||score<0||score>100)){fail('Điểm phải nằm trong khoảng từ 0 đến 100.');return}
    if(['Chờ phê duyệt','Đã hoàn thành'].includes(values.status)&&score===null){fail('Nhập ít nhất một điểm KPI trước khi gửi duyệt hoặc hoàn thành đánh giá.');return}
    let record=recordFor(type,id);
    if(!record){record={id:makeId(),subjectType:type,subjectId:id,period:preferences.period||defaultPeriod};assessments.push(record)}
    record.score=score;record.results=results;record.status=values.status;record.note=values.note||'';record.updatedAt=new Date().toISOString();
    if(type==='staff'){const person=staff.find(item=>item.id===id);if(person){person.score=score;person.status=record.status;person.progress=record.status==='Đã hoàn thành'?100:record.status==='Đang đánh giá'?75:50}}
    else {const org=organizations.find(item=>item.id===id);if(org){org.score=score;org.status=record.status}}
    persistAndRender('Đã cập nhật kết quả đánh giá.');
    return;
  }
  const editing=action.startsWith('edit-');const kind=action==='add-org'?'organization':action==='add-staff'?'staff':action==='new-kpi'?'kpi':action.replace(/^edit-/,'');
  const collection=kind==='organization'?organizations:kind==='staff'?staff:kpis;
  const id=modalBackdrop.dataset.entityId;
  const record=editing?collection.find(item=>item.id===id):null;
  if(editing&&!record){fail('Không tìm thấy dữ liệu cần cập nhật.');return}
  if(kind==='organization') {
    const next={...(record||{}),id:record?.id||makeId(),name:String(values.name).trim(),type:values.type,target:String(values.target||'0/0').trim(),description:values.note||'',initials:record?.initials||initialsFromName(values.name),color:record?.color||['#e9efff','#fff1e8','#e9f7f0','#f1edff','#fff4e4'][organizations.length%5],ink:record?.ink||'#456bd1',score:record?.score??0,status:record?.status||'Chưa đánh giá'};
    if(!next.name){fail('Vui lòng nhập tên đơn vị.');return}
    if(record)Object.assign(record,next);else organizations.push(next);
  } else if(kind==='staff') {
    const title=String(values.title).trim();const next={...(record||{}),id:record?.id||makeId(),name:String(values.name).trim(),title,sub:`${title} · ${String(values.department).trim()}`,department:String(values.department).trim(),description:values.note||'',initials:record?.initials||initialsFromName(values.name),color:record?.color||'#e9efff',ink:record?.ink||'#456bd1',score:record?.score??0,progress:record?.progress??0,status:record?.status||'Chờ tự đánh giá'};
    if(!next.name||!next.department){fail('Vui lòng nhập họ tên và đơn vị công tác.');return}
    if(record)Object.assign(record,next);else staff.push(next);
  } else if(kind==='kpi') {
    const weight=Number(values.weight);
    if(!Number.isFinite(weight)||weight<0||weight>100){fail('Trọng số phải nằm trong khoảng từ 0 đến 100.');return}
    const next={...(record||{}),id:record?.id||makeId(),name:String(values.name).trim(),category:values.category,unit:String(values.unit).trim(),target:String(values.target).trim(),weight,owner:String(values.owner).trim(),description:values.note||'',icon:record?.icon||'◎',color:record?.color||'blue'};
    if(!next.name||!next.unit||!next.target||!next.owner){fail('Vui lòng nhập đầy đủ tên, đơn vị đo, mục tiêu và đối tượng áp dụng.');return}
    if(record)Object.assign(record,next);else kpis.push(next);
  }
  persistAndRender(`${editing?'Đã cập nhật':'Đã thêm'} dữ liệu thành công.`);
}
document.addEventListener('click',event=>{
  const routeLink=event.target.closest('[data-route]');
  if(routeLink){event.preventDefault();renderRoute(routeLink.dataset.route);return}
  const actionButton=event.target.closest('[data-action]');
  if(actionButton){
    const action=actionButton.dataset.action;
    if(['new-assessment','new-kpi','add-org','add-staff'].includes(action))openModal(action);
    else if(action==='export')exportOrganizations();
    else if(action==='export-staff')exportStaff();
    else if(action==='backup')downloadFile('kpi-portal-backup.json','application/json;charset=utf-8',JSON.stringify({version:1,exportedAt:new Date().toISOString(),organizations,kpis,staff,assessments,preferences},null,2));
    else if(action==='restore')document.getElementById('restore-file').click();
    else if(action==='reset-data'&&window.confirm('Đặt lại toàn bộ dữ liệu về bộ minh họa ban đầu? Thao tác này không thể hoàn tác.')){organizations=seedOrganizations.map(item=>({...item,id:makeId()}));kpis=seedKpis.map(item=>({...item,id:makeId()}));staff=seedStaff.map(item=>({...item,id:makeId()}));assessments=[];preferences={};persistAndRender('Đã khôi phục dữ liệu mẫu.','settings')}
    else if(action==='save-settings'){persistState();showToast('Đã lưu cài đặt trên trình duyệt này.')}
    else if(action==='filter')showToast('Dùng thanh tìm kiếm và bộ lọc để thu hẹp danh sách.');
    return;
  }
  const toggle=event.target.closest('.toggle');
  if(toggle){toggle.classList.toggle('on');toggle.setAttribute('aria-checked',toggle.classList.contains('on')?'true':'false');preferences[toggle.dataset.setting]=toggle.classList.contains('on');persistState();return}
  const settingTab=event.target.closest('.settings-nav button');
  if(settingTab){document.querySelectorAll('.settings-nav button').forEach(item=>item.classList.remove('active'));settingTab.classList.add('active');document.getElementById(settingTab.dataset.settingsSection)?.scrollIntoView({behavior:'smooth',block:'center'});return}
  if(event.target.closest('.notification-button')){showToast('Không có thông báo mới trong bản demo.');return}
  if(event.target.closest('.top-user,.more-button')){showToast('Tài khoản quản trị demo — chức năng đăng nhập chưa được bật.');return}
  const menu=event.target.closest('.row-menu');
  if(menu){
    const row=menu.closest('[data-entity-kind]');const kind=row?.dataset.entityKind;const id=row?.dataset.entityId;
    if(currentRoute==='assessments'){const subjectType=currentAssessmentTab==='staff'?'staff':'organization';openModal('score-assessment',`${subjectType}:${id||''}`)}
    else if(kind&&id)openModal(`edit-${kind}`,id);
  }
});
document.getElementById('modal-close').addEventListener('click',closeModal);
document.getElementById('modal-cancel').addEventListener('click',closeModal);
document.getElementById('modal-delete').addEventListener('click',deleteCurrentEntity);
modalBackdrop.addEventListener('click',event=>{if(event.target===modalBackdrop)closeModal()});
document.getElementById('modal-confirm').addEventListener('click',saveModal);
document.getElementById('modal-form').addEventListener('submit',event=>{event.preventDefault();saveModal()});
document.getElementById('modal-form').addEventListener('input',updateWeightedPreview);
document.getElementById('restore-file').addEventListener('change',async event=>{
  const file=event.target.files?.[0];if(!file)return;
  try {
    const backup=JSON.parse(await file.text());
    validateBackup(backup);
    organizations=backup.organizations.map(item=>({...item,id:item.id||makeId()}));
    kpis=backup.kpis.map(item=>({...item,id:item.id||makeId()}));
    staff=backup.staff.map(item=>({...item,id:item.id||makeId()}));
    assessments=backup.assessments;preferences=backup.preferences&&typeof backup.preferences==='object'?backup.preferences:{};
    persistAndRender('Đã khôi phục dữ liệu từ bản sao lưu.','settings');
  } catch(error) {
    console.error('Không thể khôi phục bản sao lưu KPI.',error);
    showToast(error instanceof SyntaxError?'Tệp không phải JSON hợp lệ.':error.message||'Không thể đọc bản sao lưu.');
  } finally {event.target.value=''}
});
document.getElementById('global-search').addEventListener('keydown',event=>{
  if(event.key==='Enter') {
    const query=event.target.value.trim().toLocaleLowerCase('vi');
    if(!query)return;
    const route=organizations.some(item=>(item.name+' '+item.type).toLocaleLowerCase('vi').includes(query))?'organizations':
      staff.some(item=>(item.name+' '+item.sub+' '+item.department).toLocaleLowerCase('vi').includes(query))?'staff':'kpis';
    renderRoute(route);
    const input=pageContent.querySelector('.table-search')||document.getElementById('org-search')||document.getElementById('staff-search');
    if(input){input.value=event.target.value;input.dispatchEvent(new Event('input',{bubbles:true}))}
  }
});
document.addEventListener('keydown',event=>{
  if((event.metaKey||event.ctrlKey)&&event.key.toLowerCase()==='k'){event.preventDefault();document.getElementById('global-search').focus()}
  if(event.key==='Escape'&&!modalBackdrop.hidden)closeModal();
});
renderRoute('dashboard');
persistState();
}
