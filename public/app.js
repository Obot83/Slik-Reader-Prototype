// TOP Operator Backoffice - SLIK Reader Frontend Engine
const API_BASE = window.location.origin;

const state = {
  activeView: 'credit-checking', // default to requested feature
  leads: [],
  selectedLead: null,
  activeDetailTab: 'credit_checking',
  uploadModal: {
    isOpen: false,
    lead: null,
    subjectType: 'SLIK_PEMOHON',
    subjectName: '',
    identityNumber: '',
    file: null,
    uploadProgress: 0,
    isUploading: false,
    error: null,
    parseResult: null
  },
  rejectModal: {
    isOpen: false,
    resultId: null,
    reasonCode: 'IDENTITY_MISMATCH',
    note: ''
  },
  reportModal: {
    isOpen: false,
    resultId: null,
    resultData: null
  }
};

// API Helper
async function apiCall(endpoint, options = {}) {
  const defaultHeaders = {
    'Accept': 'application/json',
    'X-Tenant-Key': 'tenant-demo-sukma',
    'Authorization': 'Bearer demo-token-top-operator'
  };

  if (!(options.body instanceof FormData)) {
    defaultHeaders['Content-Type'] = 'application/json';
  }

  const response = await fetch(API_BASE + endpoint, {
    ...options,
    headers: {
      ...defaultHeaders,
      ...(options.headers || {})
    }
  });

  const json = await response.json();
  return json;
}

// Formatters
function formatRupiah(amount) {
  if (amount === undefined || amount === null) return 'Rp 0';
  return 'Rp ' + Number(amount).toLocaleString('id-ID');
}

function maskNik(nik) {
  if (!nik || nik.length < 4) return '****';
  return '****' + nik.slice(-4);
}


// Navigation
function setView(viewName, params = {}) {
  state.activeView = viewName;
  document.querySelectorAll('.nav-sub-item, .nav-item').forEach(el => el.classList.remove('active'));

  const appLayout = document.getElementById('app-container');
  const loginLayout = document.getElementById('login-container');

  if (viewName === 'login' || viewName === 'binding') {
    if (appLayout) appLayout.style.display = 'none';
    if (loginLayout) {
      loginLayout.style.display = 'flex';
      renderLogin(viewName);
    }
    return;
  }

  if (appLayout) appLayout.style.display = 'flex';
  if (loginLayout) loginLayout.style.display = 'none';

  if (viewName === 'credit-checking') {
    const el = document.getElementById('nav-pengajuan-slik');
    if (el) el.classList.add('active');
    loadPengajuanSlik();
  } else if (viewName === 'daftar-leads') {
    const el = document.getElementById('nav-daftar-leads');
    if (el) el.classList.add('active');
    loadDaftarLeads();
  } else if (viewName === 'dashboard') {
    const el = document.getElementById('nav-dashboard');
    if (el) el.classList.add('active');
    renderDashboard();
  } else if (viewName === 'assign-surveyor') {
    const el = document.getElementById('nav-assign-surveyor');
    if (el) el.classList.add('active');
    renderAssignSurveyor();
  } else if (viewName === 'detail-leads') {
    if (params.leadId) {
      loadLeadDetail(params.leadId, params.tab || 'credit_checking');
    }
  }

  render();
}

function renderLogin(mode = 'login') {
  const container = document.getElementById('login-container');
  if (!container) return;

  if (mode === 'binding') {
    container.innerHTML = `
      <div class="login-split">
        <div class="login-form-side">
          <h2 style="font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Hi, Welcome to Backoffice!</h2>
          <p style="color: #64748b; font-size: 14px; margin-bottom: 32px;">Masukkan Company ID untuk melanjutkan</p>
          <div class="form-group">
            <label class="form-label" style="font-size: 13px; color: #64748b;">Company ID</label>
            <input type="text" class="form-control" placeholder="e.g. japra" value="japra" style="padding: 12px 16px; border-radius: 8px;">
          </div>
          <button class="btn btn-primary" style="width: 100%; padding: 13px; font-size: 15px; margin-top: 10px; background: #64748b; border:none;" onclick="setView('login')">
            Selanjutnya
          </button>
        </div>
        <div class="login-banner-side">
          <div style="opacity: 0.15; position: absolute; top: 20%; left: 10%;">
            <svg width="200" height="200" fill="currentColor" viewBox="0 0 24 24"><circle cx="4" cy="4" r="2"/><circle cx="12" cy="4" r="2"/><circle cx="20" cy="4" r="2"/><circle cx="4" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="20" cy="12" r="2"/><circle cx="4" cy="20" r="2"/><circle cx="12" cy="20" r="2"/><circle cx="20" cy="20" r="2"/></svg>
          </div>
        </div>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div class="login-split">
      <div class="login-form-side">
        <h2 style="font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Hi, Welcome to Backoffice!</h2>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 32px;">Login and monitor the progress</p>
        <div class="form-group">
          <label class="form-label" style="font-size: 13px; color: #64748b;">Username</label>
          <input type="text" class="form-control" placeholder="Username" value="operator@top.id" style="padding: 12px 16px; border-radius: 8px;">
        </div>
        <div class="form-group" style="position: relative;">
          <label class="form-label" style="font-size: 13px; color: #64748b;">Password</label>
          <input type="password" class="form-control" placeholder="Password" value="password123" style="padding: 12px 16px; border-radius: 8px;">
          <svg style="position: absolute; right: 14px; top: 38px; color: #94a3b8; cursor: pointer; width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
          <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #475569; cursor: pointer;">
            <input type="checkbox" checked> Biarkan Saya Tetap Masuk
          </label>
          <a href="javascript:void(0)" style="font-size: 13px; color: #0284c7; text-decoration: none; font-weight: 600;">Lupa Kata Sandi?</a>
        </div>
        <button class="btn btn-primary" style="width: 100%; padding: 13px; font-size: 15px; border-radius: 8px; background: #0f172a;" onclick="setView('dashboard')">
          Masuk
        </button>
        <div style="margin-top: 18px; text-align: center;">
          <a href="javascript:void(0)" style="color: #64748b; font-size: 12.5px;" onclick="setView('binding')">Ganti Company ID</a>
        </div>
      </div>
      <div class="login-banner-side">
        <div style="max-width: 460px; text-align: center;">
          <div style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 24px; margin-bottom: 30px; backdrop-filter: blur(10px);">
            <div style="width: 100%; height: 160px; background: #ffffff; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #0f172a; font-weight: bold; font-size: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">
              <div style="text-align: left; padding: 15px; font-size: 11px; width: 100%;">
                <div style="font-weight: 800; font-size: 13px; margin-bottom: 8px;">TOP Operator &bull; Memorandum Kredit</div>
                <div style="height: 6px; width: 60%; background: #e2e8f0; border-radius: 3px; margin-bottom: 6px;"></div>
                <div style="height: 6px; width: 85%; background: #e2e8f0; border-radius: 3px; margin-bottom: 6px;"></div>
                <div style="height: 6px; width: 45%; background: #0284c7; border-radius: 3px;"></div>
              </div>
            </div>
          </div>
          <h3 style="font-size: 24px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.3px;">Persetujuan Kredit Lebih Cepat</h3>
          <p style="font-size: 13.5px; opacity: 0.8; line-height: 1.6;">Review dan setujui memorandum kredit dengan proses yang terpusat dan terdokumentasi.</p>
        </div>
      </div>
    </div>
  `;
}

// Load Data
async function loadPengajuanSlik() {
  try {
    const res = await apiCall('/leads/list/pengajuan-slik', { method: 'POST' });
    if (res.error === 0) {
      state.leads = res.data;
      renderPengajuanSlik();
    }
  } catch (err) {
    console.error('Failed to load pengajuan slik:', err);
  }
}

async function loadDaftarLeads() {
  try {
    const res = await apiCall('/leads/list/pengajuan-slik', { method: 'POST' });
    if (res.error === 0) {
      state.leads = res.data;
      renderDaftarLeads();
    }
  } catch (err) {
    console.error('Failed to load leads:', err);
  }
}

async function loadLeadDetail(leadId, activeTab = 'credit_checking') {
  try {
    const res = await apiCall(`/leads/list/${leadId}`, { method: 'GET' });
    if (res.error === 0) {
      state.selectedLead = res.data;
      state.activeDetailTab = activeTab;
      renderDetailLeads();
    }
  } catch (err) {
    console.error('Failed to load lead detail:', err);
  }
}

// Render Functions
function render() {
  const container = document.getElementById('main-content');
  if (!container) return;

  if (state.activeView === 'dashboard') {
    renderDashboard();
  } else if (state.activeView === 'credit-checking') {
    renderPengajuanSlik();
  } else if (state.activeView === 'daftar-leads') {
    renderDaftarLeads();
  } else if (state.activeView === 'detail-leads') {
    renderDetailLeads();
  } else if (state.activeView === 'assign-surveyor') {
    renderAssignSurveyor();
  }

  renderModals();
}

function renderDashboard() {
  const container = document.getElementById('main-content');
  container.innerHTML = `
    <div class="top-header">
      <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Halo, Berikut aktivitas terbaru di akun Anda hari ini.</p>
      </div>
      <div class="header-actions">
        <button class="icon-btn" title="Notifikasi">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </button>
      </div>
    </div>

    <!-- Sales Statistik Card -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h3 style="font-size:18px; font-weight:800; color:#0f172a;">Sales Statistik</h3>
        <div style="display:flex; gap:16px; font-size:12.5px; font-weight:700;">
          <span style="display:flex; align-items:center; gap:6px;">
            <span style="width:12px; height:12px; background:#0ea5e9; border-radius:3px; display:inline-block;"></span> Leads
          </span>
          <span style="display:flex; align-items:center; gap:6px;">
            <span style="width:12px; height:12px; background:#f43f5e; border-radius:3px; display:inline-block;"></span> Prospects
          </span>
        </div>
      </div>

      <!-- Custom SVG Chart matching Mockup -->
      <div style="width:100%; height:260px; position:relative;">
        <svg viewBox="0 0 1000 240" style="width:100%; height:100%;">
          <!-- Grid lines -->
          <line x1="60" y1="20" x2="980" y2="20" stroke="#f1f5f9" stroke-width="1" />
          <text x="50" y="24" text-anchor="end" font-size="10" fill="#94a3b8">3,500,000,000</text>
          
          <line x1="60" y1="60" x2="980" y2="60" stroke="#f1f5f9" stroke-width="1" />
          <text x="50" y="64" text-anchor="end" font-size="10" fill="#94a3b8">2,500,000,000</text>

          <line x1="60" y1="100" x2="980" y2="100" stroke="#f1f5f9" stroke-width="1" />
          <text x="50" y="104" text-anchor="end" font-size="10" fill="#94a3b8">1,500,000,000</text>

          <line x1="60" y1="140" x2="980" y2="140" stroke="#f1f5f9" stroke-width="1" />
          <text x="50" y="144" text-anchor="end" font-size="10" fill="#94a3b8">500,000,000</text>

          <line x1="60" y1="180" x2="980" y2="180" stroke="#cbd5e1" stroke-width="1" />
          <text x="50" y="184" text-anchor="end" font-size="10" fill="#94a3b8">0</text>

          <!-- Bars: Apr, May, Jun, Jul, Ags, Sep -->
          <!-- Apr -->
          <rect x="360" y="145" width="22" height="35" fill="#0ea5e9" rx="2" />
          <rect x="384" y="145" width="22" height="35" fill="#f43f5e" rx="2" />
          <text x="383" y="200" text-anchor="middle" font-size="11" fill="#64748b">Apr</text>

          <!-- May -->
          <rect x="440" y="40" width="22" height="140" fill="#0ea5e9" rx="2" />
          <rect x="464" y="60" width="22" height="120" fill="#f43f5e" rx="2" />
          <text x="463" y="200" text-anchor="middle" font-size="11" fill="#64748b">May</text>

          <!-- Jun -->
          <rect x="520" y="105" width="22" height="75" fill="#0ea5e9" rx="2" />
          <rect x="544" y="100" width="22" height="80" fill="#f43f5e" rx="2" />
          <text x="543" y="200" text-anchor="middle" font-size="11" fill="#64748b">Jun</text>

          <!-- Jul -->
          <rect x="600" y="155" width="22" height="25" fill="#0ea5e9" rx="2" />
          <rect x="624" y="160" width="22" height="20" fill="#f43f5e" rx="2" />
          <text x="623" y="200" text-anchor="middle" font-size="11" fill="#64748b">Jul</text>

          <!-- Ags -->
          <rect x="680" y="150" width="22" height="30" fill="#0ea5e9" rx="2" />
          <rect x="704" y="140" width="22" height="40" fill="#f43f5e" rx="2" />
          <text x="703" y="200" text-anchor="middle" font-size="11" fill="#64748b">Ags</text>

          <!-- Sep -->
          <rect x="760" y="168" width="22" height="12" fill="#0ea5e9" rx="2" />
          <text x="771" y="200" text-anchor="middle" font-size="11" fill="#64748b">Sep</text>
        </svg>
      </div>
    </div>

    <!-- 4 Summary KPI Cards matching Mockup -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div>
          <div class="kpi-icon-wrapper kpi-icon-red">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
          </div>
          <div class="kpi-label">Jumlah Leads</div>
          <div class="kpi-value">Rp 6.343.348.607</div>
          <div class="kpi-progress-bar">
            <div class="kpi-progress-fill" style="width: 73%; background: #ef4444;"></div>
          </div>
        </div>
        <div class="kpi-footer">
          <span>73%</span>
          <span>58/80</span>
        </div>
      </div>

      <div class="kpi-card">
        <div>
          <div class="kpi-icon-wrapper kpi-icon-dark">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <div class="kpi-label kpi-label-dark">Prospects</div>
          <div class="kpi-value">Rp 5.673.237.496</div>
          <div class="kpi-progress-bar">
            <div class="kpi-progress-fill" style="width: 77%; background: #0f172a;"></div>
          </div>
        </div>
        <div class="kpi-footer">
          <span>77%</span>
          <span>56/73</span>
        </div>
      </div>

      <div class="kpi-card">
        <div>
          <div class="kpi-icon-wrapper kpi-icon-red">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <div class="kpi-label">Collections</div>
          <div class="kpi-value">Rp 833.334</div>
          <div class="kpi-progress-bar">
            <div class="kpi-progress-fill" style="width: 4%; background: #ef4444;"></div>
          </div>
        </div>
        <div class="kpi-footer">
          <span>4%</span>
          <span>Rp 833.334/Rp 22.083.333</span>
        </div>
      </div>

      <div class="kpi-card">
        <div>
          <div class="kpi-icon-wrapper kpi-icon-red">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
          </div>
          <div class="kpi-label">NPL</div>
          <div class="kpi-value">100%</div>
        </div>
        <div class="kpi-footer">
          <span>Rasio Kredit Bermasalah</span>
        </div>
      </div>
    </div>
  `;
}

function renderPengajuanSlik() {
  const container = document.getElementById('main-content');
  container.innerHTML = `
    <div class="top-header">
      <div>
        <div class="breadcrumb">
          <span>Credit Checking</span> &gt; <strong style="color:#0f172a;">List Credit Checking</strong>
        </div>
        <h1 class="page-title">List Credit Checking</h1>
      </div>
      <div class="header-actions">
        <button class="icon-btn" title="Mobile Surveyor" onclick="openMobileSurveyor()" style="background: #0284c7; color: white; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
          Mobile
        </button>
        <button class="icon-btn" title="Notifikasi">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        </button>
      </div>
    </div>

    <div class="search-wrapper">
      <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <input type="text" class="search-input" placeholder="Cari berdasarkan nama atau lead id" id="search-slik" oninput="filterPengajuanSlik(this.value)">
    </div>

    <!-- Sub Tabs -->
    <div class="tabs-header">
      <button class="tab-btn active">Credit Checking</button>
      <button class="tab-btn" onclick="alert('Tab Hasil Credit Checking menampilkan rekapan lead selesai.')">Hasil Credit Checking</button>
    </div>

    <!-- Table Card -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <div class="table-container">
        <table class="custom-table" id="table-pengajuan-slik">
          <thead>
            <tr>
              <th>Nama &amp; Leads ID</th>
              <th>No KTP/Telp</th>
              <th>Pengajuan</th>
              <th>Action Lead</th>
              <th>SLIK Pemohon</th>
              <th>SLIK Pasangan</th>
              <th>SLIK Penjamin</th>
              <th style="text-align:center;">Submit</th>
              <th style="text-align:center;">Action Detail</th>
            </tr>
          </thead>
          <tbody>
            ${renderPengajuanRows(state.leads)}
          </tbody>
        </table>
      </div>
    </div>
  `;
}

function renderPengajuanRows(leads) {
  if (!leads || leads.length === 0) {
    return `<tr><td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data pengajuan SLIK</td></tr>`;
  }

  return leads.map(l => {
    return `
      <tr>
        <td>
          <div style="font-weight:800; color:#0f172a;">${l.name}</div>
          <div style="color:#64748b; font-size:12px;">${l.lead_id}</div>
        </td>
        <td>
          <div style="font-weight:700; color:#0f172a;">${l.identity_number}</div>
          <div style="color:#64748b; font-size:12px;">${l.phone_number}</div>
        </td>
        <td>
          <div style="font-weight:700; color:#0f172a;">${formatRupiah(l.nominal_pengajuan)}</div>
        </td>
        <td>
          <span class="badge badge-response">
            <span style="width:6px; height:6px; border-radius:50%; background:#16a34a; display:inline-block;"></span>
            ${l.action_lead}
          </span>
        </td>
        <!-- SLIK Pemohon -->
        <td>
          ${renderSubjectCell(l, 'SLIK_PEMOHON', l.slik_pemohon)}
        </td>
        <!-- SLIK Pasangan -->
        <td>
          ${renderSubjectCell(l, 'SLIK_PASANGAN', l.slik_pasangan)}
        </td>
        <!-- SLIK Penjamin -->
        <td>
          ${renderSubjectCell(l, 'SLIK_PENJAMIN', l.slik_penjamin)}
        </td>
        <!-- Submit Button -->
        <td style="text-align:center;">
          <button class="btn btn-outline btn-sm" style="font-weight:700;" onclick="submitCreditChecking('${l.lead_id}')">
            Submit
          </button>
        </td>
        <!-- Action Detail -->
        <td style="text-align:center;">
          <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
            <button class="btn btn-sm btn-link" title="Buka Detail Leads" onclick="setView('detail-leads', { leadId: '${l.lead_id}', tab: 'credit_checking' })">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function toggleNoSlikResult(leadId, subjectType, checked) {
  const lead = state.leads.find(item => item.lead_id === leadId) || state.selectedLead;
  if (!lead) return;

  const keyMap = {
    'SLIK_PEMOHON': 'no_slik_pemohon',
    'SLIK_PASANGAN': 'no_slik_pasangan',
    'SLIK_PENJAMIN': 'no_slik_penjamin',
    'PEMOHON': 'no_slik_pemohon',
    'PASANGAN': 'no_slik_pasangan',
    'PENJAMIN': 'no_slik_penjamin'
  };

  const key = keyMap[subjectType] || 'no_slik_result';
  lead[key] = checked;

  try {
    const response = await apiCall(`/leads/no-slik/${leadId}`, {
      method: 'PUT',
      body: JSON.stringify({
        subject_type: subjectType,
        no_slik: checked
      })
    });

    if (response.error !== 0) {
      lead[key] = !checked;
      throw new Error(response.message || 'Gagal menyimpan status no_slik');
    }
  } catch (err) {
    console.error('toggleNoSlikResult failed:', err);
    alert(err.message || 'Gagal menyimpan status Tidak ada hasil SLIK');
    return;
  }

  if (checked) {
    if (lead.slik_pemohon && lead.slik_pemohon.has_file) {
      lead.slik_pemohon.has_file = false;
    }
    if (lead.slik_pasangan && lead.slik_pasangan.has_file) {
      lead.slik_pasangan.has_file = false;
    }
    if (lead.slik_penjamin && lead.slik_penjamin.has_file) {
      lead.slik_penjamin.has_file = false;
    }
  }

  if (state.activeView === 'credit-checking') {
    renderPengajuanSlik();
  }
  if (state.activeView === 'detail-leads' && state.selectedLead) {
    renderDetailLeads();
  }
}

function renderSubjectCell(lead, subjectType, info) {
  const noSlikFlag = !!lead[subjectType === 'SLIK_PEMOHON' ? 'no_slik_pemohon' : subjectType === 'SLIK_PASANGAN' ? 'no_slik_pasangan' : 'no_slik_penjamin'];
  const hasFile = !!(info && info.has_file);

  const checkboxHtml = `
    <label class="slik-check-wrap" title="Tidak ada hasil SLIK">
      <input class="slik-check" type="checkbox" ${noSlikFlag ? 'checked' : ''} onchange="toggleNoSlikResult('${lead.lead_id}', '${subjectType}', this.checked)">
      <span class="slik-check-label">Tidak ada hasil SLIK</span>
    </label>
  `;

  if (noSlikFlag) {
    return `
      <div style="display:flex; align-items:center; gap:8px;">
        ${checkboxHtml}
      </div>
    `;
  }

  if (!hasFile) {
    return `
      <div style="display:flex; align-items:center; gap:8px;">
        ${checkboxHtml}
        <button class="btn-link" onclick="openUploadModal('${lead.lead_id}', '${subjectType}', '${info ? info.name : lead.name}', '${info ? info.identity_number : lead.identity_number}')">
          Upload
        </button>
      </div>
    `;
  }

  const statusColor = info.status === 'APPROVED' ? '#16a34a' : (info.status === 'REJECTED' ? '#dc2626' : '#0284c7');
  return `
    <div style="display:flex; flex-direction:column; gap:8px;">
      <div style="font-size:12px; margin-bottom:4px;">
        <span class="badge" style="background:#f1f5f9; color:${statusColor}; padding:2px 8px;">
          ${info.status}
        </span>
      </div>
      <div style="font-size:12px; display:flex; gap:6px;">
        <a href="javascript:void(0)" class="btn-link" onclick="openUploadModal('${lead.lead_id}', '${subjectType}', '${info.name}', '${info.identity_number}')">Ganti</a>
        <span style="color:#cbd5e1;">|</span>
        <a href="javascript:void(0)" class="btn-link" onclick="downloadSlikFile('${info.file_id}')">Download</a>
        <span style="color:#cbd5e1;">|</span>
        <a href="javascript:void(0)" class="btn-link" style="color:#dc2626;" onclick="resetSlikData('${lead.lead_id}', '${subjectType}')">Reset</a>
      </div>
    </div>
  `;
}

function renderDaftarLeads() {
  const container = document.getElementById('main-content');
  container.innerHTML = `
    <div class="top-header">
      <div>
        <div class="breadcrumb">
          <span>Leads</span> &gt; <strong style="color:#0f172a;">List Leads</strong>
        </div>
        <h1 class="page-title">List Leads</h1>
      </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
      <div class="search-wrapper" style="margin-bottom:0;">
        <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" class="search-input" placeholder="Search..." oninput="filterPengajuanSlik(this.value)">
      </div>
      <div style="display:flex; align-items:center; gap:8px;">
        <span style="color:#64748b; font-size:13px; font-weight:600;">Filter By :</span>
        <select class="form-control" style="width:140px; padding:6px 12px;">
          <option>Semua</option>
          <option>Dalam Proses</option>
          <option>Credit Checking</option>
          <option>Proses Survey</option>
        </select>
      </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
      <table class="custom-table">
        <thead>
          <tr>
            <th width="40"><input type="checkbox"></th>
            <th>Nama / Leads Id</th>
            <th>No KTP/Telp</th>
            <th>Pengajuan</th>
            <th>Action Lead</th>
            <th>Status Credit Checking</th>
            <th>Tgl Pengajuan</th>
            <th width="60"></th>
          </tr>
        </thead>
        <tbody>
          ${state.leads.map(l => `
            <tr>
              <td><input type="checkbox"></td>
              <td>
                <div style="font-weight:800; color:#0f172a; cursor:pointer;" onclick="setView('detail-leads', { leadId: '${l.lead_id}' })">${l.name}</div>
                <div style="color:#64748b; font-size:12px;">${l.lead_id}</div>
              </td>
              <td>
                <div style="font-weight:700;">${l.identity_number}</div>
                <div style="color:#64748b; font-size:12px;">${l.phone_number}</div>
              </td>
              <td><strong>${formatRupiah(l.nominal_pengajuan)}</strong></td>
              <td>
                <span class="badge badge-response">
                  <span style="width:6px; height:6px; border-radius:50%; background:#0284c7; display:inline-block;"></span>
                  ${l.action_lead}
                </span>
              </td>
              <td>
                <span class="badge badge-passed">${l.status_credit_checking}</span>
              </td>
              <td style="color:#64748b;">${l.created_dtm}</td>
              <td>
                <button class="btn-link" onclick="setView('detail-leads', { leadId: '${l.lead_id}' })">
                  <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderDetailLeads() {
  const lead = state.selectedLead;
  if (!lead) return;

  const pemohon = lead.pemohon || {};
  const pengajuan = lead.pengajuan || {};
  const ktp = pemohon.ktp || {};
  const slikResults = lead.slik_results || [];

  const container = document.getElementById('main-content');
  container.innerHTML = `
    <div class="top-header">
      <div>
        <div class="breadcrumb">
          <a href="javascript:void(0)" onclick="setView('daftar-leads')">Leads</a> &gt; <strong style="color:#0f172a;">Detail Leads</strong>
        </div>
        <h1 class="page-title">Detail Leads</h1>
        <p class="page-subtitle">${pemohon.name || 'Debitur'} &bull; ${lead._id}</p>
      </div>
      <div class="header-actions">
        <button class="icon-btn" title="Back" onclick="setView('credit-checking')">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        </button>
      </div>
    </div>

    <!-- 6 Tabs matching Mockup -->
    <div class="tabs-header">
      <button class="tab-btn ${state.activeDetailTab === 'pemohon' ? 'active' : ''}" onclick="switchDetailTab('pemohon')">Data Pemohon</button>
      <button class="tab-btn ${state.activeDetailTab === 'pengajuan' ? 'active' : ''}" onclick="switchDetailTab('pengajuan')">Data Pengajuan</button>
      <button class="tab-btn ${state.activeDetailTab === 'pasangan' ? 'active' : ''}" onclick="switchDetailTab('pasangan')">Data Pasangan</button>
      <button class="tab-btn ${state.activeDetailTab === 'penjamin' ? 'active' : ''}" onclick="switchDetailTab('penjamin')">Data Penjamin</button>
      <button class="tab-btn ${state.activeDetailTab === 'agunan' ? 'active' : ''}" onclick="switchDetailTab('agunan')">Data Agunan</button>
      <button class="tab-btn ${state.activeDetailTab === 'credit_checking' ? 'active' : ''}" onclick="switchDetailTab('credit_checking')">Credit Checking</button>
    </div>

    <div id="detail-tab-content">
      ${renderDetailTabContent(lead, state.activeDetailTab, slikResults)}
    </div>
  `;
}

function switchDetailTab(tab) {
  state.activeDetailTab = tab;
  renderDetailLeads();
}

function renderDetailTabContent(lead, tab, slikResults) {
  const pemohon = lead.pemohon || {};
  const ktp = pemohon.ktp || {};
  const pengajuan = lead.pengajuan || {};

  if (tab === 'pemohon') {
    return `
      <div class="card">
        <h3 style="font-size:16px; font-weight:800; margin-bottom:4px; color:#0f172a;">Data Diri Pemohon</h3>
        <p style="color:#64748b; font-size:12.5px; margin-bottom:20px;">Nama, KTP, No HP, Pekerjaan dll.</p>

        <div style="display:grid; grid-template-columns: 200px 1fr; row-gap:14px; font-size:13.5px;">
          <div style="color:#64748b;">Jenis Leads</div>
          <div style="font-weight:700;">${pemohon.user_type?.name || 'Perseorang'}</div>

          <div style="color:#64748b;">No KTP</div>
          <div style="font-weight:700;">${ktp.identity_number || '-'}</div>

          <div style="color:#64748b;">Nama</div>
          <div style="font-weight:700;">${pemohon.title?.name || 'Sdr'} ${pemohon.name}</div>

          <div style="color:#64748b;">No HP</div>
          <div style="font-weight:700;">${pemohon.phone_number || '-'}</div>

          <div style="color:#64748b;">Jenis Kelamin</div>
          <div style="font-weight:700;">${ktp.gender?.name || 'Laki-laki'}</div>

          <div style="color:#64748b;">Tempat / Tgl Lahir</div>
          <div style="font-weight:700;">${ktp.birth_place || '-'}, 1978-03-09</div>

          <div style="color:#64748b;">Agama</div>
          <div style="font-weight:700;">${ktp.religion?.name || 'Islam'}</div>

          <div style="color:#64748b;">Status Pernikahan</div>
          <div style="font-weight:700;">${ktp.marital?.name || 'Kawin'}</div>

          <div style="color:#64748b;">Pekerjaan</div>
          <div style="font-weight:700;">${pemohon.pekerjaan?.name || 'Pedagang'}</div>

          <div style="color:#64748b;">Nama Ibu Kandung</div>
          <div style="font-weight:700;">${pemohon.mother_name || 'Surti'}</div>

          <div style="color:#64748b;">Warga Negara</div>
          <div style="font-weight:700;">${ktp.nationality?.name || 'Warga Negara Indonesia'}</div>

          <div style="color:#64748b;">Alamat Sesuai KTP</div>
          <div style="font-weight:700;">${ktp.address || '-'}, ${ktp.city || ''}, ${ktp.province || ''}</div>
        </div>
      </div>
    `;
  }

  if (tab === 'pengajuan') {
    return `
      <div class="card">
        <h3 style="font-size:16px; font-weight:800; margin-bottom:18px; color:#0f172a;">Data Pengajuan Pinjaman</h3>
        <div style="display:grid; grid-template-columns: 200px 1fr; row-gap:14px; font-size:13.5px;">
          <div style="color:#64748b;">Produk Terpilih</div>
          <div style="font-weight:700;">${pengajuan.product?.product_name || 'Kredit Usaha Rakyat (KUR)'}</div>

          <div style="color:#64748b;">Tujuan Pinjaman</div>
          <div style="font-weight:700;">${pengajuan.loan_purpose?.name || 'Modal Usaha'}</div>

          <div style="color:#64748b;">Nominal Pengajuan</div>
          <div style="font-weight:700; color:#0284c7; font-size:16px;">${formatRupiah(pengajuan.submission?.loan?.$numberDecimal || 80000000)}</div>

          <div style="color:#64748b;">Tenor</div>
          <div style="font-weight:700;">${pengajuan.submission?.tenor || 36} Bulan</div>

          <div style="color:#64748b;">Memiliki Pasangan</div>
          <div style="font-weight:700;">${lead.flg?.spouse ? 'Ya' : 'Tidak'}</div>

          <div style="color:#64748b;">Memiliki Penjamin</div>
          <div style="font-weight:700;">${lead.flg?.guarantee ? 'Ya' : 'Tidak'}</div>
        </div>
      </div>
    `;
  }

  if (tab === 'credit_checking') {
    // Check results for Pemohon, Pasangan, Penjamin
    const resPemohon = slikResults.find(r => r.subject?.type === 'PEMOHON');
    const resPasangan = slikResults.find(r => r.subject?.type === 'PASANGAN');
    const resPenjamin = slikResults.find(r => r.subject?.type === 'PENJAMIN');

    return `
      <div style="margin-bottom:16px;">
        <h3 style="font-size:17px; font-weight:800; color:#0f172a;">Status &amp; Hasil SLIK Reader</h3>
      </div>

      <div style="display:flex; flex-direction:column; gap:16px;">
        <!-- Card Pemohon -->
        ${renderCreditCheckingSubjectCard(lead, 'PEMOHON', 'SLIK Pemohon', pemohon.name, ktp.identity_number, resPemohon)}

        <!-- Card Pasangan -->
        ${renderCreditCheckingSubjectCard(lead, 'PASANGAN', 'SLIK Pasangan', lead.spouse?.name || 'Pasangan Debitur', lead.spouse?.identity_number || '3276020801000002', resPasangan)}

        <!-- Card Penjamin -->
        ${renderCreditCheckingSubjectCard(lead, 'PENJAMIN', 'SLIK Penjamin', lead.lec?.[0]?.name || 'Penjamin Debitur', lead.lec?.[0]?.identity_number || '3276020801000003', resPenjamin)}
      </div>
    `;
  }

  return `<div class="card"><p style="color:#64748b;">Data tidak tersedia untuk tab ini.</p></div>`;
}

function renderCreditCheckingSubjectCard(lead, type, title, name, nik, result) {
  const noSlikFlag = !!lead[type === 'PEMOHON' ? 'no_slik_pemohon' : type === 'PASANGAN' ? 'no_slik_pasangan' : 'no_slik_penjamin'];
  const status = result?.approval?.status || result?.status || '';
  const statusBadge = status === 'APPROVED' ? 'badge-passed' : (status === 'REJECTED' ? 'badge-rejected' : 'badge-progress');
  // "Lihat Detail" is only enabled once the SLIK file is uploaded AND the summary report has been parsed successfully.
  const hasReport = !!(result && result.parser?.status === 'PARSED' && result.ringkasan);

  const checkboxHtml = `
    <label class="slik-check-wrap" title="Tidak ada hasil SLIK">
      <input class="slik-check" type="checkbox" ${noSlikFlag ? 'checked' : ''} onchange="toggleNoSlikResult('${lead._id}', 'SLIK_${type}', this.checked)">
      <span class="slik-check-label">Tidak ada hasil SLIK</span>
    </label>
  `;

  return `
    <div class="card" style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px;">
      <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
          <span style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">${title}</span>
          ${status ? `<span class="badge ${statusBadge}">${status}</span>` : ''}
        </div>
        <div style="font-size:16px; font-weight:800; color:#0f172a; margin-bottom:2px;">${name}</div>
        <div style="font-size:13px; color:#64748b;">No. KTP: <strong>${nik}</strong></div>
      </div>

      <div style="display:flex; align-items:center; gap:12px;">
        ${noSlikFlag ? checkboxHtml : result ? `
          <div style="display:flex; align-items:center; gap:12px;">
            <button class="btn btn-blue btn-sm" ${hasReport ? `onclick="viewSlikReport('${result._id}')"` : 'disabled title="Menunggu hasil summary SLIK terbentuk"'}>
              Lihat Detail
            </button>
            <button class="btn btn-outline btn-sm" onclick="downloadSlikFile('${result.source_file?.file_id}')">
              Download PDF
            </button>
          </div>
        ` : `
          <div style="display:flex; align-items:center; gap:8px;">
            ${checkboxHtml}
            <button class="btn btn-outline btn-sm" onclick="openUploadModal('${lead._id}', 'SLIK_${type}', '${name}', '${nik}')">
              Upload SLIK
            </button>
          </div>
        `}
      </div>
    </div>
  `;
}

function renderAssignSurveyor() {
  const container = document.getElementById('main-content');
  container.innerHTML = `
    <div class="top-header">
      <div>
        <div class="breadcrumb">
          <span>Leads</span> &gt; <strong style="color:#0f172a;">Assign Surveyor</strong>
        </div>
        <h1 class="page-title">Assign Surveyor</h1>
        <p class="page-subtitle">Penugasan surveyor lapangan untuk lead yang lolos credit checking.</p>
      </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
      <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:13.5px; font-weight:700; color:#334155;">Daftar Lead Siap Survey</span>
        <button class="btn btn-primary btn-sm" onclick="alert('Surveyor berhasil di-assign!')">
          Assign Sekaligus
        </button>
      </div>
      <table class="custom-table">
        <thead>
          <tr>
            <th width="40"><input type="checkbox"></th>
            <th>Cabang</th>
            <th>ID Leads</th>
            <th>Nama</th>
            <th>No. KTP / Telp</th>
            <th>Sales</th>
            <th>Tanggal Daftar</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          ${state.leads.map(l => `
            <tr>
              <td><input type="checkbox"></td>
              <td>Cabang Unit 1</td>
              <td><strong>${l.lead_id}</strong></td>
              <td><strong>${l.name}</strong></td>
              <td>${l.identity_number} / ${l.phone_number}</td>
              <td>Dedi Supriadi</td>
              <td>${l.created_dtm}</td>
              <td>
                <div style="display:flex; gap:6px;">
                  <button class="btn btn-outline btn-sm" onclick="alert('Surveyor di-assign untuk ${l.name}')">
                    Assign Surveyor
                  </button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

// Modal Handlers
function openUploadModal(leadId, subjectType, name, nik) {
  state.uploadModal = {
    isOpen: true,
    leadId: leadId,
    subjectType: subjectType,
    subjectName: name,
    identityNumber: nik,
    file: null,
    uploadProgress: 0,
    isUploading: false,
    error: null,
    parseResult: null
  };
  renderModals();
}

function closeUploadModal() {
  state.uploadModal.isOpen = false;
  renderModals();
  // Reload current view
  if (state.activeView === 'credit-checking') loadPengajuanSlik();
  if (state.activeView === 'detail-leads' && state.selectedLead) loadLeadDetail(state.selectedLead._id);
}

// Live Upload with Simulated Progress & Direct API Execution
async function executeUpload(fileInput) {
  const m = state.uploadModal;
  const file = fileInput || m.file;
  if (!file) {
    alert('Silakan pilih file PDF terlebih dahulu');
    return;
  }

  m.isUploading = true;
  m.uploadProgress = 15;
  m.error = null;
  m.parseResult = null;
  renderModals();

  // Progress animation
  const progressInterval = setInterval(() => {
    if (m.uploadProgress < 85) {
      m.uploadProgress += 15;
      renderModals();
    }
  }, 100);

  const formData = new FormData();
  formData.append('file', file);
  formData.append('lead_id', m.leadId);
  formData.append('identity_number', m.identityNumber);
  formData.append('name', m.subjectType);

  try {
    const res = await apiCall('/leads/upload/credit-checking', {
      method: 'POST',
      body: formData
    });

    clearInterval(progressInterval);
    m.uploadProgress = 100;
    m.isUploading = false;

    if (res.error === 0) {
      m.parseResult = res.data;
      m.error = null;
    } else {
      m.error = res.message || 'Terjadi kesalahan saat mengunggah SLIK';
      if (res.error_code === 4004) {
        m.error = 'Data tidak sesuai dengan KTP yang terdaftar, silahkan masukkan data yang sesuai.';
        m.file = null;
        alert(m.error);
      }
    }
  } catch (e) {
    clearInterval(progressInterval);
    m.isUploading = false;
    m.error = 'Gagal menghubungi server: ' + e.message;
  }

  renderModals();
}

// Approve / Reject
async function approveSlik(resultId) {
  try {
    const res = await apiCall(`/leads/slik-reader/result/${resultId}/approve`, {
      method: 'PUT',
      body: JSON.stringify({ note: 'Disetujui dari preview modal' })
    });
    if (res.error === 0) {
      alert('Dokumen SLIK berhasil di-APPROVE!');
      closeUploadModal();
    } else {
      alert('Gagal approve: ' + res.message);
    }
  } catch (e) {
    alert('Error: ' + e.message);
  }
}

function openRejectModal(resultId) {
  state.rejectModal = {
    isOpen: true,
    resultId: resultId,
    reasonCode: 'IDENTITY_MISMATCH',
    note: ''
  };
  renderModals();
}

function closeRejectModal() {
  state.rejectModal.isOpen = false;
  renderModals();
}

async function submitReject() {
  const r = state.rejectModal;
  try {
    const res = await apiCall(`/leads/slik-reader/result/${r.resultId}/reject`, {
      method: 'PUT',
      body: JSON.stringify({
        reason_code: r.reasonCode,
        note: r.note
      })
    });
    if (res.error === 0) {
      alert('Dokumen SLIK berhasil di-REJECT. Data tetap tercatat untuk audit trail.');
      closeRejectModal();
      closeUploadModal();
    } else {
      alert('Gagal reject: ' + res.message);
    }
  } catch (e) {
    alert('Error: ' + e.message);
  }
}

// View Full Report Modal
async function viewSlikReport(resultId) {
  try {
    const res = await apiCall(`/leads/slik-reader/result/${resultId}`, { method: 'GET' });
    if (res.error === 0) {
      state.reportModal = {
        isOpen: true,
        resultId: resultId,
        resultData: res.data
      };
      renderModals();
    }
  } catch (e) {
    alert('Gagal memuat detail laporan SLIK: ' + e.message);
  }
}

function closeReportModal() {
  state.reportModal.isOpen = false;
  renderModals();
}

function downloadSlikFile(fileId) {
  if (!fileId) {
    alert('File ID tidak ditemukan');
    return;
  }
  window.open(API_BASE + `/leads/slik-reader/file/${fileId}/download`, '_blank');
}

function downloadReportFile(resultId, format) {
  window.open(API_BASE + `/leads/slik-reader/result/${resultId}/report?format=${format}`, '_blank');
}

async function resetSlikData(leadId, subjectType) {
  const subjectName = subjectType.replace('SLIK_', '');
  if (confirm(`Apakah Anda yakin ingin mereset data SLIK ${subjectName} untuk lead ${leadId}?\n\nData yang telah diupload akan dihapus.`)) {
    try {
      const res = await apiCall(`/leads/reset-slik/${leadId}`, {
        method: 'POST',
        body: JSON.stringify({
          subject_type: subjectName,
          reset_all: false
        })
      });

      if (res.error === 0) {
        alert(`Data SLIK ${subjectName} berhasil direset!\nAnda dapat mengupload ulang file PDF.`);
        loadPengajuanSlik();
      } else {
        alert(`Gagal mereset data: ${res.message}`);
      }
    } catch (err) {
      console.error('Reset SLIK failed:', err);
      alert(`Gagal mereset data SLIK: ${err.message}`);
    }
  }
}

async function submitCreditChecking(leadId) {
  if (confirm(`Apakah Anda yakin ingin menyelesaikan proses credit checking untuk lead ${leadId}?`)) {
    const res = await apiCall(`/leads/set-done/credit-checking/${leadId}`, { method: 'PUT' });
    if (res.error === 0) {
      alert('Credit checking selesai! Status lead berhasil diperbarui.');
      loadPengajuanSlik();
    }
  }
}

// Render All Modals
function renderModals() {
  let modalContainer = document.getElementById('modal-container');
  if (!modalContainer) {
    modalContainer = document.createElement('div');
    modalContainer.id = 'modal-container';
    document.body.appendChild(modalContainer);
  }

  let html = '';

  // 1. Upload Modal
  if (state.uploadModal.isOpen) {
    const m = state.uploadModal;
    html += `
      <div class="modal-overlay">
        <div class="modal-content modal-content-lg">
          <div class="modal-header">
            <h3 class="modal-title">Upload Dokumen SLIK iDeb OJK</h3>
            <button class="btn-link" onclick="closeUploadModal()">&times;</button>
          </div>
          <div class="modal-body">
            <div style="background:#f1f5f9; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13px;">
              <strong>Pihak:</strong> ${m.subjectType} &bull; <strong>Nama:</strong> ${m.subjectName} &bull; <strong>No KTP:</strong> ${m.identityNumber}
            </div>

            <!-- Drag Drop Area -->
            <div class="dropzone" onclick="document.getElementById('slik-file-input').click()">
              <input type="file" id="slik-file-input" accept="application/pdf" style="display:none;" onchange="executeUpload(this.files[0])">
              <svg class="dropzone-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
              <div style="font-weight:700; color:#0f172a; margin-bottom:4px;">Klik untuk memilih file atau seret file PDF ke sini</div>
              <div style="color:#64748b; font-size:12px;">Maksimal ukuran file: 20MB (Hanya format PDF)</div>
            </div>

            <!-- Progress Bar -->
            ${m.isUploading || m.uploadProgress > 0 ? `
              <div class="progress-container">
                <div class="progress-header">
                  <span>Proses Upload &amp; Parsing iDeb...</span>
                  <span>${m.uploadProgress}%</span>
                </div>
                <div class="progress-track">
                  <div class="progress-fill" style="width:${m.uploadProgress}%"></div>
                </div>
              </div>
            ` : ''}

            <!-- Error Notification -->
            ${m.error ? `
              <div class="alert alert-danger">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div>
                  <strong>Validasi Gagal!</strong><br>
                  ${m.error}
                </div>
              </div>
            ` : ''}

            <!-- Success Summary Preview -->
            ${m.parseResult ? `
              <div class="alert alert-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <div>
                  <strong>Dokumen SLIK Valid &amp; Berhasil Diekstrak!</strong><br>
                  NIK cocok dengan data subject (${m.parseResult.metadata?.no_identitas || ''})
                </div>
              </div>

              <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin-top:16px;">Ringkasan Hasil Parsing SLIK</h4>
              <div class="summary-grid">
                <div class="summary-card">
                  <div class="summary-card-label">Nama Debitur</div>
                  <div class="summary-card-value">${m.parseResult.metadata?.nama_debitur || '-'}</div>
                </div>
                <div class="summary-card">
                  <div class="summary-card-label">Nomor Laporan iDeb</div>
                  <div class="summary-card-value">${m.parseResult.metadata?.no_laporan || '-'}</div>
                </div>
                <div class="summary-card">
                  <div class="summary-card-label">Plafon Efektif Total</div>
                  <div class="summary-card-value">${formatRupiah(m.parseResult.ringkasan?.plafon_efektif_total)}</div>
                </div>
                <div class="summary-card">
                  <div class="summary-card-label">Baki Debet Total</div>
                  <div class="summary-card-value">${formatRupiah(m.parseResult.ringkasan?.baki_debet_total)}</div>
                </div>
                <div class="summary-card">
                  <div class="summary-card-label">Utilisasi Plafon</div>
                  <div class="summary-card-value">${Math.round((m.parseResult.ringkasan?.utilisasi_plafon || 0) * 100)}%</div>
                </div>
                <div class="summary-card">
                  <div class="summary-card-label">Kualitas Terburuk</div>
                  <div class="summary-card-value" style="color:#16a34a;">${m.parseResult.ringkasan?.kualitas_terburuk || '1 - Lancar'}</div>
                </div>
              </div>
            ` : ''}
          </div>

          <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeUploadModal()">Tutup</button>
            ${m.parseResult ? `
              <button class="btn btn-danger" onclick="openRejectModal('${m.parseResult._id}')">Reject</button>
              <button class="btn btn-success" onclick="approveSlik('${m.parseResult._id}')">Approve</button>
            ` : ''}
          </div>
        </div>
      </div>
    `;
  }

  // 2. Reject Modal
  if (state.rejectModal.isOpen) {
    const r = state.rejectModal;
    html += `
      <div class="modal-overlay" style="z-index:1100;">
        <div class="modal-content" style="max-width:480px;">
          <div class="modal-header">
            <h3 class="modal-title" style="color:#dc2626;">Reject Dokumen SLIK</h3>
            <button class="btn-link" onclick="closeRejectModal()">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="form-label">Alasan Penolakan (Reason Code)</label>
              <select class="form-control" id="reject-reason" onchange="state.rejectModal.reasonCode = this.value">
                <option value="IDENTITY_MISMATCH">IDENTITY_MISMATCH - NIK / Data tidak sesuai</option>
                <option value="HIGH_NPL">HIGH_NPL - Riwayat kredit macet / skor buruk</option>
                <option value="OVER_LIMIT">OVER_LIMIT - Utilisasi plafon melebihi ketentuan</option>
                <option value="UNREADABLE_DOC">UNREADABLE_DOC - Dokumen buram / tidak valid</option>
                <option value="OTHER">OTHER - Alasan lainnya</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Catatan Reviewer (Optional)</label>
              <textarea class="form-control" rows="3" placeholder="Masukkan catatan penolakan..." oninput="state.rejectModal.note = this.value"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeRejectModal()">Batal</button>
            <button class="btn btn-danger" onclick="submitReject()">Konfirmasi Reject</button>
          </div>
        </div>
      </div>
    `;
  }

  // 3. Full Report Modal
  if (state.reportModal.isOpen) {
    const d = state.reportModal.resultData;
    const meta = d?.metadata || {};
    const ringkasan = d?.ringkasan || {};
    const fasilitas = d?.fasilitas || [];
    const dataPokok = d?.data_pokok || [];
    const approval = d?.approval || {};

    html += `
      <div class="modal-overlay">
        <div class="modal-content modal-content-lg" style="max-width: 1180px;">
          <div class="modal-header">
            <div>
              <h3 class="modal-title">Detail Hasil Pembacaan SLIK</h3>
              <p style="font-size:12px; color:#64748b; margin-top:2px;">${meta.nama_debitur || '-'} &bull; NIK: ${meta.no_identitas || '-'} &bull; Status: <span class="report-badge">${approval.status || d?.status || 'PENDING'}</span></p>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
              <button class="btn btn-outline btn-sm" onclick="downloadReportFile('${d?.result_id}', 'pdf')">Export PDF</button>
              <button class="btn btn-outline btn-sm" onclick="downloadReportFile('${d?.result_id}', 'xlsx')">Export Excel</button>
              <button class="btn-link" onclick="closeReportModal()">&times;</button>
            </div>
          </div>

          <div class="modal-body" style="padding-top:12px;">
            <div class="report-layout">
              <div class="report-grid-2">
                <div class="report-panel">
                  <div class="report-section-label">Data Nasabah</div>
                  <div class="report-kv">
                    <div class="report-kv-key">Nama</div>
                    <div class="report-kv-value">${meta.nama_debitur || '-'}</div>

                    <div class="report-kv-key">No Identitas</div>
                    <div class="report-kv-value">${meta.no_identitas || '-'}</div>

                    <div class="report-kv-key">No Laporan</div>
                    <div class="report-kv-value">${meta.no_laporan || '-'}</div>

                    <div class="report-kv-key">Tanggal Req</div>
                    <div class="report-kv-value">${meta.tanggal_permintaan || '-'}</div>

                    <div class="report-kv-key">Tanggal Lahir</div>
                    <div class="report-kv-value">${meta.tanggal_lahir || '-'}</div>

                    <div class="report-kv-key">Jenis Kelamin</div>
                    <div class="report-kv-value">${meta.jenis_kelamin || '-'}</div>

                    <div class="report-kv-key">NPWP</div>
                    <div class="report-kv-value">${meta.npwp || '-'}</div>

                    <div class="report-kv-key">Alamat</div>
                    <div class="report-kv-value">${meta.alamat || '-'}</div>

                    <div class="report-kv-key">Posisi Data</div>
                    <div class="report-kv-value">${meta.posisi_data_terakhir || '-'}</div>
                  </div>
                </div>

                <div class="report-panel">
                  <div class="report-section-label">Ringkasan Fasilitas</div>
                  <div class="report-stat-grid">
                    <div class="report-stat-card">
                      <span class="report-stat-title">Plafon Efektif Total</span>
                      <div class="report-stat-value">${formatRupiah(ringkasan.plafon_efektif_total)}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Baki Debet Total</span>
                      <div class="report-stat-value">${formatRupiah(ringkasan.baki_debet_total)}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Utilisasi Plafon</span>
                      <div class="report-stat-value">${Math.round((ringkasan.utilisasi_plafon || 0) * 100)}%</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Kualitas Terburuk</span>
                      <div class="report-stat-value" style="color:#16a34a;">${ringkasan.kualitas_terburuk || '1 - Lancar'}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Kredit Bank Umum</span>
                      <div class="report-stat-value">${ringkasan.kredit_bank_umum || 0}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Kredit BPR</span>
                      <div class="report-stat-value">${ringkasan.kredit_bpr || 0}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Kredit LP</span>
                      <div class="report-stat-value">${ringkasan.kredit_lp || 0}</div>
                    </div>
                    <div class="report-stat-card">
                      <span class="report-stat-title">Kredit Lainnya</span>
                      <div class="report-stat-value">${ringkasan.kredit_lainnya || 0}</div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="report-panel">
                <div class="report-section-label">Data Pokok Debitur</div>
                <div class="report-table-wrap">
                  <table class="custom-table" style="font-size:12.5px;">
                    <thead>
                      <tr>
                        <th>Pelapor</th>
                        <th>Tanggal Update</th>
                        <th>Alamat</th>
                        <th>Pekerjaan</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${dataPokok.length ? dataPokok.map(item => `
                        <tr>
                          <td><strong>${item.pelapor || '-'}</strong></td>
                          <td>${item.tanggal_update || '-'}</td>
                          <td>${item.alamat || '-'}</td>
                          <td>${item.pekerjaan || '-'}</td>
                        </tr>
                      `).join('') : '<tr><td colspan="4" style="text-align:center; color:#94a3b8;">Data pokok tidak tersedia</td></tr>'}
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="report-panel">
                <div class="report-section-label">Data Kredit Pembiayaan</div>
                <div class="report-table-wrap">
                  <table class="custom-table" style="font-size:12.5px;">
                    <thead>
                      <tr>
                        <th>Pelapor</th>
                        <th>Cabang</th>
                        <th>Jenis Fasilitas</th>
                        <th>Plafon</th>
                        <th>Baki Debet</th>
                        <th>Kualitas</th>
                        <th>Kondisi</th>
                        <th>Tgl Kondisi</th>
                        <th>Tgl Mulai</th>
                        <th>Tgl Jatuh Tempo</th>
                        <th>Update</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${fasilitas.length ? fasilitas.map(item => `
                        <tr>
                          <td><strong>${item.pelapor || '-'}</strong></td>
                          <td>${item.cabang || '-'}</td>
                          <td>${item.jenis_fasilitas || '-'}</td>
                          <td>${formatRupiah(item.plafon)}</td>
                          <td>${formatRupiah(item.baki_debet)}</td>
                          <td><span class="badge badge-passed">${item.kualitas || '1 - Lancar'}</span></td>
                          <td>${item.kondisi || '-'}</td>
                          <td>${item.tgl_kondisi || '-'}</td>
                          <td>${item.tgl_mulai || '-'}</td>
                          <td>${item.tgl_jatuh_tempo || '-'}</td>
                          <td>${item.update || '-'}</td>
                        </tr>
                      `).join('') : '<tr><td colspan="11" style="text-align:center; color:#94a3b8;">Data fasilitas tidak tersedia</td></tr>'}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-primary" onclick="closeReportModal()">Tutup</button>
          </div>
        </div>
      </div>
    `;
  }

  modalContainer.innerHTML = html;
}

// Open Mobile Surveyor
function openMobileSurveyor() {
  window.open('/mobile_surveyor.html', '_blank');
}

// Initial boot
window.addEventListener('DOMContentLoaded', () => {
  setView('credit-checking');
});
