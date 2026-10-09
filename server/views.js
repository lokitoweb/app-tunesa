/**
 * TuneSA Web Views — Design System conforming to panel.css and Blade templates
 */

function layout(title, currentUser, activeNav, bodyContent) {
  return `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${title} — TuneSA SaaS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <link rel="stylesheet" href="/css/panel.css">
  <style>
    .app-layout { display: flex; min-height: 100vh; }
    .sidebar { width: 260px; background: #0d0e16; border-right: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; flex-shrink: 0; }
    .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .sidebar-nav { padding: 1rem 0; flex: 1; }
    .nav-section-title { font-size: 0.7rem; font-weight: 700; color: #475569; padding: 0.75rem 1.5rem 0.25rem 1.5rem; letter-spacing: 0.5px; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 0.75rem 1.5rem; color: #94a3b8; text-decoration: none; font-size: 0.88rem; font-weight: 500; transition: all 0.2s; }
    .nav-item:hover, .nav-item.active { color: #fff; background: rgba(99,102,241,0.12); border-left: 3px solid #6366f1; }
    .sidebar-footer { padding: 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); }
    .main-wrapper { flex: 1; display: flex; flex-direction: column; min-width: 0; background: #0a0b0f; }
    .topbar { height: 64px; border-bottom: 1px solid rgba(255,255,255,0.08); background: rgba(17,18,24,0.8); backdrop-filter: blur(12px); display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; position: sticky; top: 0; z-index: 100; }
    .content-container { padding: 2rem; flex: 1; }
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
    .kpi-card { background: #111218; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 1.5rem; }
    .card-panel { background: #111218; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; overflow: hidden; }
    .card-panel-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: space-between; align-items: center; }
    .badge { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    .badge-success { background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
    .badge-warning { background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
    .badge-danger { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
    .badge-info { background: rgba(99,102,241,0.15); color: #818cf8; border: 1px solid rgba(99,102,241,0.3); }
    .btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; border-radius: 10px; padding: 0.65rem 1.25rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
    .btn-secondary { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #cbd5e1; border-radius: 10px; padding: 0.65rem 1.25rem; font-weight: 600; cursor: pointer; text-decoration: none; }
  </style>
</head>
<body>
  <div class="app-layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <a href="/dashboard" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 12px;">
          <div style="width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: 0 4px 15px rgba(99,102,241,0.4);">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><circle cx="12" cy="11" r="3"/></svg>
          </div>
          <div>
            <div style="font-size: 1.15rem; font-weight: 800; letter-spacing: -0.5px; color: #fff;">TuneSA</div>
            <div style="font-size: 0.68rem; text-transform: uppercase; color: #818cf8; font-weight: 600;">Control Geocercas</div>
          </div>
        </a>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section-title">MONITOREO & ASISTENCIA</div>
        <a href="/dashboard" class="nav-item ${activeNav === 'dashboard' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
          <span>Dashboard en Vivo</span>
        </a>
        <a href="/attendance" class="nav-item ${activeNav === 'attendance' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <span>Marcaciones & GPS</span>
        </a>
        <a href="/simulator" class="nav-item ${activeNav === 'simulator' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-7.5 16.6l-.5 3.4 3.4-.5A10 10 0 1 0 12 2zm0 14a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
          <span style="color: #60a5fa; font-weight: 600;">⚡ Simulador Móvil GPS</span>
        </a>

        <div class="nav-section-title">ADMINISTRACIÓN & RRHH</div>
        <a href="/employees" class="nav-item ${activeNav === 'employees' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
          <span>Colaboradores</span>
        </a>
        <a href="/branches" class="nav-item ${activeNav === 'branches' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>Sucursales & Geocercas</span>
        </a>
        <a href="/payslips" class="nav-item ${activeNav === 'payslips' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span>Boletas de Pago</span>
        </a>
        <a href="/reports" class="nav-item ${activeNav === 'reports' ? 'active' : ''}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>
          <span>Reportes & Nómina</span>
        </a>
      </nav>

      <div class="sidebar-footer">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.75rem;">
          <div style="width: 36px; height: 36px; border-radius: 50%; background: #3730a3; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #c7d2fe; font-size: 0.85rem;">
            ${currentUser ? currentUser.avatarInitials : 'SA'}
          </div>
          <div style="overflow: hidden;">
            <div style="font-size: 0.85rem; font-weight: 600; color: #fff; white-space: nowrap; text-overflow: ellipsis; overflow: hidden;">
              ${currentUser ? currentUser.name : 'Super Admin'}
            </div>
            <div style="font-size: 0.72rem; color: #818cf8; text-transform: uppercase;">
              ${currentUser ? currentUser.roleLabel : 'Super Admin'}
            </div>
          </div>
        </div>
        <form method="POST" action="/logout">
          <button type="submit" id="btnLogout" style="width: 100%; padding: 0.5rem; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); border-radius: 8px; color: #f87171; font-size: 0.8rem; font-weight: 500; cursor: pointer;">
            Cerrar Sesión
          </button>
        </form>
      </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <main class="main-wrapper">
      <header class="topbar">
        <div style="display: flex; align-items: center; gap: 12px;">
          <span style="font-size: 0.78rem; text-transform: uppercase; background: rgba(99,102,241,0.15); color: #818cf8; padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(99,102,241,0.25); font-weight: 600;">
            Tenant: ${currentUser ? currentUser.tenant : 'Global SaaS'}
          </span>
          <span style="color: #475569;">•</span>
          <span style="font-size: 0.85rem; color: #94a3b8;">
            Lima, Perú • UTC-5
          </span>
        </div>
        <div style="display: flex; align-items: center; gap: 16px;">
          <a href="/simulator" style="text-decoration: none; font-size: 0.8rem; background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); color: #93c5fd; padding: 5px 12px; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
            <span>📱 Fichar en Simulador</span>
          </a>
          <div style="display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: #10b981;">
            <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
            Motor GPS Haversine Activo
          </div>
        </div>
      </header>

      <div class="content-container">
        ${bodyContent}
      </div>
    </main>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>`;
}

function renderLogin(errorMessage = '', superAdminEmail = 'superadmin@tunesa.app', superAdminPass = 'SuperAdmin2024!') {
  return `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Acceso — TuneSA Multi-Tenant</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #0a0b0f; color: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .auth-card { width: 100%; max-width: 440px; background: rgba(17,18,24,0.9); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 2.5rem; box-shadow: 0 20px 50px rgba(0,0,0,0.6); }
    .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 2rem; justify-content: center; }
    .brand-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px; }
    .form-group { margin-bottom: 1.25rem; }
    .form-label { display: block; font-size: 0.85rem; font-weight: 500; color: #94a3b8; margin-bottom: 0.5rem; }
    .form-control { width: 100%; padding: 0.85rem 1rem; background: rgba(26,28,38,0.7); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; color: #fff; font-size: 0.95rem; }
    .form-control:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.25); }
    .btn-submit { width: 100%; padding: 0.9rem; border: none; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer; margin-top: 0.5rem; box-shadow: 0 4px 15px rgba(99,102,241,0.35); }
    .error-alert { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); border-radius: 12px; padding: 0.85rem 1rem; color: #fca5a5; font-size: 0.875rem; margin-bottom: 1.25rem; }
    .quick-creds { margin-top: 1.5rem; padding: 1rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; font-size: 0.78rem; color: #94a3b8; }
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="brand">
      <div class="brand-icon">📍</div>
      <div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #fff;">TuneSA SaaS</div>
        <div style="font-size: 0.72rem; color: #94a3b8; letter-spacing: 0.5px;">PLATAFORMA MULTI-TENANT</div>
      </div>
    </div>

    ${errorMessage ? `<div class="error-alert">${errorMessage}</div>` : ''}

    <form method="POST" action="/login" id="loginForm">
      <div class="form-group">
        <label class="form-label" for="tenantSelect">Entorno de Empresa (Tenant)</label>
        <select name="tenant" id="tenantSelect" class="form-control">
          <option value="global">TuneSA Global SaaS (Matriz Super Admin)</option>
          <option value="alfa">Constructora Alfa S.A. (Empresa Tenant)</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="emailInput">Correo Electrónico</label>
        <input type="email" id="emailInput" name="email" value="${superAdminEmail}" required class="form-control">
      </div>

      <div class="form-group">
        <label class="form-label" for="passwordInput">Contraseña</label>
        <input type="password" id="passwordInput" name="password" value="${superAdminPass}" required class="form-control">
      </div>

      <button type="submit" id="btnSubmitLogin" class="btn-submit">
        Ingresar al Panel de Control
      </button>
    </form>

    <div class="quick-creds">
      <div style="font-weight: 700; color: #fff; margin-bottom: 4px;">🛡️ Credenciales de Acceso:</div>
      <div>Super Admin: <strong style="color: #818cf8;">${superAdminEmail}</strong> / <strong style="color: #818cf8;">${superAdminPass}</strong></div>
      <div style="margin-top: 4px;">Company Admin: <strong style="color: #818cf8;">admin@empresa-demo.com</strong> / <strong style="color: #818cf8;">Admin2024!</strong></div>
    </div>
  </div>
</body>
</html>`;
}

function renderDashboard(user, db) {
  const branchesJson = JSON.stringify(db.branches);
  const attendancesJson = JSON.stringify(db.attendances);

  const presentCount = db.attendances.length;
  const lateCount = db.attendances.filter(a => a.status === 'late').length;

  const content = `
    <div style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Panel de Control en Vivo</h1>
        <p style="color: #94a3b8; font-size: 0.92rem;">Monitoreo satelital de geocercas activas y flujo de colaboradores en tiempo real.</p>
      </div>
      <div style="display: flex; align-items: center; gap: 12px;">
        <a href="/simulator" class="btn-primary" style="font-size: 0.85rem;">
          ⚡ Probar Simulador GPS
        </a>
      </div>
    </div>

    <!-- KPIS -->
    <div class="kpi-grid">
      <div class="kpi-card" style="border-top: 3px solid #10b981;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;">PRESENTES HOY</span>
          <span style="color: #10b981; font-size: 1.2rem;">✓</span>
        </div>
        <div style="font-size: 2.2rem; font-weight: 800; color: #fff;" id="kpiPresent">${presentCount}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;"><span style="color: #10b981; font-weight: 700;">${Math.round((presentCount / db.employees.length) * 100)}%</span> del personal activo</div>
      </div>

      <div class="kpi-card" style="border-top: 3px solid #f59e0b;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;">TARDANZAS HOY</span>
          <span style="color: #f59e0b; font-size: 1.2rem;">⏱</span>
        </div>
        <div style="font-size: 2.2rem; font-weight: 800; color: #fbbf24;" id="kpiLate">${lateCount}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">Con retardo registrado</div>
      </div>

      <div class="kpi-card" style="border-top: 3px solid #3b82f6;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;">SUCURSALES & OBRAS</span>
          <span style="color: #3b82f6; font-size: 1.2rem;">📍</span>
        </div>
        <div style="font-size: 2.2rem; font-weight: 800; color: #60a5fa;" id="kpiBranches">${db.branches.length}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">Geocercas activas</div>
      </div>

      <div class="kpi-card" style="border-top: 3px solid #8b5cf6;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;">TOTAL COLABORADORES</span>
          <span style="color: #8b5cf6; font-size: 1.2rem;">👥</span>
        </div>
        <div style="font-size: 2.2rem; font-weight: 800; color: #c084fc;" id="kpiTotalEmp">${db.employees.length}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">En planilla</div>
      </div>
    </div>

    <!-- MAPA LEAFLET & STREAM -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
      <div class="card-panel">
        <div class="card-panel-header">
          <div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #fff;">Mapa Satelital de Geocercas (Lima Metropolitana)</h3>
            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">Círculos: Geocercas autorizadas | Pines: Fichajes GPS con selfies</p>
          </div>
          <span class="badge badge-info">${db.branches.length} Geocercas Vivas</span>
        </div>
        <div id="liveMap" style="height: 520px; width: 100%; background: #0a0b0f;"></div>
      </div>

      <div class="card-panel">
        <div class="card-panel-header">
          <div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #fff;">Marcaciones Recientes</h3>
            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">Clic en una para ver auditoría forense</p>
          </div>
        </div>
        <div style="max-height: 520px; overflow-y: auto;" id="streamList">
          ${db.attendances.map(att => `
            <a href="/attendance/${att.id}" style="text-decoration: none; display: flex; align-items: center; gap: 12px; padding: 1rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">
              <div style="width: 44px; height: 44px; border-radius: 10px; overflow: hidden; background: #1e1b4b; flex-shrink: 0; border: 2px solid ${att.withinGeo ? '#10b981' : '#ef4444'};">
                <img src="${att.clockInPhoto}" style="width: 100%; height: 100%; object-fit: cover;" alt="${att.employee}">
              </div>
              <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 600; color: #fff; font-size: 0.9rem;">${att.employee}</div>
                <div style="font-size: 0.75rem; color: #94a3b8;">
                  ${att.branch} • <span style="color: ${att.withinGeo ? '#10b981' : '#ef4444'}; font-weight: 600;">${att.distance}m</span>
                </div>
              </div>
              <div style="text-align: right;">
                <div style="font-weight: 700; color: #fff; font-size: 0.85rem;">${att.clockIn}</div>
                <span class="badge ${att.status === 'on_time' ? 'badge-success' : 'badge-warning'}">
                  ${att.status === 'on_time' ? 'Puntual' : 'Tardanza'}
                </span>
              </div>
            </a>
          `).join('')}
        </div>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const branches = ${branchesJson};
        const attendances = ${attendancesJson};

        const map = L.map('liveMap').setView([-12.08, -77.06], 12);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
          maxZoom: 19
        }).addTo(map);

        branches.forEach(b => {
          L.circle([b.lat, b.lng], {
            color: '#6366f1',
            fillColor: '#6366f1',
            fillOpacity: 0.18,
            weight: 2,
            radius: b.radius
          }).addTo(map).bindPopup('<b>📍 ' + b.name + '</b><br>' + b.address + '<br>Radio autoriz.: ' + b.radius + 'm');

          L.marker([b.lat, b.lng]).addTo(map);
        });

        attendances.forEach(att => {
          const color = att.withinGeo ? '#10b981' : '#ef4444';
          const icon = L.divIcon({
            className: 'emp-marker',
            html: '<div style="width: 22px; height: 22px; border-radius: 50%; background: ' + color + '; border: 3px solid #fff; box-shadow: 0 0 10px ' + color + ';"></div>',
            iconSize: [22, 22],
            iconAnchor: [11, 11]
          });

          L.marker([att.clockInLat, att.clockInLng], { icon: icon }).addTo(map)
            .bindPopup('<b>' + att.employee + '</b><br>Hora: ' + att.clockIn + '<br>Distancia: ' + att.distance + 'm<br><span style="color:' + color + ';">' + (att.withinGeo ? '✓ Dentro de Geocerca' : '⚠ Fuera de Geocerca') + '</span><br><a href="/attendance/' + att.id + '" style="color:#818cf8;">Ver Auditoría Forense →</a>');
        });
      });
    </script>
  `;
  return layout('Dashboard en Vivo', user, 'dashboard', content);
}

function renderAttendanceList(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Auditoría Forense de Marcaciones</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Verificación satelital de distancias a geocerca y selfies en tiempo real.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <a href="/simulator" class="btn-primary">⚡ Fichar en Simulador</a>
        <a href="/reports/export/csv" class="btn-secondary">📥 Exportar CSV</a>
      </div>
    </div>

    <div class="card-panel">
      <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
        <thead>
          <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); color: #94a3b8;">
            <th style="padding: 1rem 1.25rem;">Colaborador</th>
            <th style="padding: 1rem;">Sucursal / Obra</th>
            <th style="padding: 1rem;">Hora Entrada</th>
            <th style="padding: 1rem;">Distancia Satelital</th>
            <th style="padding: 1rem;">Cumplimiento Geocerca</th>
            <th style="padding: 1rem;">Estado</th>
            <th style="padding: 1rem; text-align: right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          ${db.attendances.map(att => `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
              <td style="padding: 1rem 1.25rem;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <img src="${att.clockInPhoto}" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid ${att.withinGeo ? '#10b981' : '#ef4444'};">
                  <div>
                    <div style="font-weight: 600; color: #fff;">${att.employee}</div>
                    <div style="font-size: 0.72rem; color: #94a3b8;">${att.workDate}</div>
                  </div>
                </div>
              </td>
              <td style="padding: 1rem; color: #94a3b8;">${att.branch}</td>
              <td style="padding: 1rem; color: #cbd5e1; font-weight: 600;">${att.clockIn}</td>
              <td style="padding: 1rem; color: ${att.withinGeo ? '#10b981' : '#ef4444'}; font-weight: 700;">${att.distance} metros</td>
              <td style="padding: 1rem;">
                <span class="badge ${att.withinGeo ? 'badge-success' : 'badge-danger'}">
                  ${att.withinGeo ? '✓ Dentro del Radio' : '⚠ Fuera de Rango'}
                </span>
              </td>
              <td style="padding: 1rem;">
                <span class="badge ${att.status === 'on_time' ? 'badge-success' : (att.status === 'late' ? 'badge-warning' : 'badge-info')}">
                  ${att.status === 'on_time' ? 'Puntual' : (att.status === 'late' ? 'Tardanza' : 'Justificado')}
                </span>
              </td>
              <td style="padding: 1rem; text-align: right;">
                <a href="/attendance/${att.id}" class="badge badge-info" style="text-decoration: none;">
                  Ver Auditoría →
                </a>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
  return layout('Marcaciones & GPS', user, 'attendance', content);
}

function renderAttendanceShow(user, attendance, branch) {
  const branchLat = branch ? branch.lat : -12.09418;
  const branchLng = branch ? branch.lng : -77.03560;
  const branchRadius = branch ? branch.radius : 50;

  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
      <div>
        <a href="/attendance" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
          ← Volver al listado de asistencias
        </a>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; letter-spacing: -0.5px;">Auditoría de Marcación #${attendance.id}</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Fecha: ${attendance.workDate} • Colaborador: ${attendance.employee}</p>
      </div>
      <div>
        <span class="badge ${attendance.status === 'on_time' ? 'badge-success' : (attendance.status === 'late' ? 'badge-warning' : 'badge-info')}" style="font-size: 0.85rem; padding: 6px 14px;">
          ESTADO: ${attendance.status.toUpperCase()}
        </span>
      </div>
    </div>

    <!-- DUAL CARDS: CLOCK-IN & CLOCK-OUT -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
      <!-- CARD ENTRADA -->
      <div class="card-panel" style="padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
          <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></span>
            Marcación de Entrada (Clock-In)
          </h3>
          <span class="badge ${attendance.withinGeo ? 'badge-success' : 'badge-danger'}">
            ${attendance.withinGeo ? '✓ Geocerca Válida' : '⚠ Fuera de Geocerca'}
          </span>
        </div>

        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 1.25rem; align-items: start;">
          <div>
            <img src="${attendance.clockInPhoto}" style="width: 100%; height: 160px; object-fit: cover; border-radius: 12px; border: 2px solid ${attendance.withinGeo ? '#10b981' : '#ef4444'};">
            <div style="font-size: 0.72rem; color: #818cf8; text-align: center; margin-top: 4px;">Selfie de verificación</div>
          </div>
          <div style="font-size: 0.88rem; line-height: 1.8;">
            <div><span style="color: #94a3b8;">Hora exacta:</span> <strong style="color: #fff;">${attendance.clockIn}</strong></div>
            <div><span style="color: #94a3b8;">Distancia al centro:</span> <strong style="color: ${attendance.withinGeo ? '#10b981' : '#ef4444'};">${attendance.distance} metros</strong> (Máx. permitido: ${branchRadius}m)</div>
            <div><span style="color: #94a3b8;">Coordenadas GPS:</span> <span style="font-family: monospace; color: #cbd5e1;">${attendance.clockInLat}, ${attendance.clockInLng}</span></div>
            <div><span style="color: #94a3b8;">Tardanza:</span> <strong style="color: ${attendance.lateMinutes > 0 ? '#f59e0b' : '#10b981'};">${attendance.lateMinutes} minutos</strong></div>
            <div><span style="color: #94a3b8;">Dispositivo:</span> <span style="font-family: monospace; color: #94a3b8;">App Móvil Biometría</span></div>
          </div>
        </div>
      </div>

      <!-- CARD SALIDA -->
      <div class="card-panel" style="padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
          <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></span>
            Marcación de Salida (Clock-Out)
          </h3>
          <span class="badge ${attendance.clockOut ? 'badge-success' : 'badge-warning'}">
            ${attendance.clockOut ? '✓ Registrada' : 'Pendiente'}
          </span>
        </div>

        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 1.25rem; align-items: start;">
          <div>
            ${attendance.clockOutPhoto ? `
              <img src="${attendance.clockOutPhoto}" style="width: 100%; height: 160px; object-fit: cover; border-radius: 12px; border: 2px solid #10b981;">
              <div style="font-size: 0.72rem; color: #818cf8; text-align: center; margin-top: 4px;">Selfie de salida</div>
            ` : `
              <div style="width: 100%; height: 160px; border-radius: 12px; background: #1a1c26; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 0.8rem; text-align: center; padding: 10px;">
                Sin marcación aún
              </div>
            `}
          </div>
          <div style="font-size: 0.88rem; line-height: 1.8;">
            <div><span style="color: #94a3b8;">Hora exacta:</span> <strong style="color: #fff;">${attendance.clockOut || '--:--:--'}</strong></div>
            <div><span style="color: #94a3b8;">Horas computadas:</span> <strong style="color: #fff;">${attendance.workedHours || 0} hrs</strong></div>
            <div><span style="color: #94a3b8;">Horas extras:</span> <strong style="color: #60a5fa;">${attendance.overtimeHours || 0} hrs</strong></div>
            <div><span style="color: #94a3b8;">Estado del turno:</span> <span style="color: #cbd5e1;">${attendance.clockOut ? 'Jornada completada' : 'En jornada laboral'}</span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- MAPA FORENSE CON POLYLINE -->
    <div class="card-panel" style="margin-bottom: 1.5rem;">
      <div class="card-panel-header">
        <div>
          <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700;">Validación Geográfica Forense</h3>
          <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">Círculo: Geocerca de ${branch ? branch.name : 'Sucursal'} (${branchRadius}m) | Pin: Coordenadas transmitidas por el móvil</p>
        </div>
        <span class="badge ${attendance.withinGeo ? 'badge-success' : 'badge-danger'}">Distancia calculada: ${attendance.distance}m</span>
      </div>
      <div id="forensicMap" style="height: 420px; width: 100%; background: #0a0b0f;"></div>
    </div>

    <!-- GESTIÓN DE JUSTIFICACIÓN -->
    <div class="card-panel" style="padding: 1.5rem;">
      <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Gestión de Justificaciones & Notas de RRHH</h3>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <div>
          <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.5rem;">Explicación remitida por el colaborador:</div>
          <div style="background: #1a1c26; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 1rem; color: #cbd5e1; font-size: 0.9rem; min-height: 80px;">
            ${attendance.notes || 'No se registraron observaciones por parte del colaborador.'}
          </div>
        </div>
        <div>
          <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.5rem;">Resolución de Supervisor / RRHH:</div>
          <form method="POST" action="/attendance/${attendance.id}/review">
            <textarea name="notes" rows="2" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #fff; padding: 0.6rem; font-size: 0.85rem; margin-bottom: 0.75rem;" placeholder="Comentarios del revisor...">${attendance.reviewerNotes || ''}</textarea>
            <div style="display: flex; gap: 10px;">
              <button type="submit" name="status" value="justified" style="flex: 1; padding: 0.65rem; background: #10b981; border: none; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer;">
                ✓ Justificar Tardanza
              </button>
              <button type="submit" name="status" value="late" style="flex: 1; padding: 0.65rem; background: #ef4444; border: none; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer;">
                ✕ Mantener como Tardanza
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const branchLat = ${branchLat};
        const branchLng = ${branchLng};
        const radius = ${branchRadius};
        const empLat = ${attendance.clockInLat};
        const empLng = ${attendance.clockInLng};
        const isWithin = ${attendance.withinGeo ? 'true' : 'false'};

        const map = L.map('forensicMap').setView([(branchLat + empLat) / 2, (branchLng + empLng) / 2], 17);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(map);

        // Geocerca de sucursal
        L.circle([branchLat, branchLng], {
          color: '#6366f1',
          fillColor: '#6366f1',
          fillOpacity: 0.22,
          weight: 2,
          radius: radius
        }).addTo(map).bindPopup('<b>📍 Sede Autorizada: ${branch ? branch.name : ''}</b><br>Radio: ' + radius + 'm');

        L.marker([branchLat, branchLng]).addTo(map).bindPopup('Centro de Sucursal');

        // Pin de colaborador
        const color = isWithin ? '#10b981' : '#ef4444';
        const empIcon = L.divIcon({
          className: 'emp-forensic-pin',
          html: '<div style="width: 24px; height: 24px; border-radius: 50%; background: ' + color + '; border: 3px solid #fff; box-shadow: 0 0 12px ' + color + ';"></div>',
          iconSize: [24, 24],
          iconAnchor: [12, 12]
        });

        L.marker([empLat, empLng], { icon: empIcon })
          .addTo(map)
          .bindPopup('<b>Punto de Marcación:</b><br>${attendance.distance}m de distancia.<br>' + (isWithin ? '✓ Dentro de geocerca' : '⚠ Fuera de geocerca'))
          .openPopup();

        // Línea conectora
        L.polyline([[branchLat, branchLng], [empLat, empLng]], {
          color: color,
          weight: 2,
          dashArray: '5, 8'
        }).addTo(map);
      });
    </script>
  `;
  return layout('Auditoría #' + attendance.id, user, 'attendance', content);
}

function renderBranchList(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Sucursales & Geocercas GPS</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Perímetros satelitales configurados para validación de presencia.</p>
      </div>
      <a href="/branches/create" class="btn-primary">
        + Nueva Sucursal / Obra
      </a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem;">
      ${db.branches.map(b => {
        const empCount = db.employees.filter(e => e.branchId === b.id).length;
        return `
          <div class="card-panel" style="padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
              <div>
                <h3 style="color: #fff; font-size: 1.15rem; font-weight: 700;">${b.name}</h3>
                <span style="font-size: 0.75rem; color: #818cf8; font-weight: 600;">${b.code}</span>
              </div>
              <span class="badge ${b.active ? 'badge-success' : 'badge-danger'}">${b.active ? 'Activa' : 'Inactiva'}</span>
            </div>
            <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 1rem;">📍 ${b.address}</div>
            <div style="background: #1a1c26; border-radius: 10px; padding: 0.85rem; font-size: 0.82rem; margin-bottom: 1rem;">
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #94a3b8;">Radio Permitido:</span>
                <strong style="color: #10b981; font-size: 0.9rem;">${b.radius} metros</strong>
              </div>
              <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                <span style="color: #94a3b8;">Supervisor:</span>
                <strong style="color: #cbd5e1;">${b.supervisor || 'No asignado'}</strong>
              </div>
              <div style="display: flex; justify-content: space-between;">
                <span style="color: #94a3b8;">Colaboradores:</span>
                <strong style="color: #fff;">${empCount} asignados</strong>
              </div>
            </div>
            <div style="font-size: 0.75rem; color: #64748b; font-family: monospace; margin-bottom: 1rem;">GPS: ${b.lat}, ${b.lng}</div>
            <div style="display: flex; gap: 8px;">
              <a href="/branches/${b.id}/edit" class="btn-secondary" style="flex: 1; text-align: center; font-size: 0.8rem; padding: 0.5rem;">Editar Geocerca</a>
              <form method="POST" action="/branches/${b.id}/delete" onsubmit="return confirm('¿Seguro de eliminar esta sucursal?')">
                <button type="submit" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); color: #f87171; border-radius: 8px; padding: 0.5rem 0.75rem; font-size: 0.8rem; cursor: pointer;">Eliminar</button>
              </form>
            </div>
          </div>
        `;
      }).join('')}
    </div>
  `;
  return layout('Sucursales & Geocercas', user, 'branches', content);
}

function renderBranchForm(user, branch = null) {
  const isEdit = !!branch;
  const lat = branch ? branch.lat : -12.09418;
  const lng = branch ? branch.lng : -77.03560;
  const radius = branch ? branch.radius : 60;

  const content = `
    <div style="margin-bottom: 1.5rem;">
      <a href="/branches" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver al listado de sucursales
      </a>
      <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">${isEdit ? 'Editar Geocerca: ' + branch.name : 'Registrar Nueva Sucursal & Geocerca'}</h1>
      <p style="color: #94a3b8; font-size: 0.9rem;">Haz clic sobre el mapa para fijar el punto GPS central y ajusta el radio perimetral permitido.</p>
    </div>

    <form method="POST" action="${isEdit ? '/branches/' + branch.id + '/edit' : '/branches'}">
      <div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 1.5rem;">
        <!-- FORM PANEL -->
        <div class="card-panel" style="padding: 1.5rem;">
          <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem;">
            Configuración de la Geocerca
          </h3>

          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nombre de la Sede / Obra *</label>
            <input type="text" name="name" value="${branch ? branch.name : ''}" required class="form-control" placeholder="Ej: Obra Panamericana Sur" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
            <div>
              <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Código Interno</label>
              <input type="text" name="code" value="${branch ? branch.code : 'SUC-' + Math.floor(Math.random()*900+100)}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
            </div>
            <div>
              <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Supervisor a Cargo</label>
              <input type="text" name="supervisor" value="${branch ? branch.supervisor : 'Carlos Ramírez'}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
            </div>
          </div>

          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Dirección Física *</label>
            <input type="text" name="address" value="${branch ? branch.address : ''}" required placeholder="Av. Principal 123" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>

          <!-- SLIDER DE RADIO -->
          <div style="background: #1a1c26; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(255,255,255,0.06);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
              <label style="font-weight: 700; color: #fff; font-size: 0.9rem;">Radio Permitido (Geocerca)</label>
              <span id="radiusBadge" style="font-size: 1rem; font-weight: 800; color: #10b981; background: rgba(16,185,129,0.15); padding: 3px 10px; border-radius: 6px;">
                ${radius} metros
              </span>
            </div>
            <input type="range" id="radiusSlider" name="radius" min="10" max="500" step="5" value="${radius}" style="width: 100%; accent-color: #6366f1; cursor: pointer;">
            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: #64748b; margin-top: 4px;">
              <span>10m (Estricto)</span>
              <span>100m</span>
              <span>250m</span>
              <span>500m (Obra amplia)</span>
            </div>
          </div>

          <!-- INPUTS GPS -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
            <div>
              <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Latitud GPS *</label>
              <input type="number" step="0.00000001" id="latInput" name="lat" value="${lat}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;">
            </div>
            <div>
              <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Longitud GPS *</label>
              <input type="number" step="0.00000001" id="lngInput" name="lng" value="${lng}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;">
            </div>
          </div>

          <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn-primary" style="flex: 1; justify-content: center;">
              ${isEdit ? 'Guardar Cambios' : 'Crear Sucursal'}
            </button>
            <a href="/branches" class="btn-secondary">Cancelar</a>
          </div>
        </div>

        <!-- LEAFLET PICKER MAP -->
        <div class="card-panel" style="display: flex; flex-direction: column;">
          <div class="card-panel-header" style="background: rgba(0,0,0,0.2);">
            <div>
              <h4 style="color: #fff; font-size: 0.95rem; font-weight: 700;">Selector Visual de Geocerca</h4>
              <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">Haz clic o arrastra el pin para reubicar</p>
            </div>
            <button type="button" onclick="getCurrentLocation()" class="badge badge-info" style="cursor: pointer;">
              📍 Mi Ubicación GPS
            </button>
          </div>
          <div id="pickerMap" style="flex: 1; min-height: 480px; background: #0a0b0f;"></div>
        </div>
      </div>
    </form>

    <script>
      let pickerMap, marker, circle;
      document.addEventListener('DOMContentLoaded', function() {
        const latInput = document.getElementById('latInput');
        const lngInput = document.getElementById('lngInput');
        const radiusSlider = document.getElementById('radiusSlider');
        const radiusBadge = document.getElementById('radiusBadge');

        let currentLat = parseFloat(latInput.value) || -12.09418;
        let currentLng = parseFloat(lngInput.value) || -77.03560;
        let currentRadius = parseInt(radiusSlider.value) || 60;

        pickerMap = L.map('pickerMap').setView([currentLat, currentLng], 16);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(pickerMap);

        marker = L.marker([currentLat, currentLng], { draggable: true }).addTo(pickerMap);
        circle = L.circle([currentLat, currentLng], {
          color: '#6366f1',
          fillColor: '#6366f1',
          fillOpacity: 0.22,
          weight: 2,
          radius: currentRadius
        }).addTo(pickerMap);

        marker.on('dragend', function(e) {
          const pos = marker.getLatLng();
          updateLocation(pos.lat, pos.lng);
        });

        pickerMap.on('click', function(e) {
          updateLocation(e.latlng.lat, e.latlng.lng);
        });

        radiusSlider.addEventListener('input', function() {
          const r = parseInt(this.value);
          radiusBadge.innerText = r + ' metros';
          circle.setRadius(r);
        });

        latInput.addEventListener('change', syncFromInputs);
        lngInput.addEventListener('change', syncFromInputs);

        function updateLocation(lat, lng) {
          latInput.value = lat.toFixed(7);
          lngInput.value = lng.toFixed(7);
          marker.setLatLng([lat, lng]);
          circle.setLatLng([lat, lng]);
        }

        function syncFromInputs() {
          const lat = parseFloat(latInput.value);
          const lng = parseFloat(lngInput.value);
          if (!isNaN(lat) && !isNaN(lng)) {
            marker.setLatLng([lat, lng]);
            circle.setLatLng([lat, lng]);
            pickerMap.panTo([lat, lng]);
          }
        }
      });

      function getCurrentLocation() {
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(function(pos) {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            document.getElementById('latInput').value = lat.toFixed(7);
            document.getElementById('lngInput').value = lng.toFixed(7);
            marker.setLatLng([lat, lng]);
            circle.setLatLng([lat, lng]);
            pickerMap.setView([lat, lng], 17);
          }, function() {
            alert('No se pudo obtener la geolocalización del navegador.');
          });
        }
      }
    </script>
  `;
  return layout(isEdit ? 'Editar Sucursal' : 'Nueva Sucursal', user, 'branches', content);
}

function renderEmployeeList(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Directorio de Colaboradores</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Gestión de personal, asignación de sedes y turnos de trabajo.</p>
      </div>
      <a href="/employees/create" class="btn-primary">
        + Nuevo Colaborador
      </a>
    </div>

    <div class="card-panel">
      <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
        <thead>
          <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); color: #94a3b8;">
            <th style="padding: 1rem 1.25rem;">Colaborador</th>
            <th style="padding: 1rem;">Código</th>
            <th style="padding: 1rem;">Cargo</th>
            <th style="padding: 1rem;">Sede Autorizada</th>
            <th style="padding: 1rem;">Turno</th>
            <th style="padding: 1rem;">Estado</th>
            <th style="padding: 1rem; text-align: right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          ${db.employees.map(e => `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
              <td style="padding: 1rem 1.25rem;">
                <div style="display: flex; align-items: center; gap: 10px;">
                  <div style="width: 36px; height: 36px; border-radius: 50%; background: #312e81; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #c7d2fe;">${e.avatar || 'EP'}</div>
                  <div>
                    <div style="font-weight: 600; color: #fff;">${e.name}</div>
                    <div style="font-size: 0.75rem; color: #94a3b8;">${e.email}</div>
                  </div>
                </div>
              </td>
              <td style="padding: 1rem; color: #818cf8; font-weight: 600;">${e.code}</td>
              <td style="padding: 1rem; color: #cbd5e1;">${e.role}</td>
              <td style="padding: 1rem; color: #94a3b8;">${e.branch}</td>
              <td style="padding: 1rem; color: #cbd5e1;">${e.shift}</td>
              <td style="padding: 1rem;">
                <span class="badge ${e.status === 'Activo' ? 'badge-success' : 'badge-danger'}">
                  ${e.status}
                </span>
              </td>
              <td style="padding: 1rem; text-align: right;">
                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                  <a href="/employees/${e.id}/edit" class="badge badge-info" style="text-decoration: none;">Editar</a>
                  <form method="POST" action="/employees/${e.id}/toggle" style="display: inline;">
                    <button type="submit" class="badge ${e.status === 'Activo' ? 'badge-warning' : 'badge-success'}" style="cursor: pointer; border: none;">
                      ${e.status === 'Activo' ? 'Desactivar' : 'Activar'}
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
  return layout('Colaboradores', user, 'employees', content);
}

function renderEmployeeForm(user, db, employee = null) {
  const isEdit = !!employee;
  const content = `
    <div style="margin-bottom: 1.5rem;">
      <a href="/employees" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver al listado de colaboradores
      </a>
      <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">${isEdit ? 'Editar Colaborador: ' + employee.name : 'Registrar Nuevo Colaborador'}</h1>
      <p style="color: #94a3b8; font-size: 0.9rem;">Asigna sus credenciales, sede autorizada y turno de trabajo.</p>
    </div>

    <form method="POST" action="${isEdit ? '/employees/' + employee.id + '/edit' : '/employees'}">
      <div class="card-panel" style="max-width: 680px; padding: 2rem;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nombres Completos *</label>
            <input type="text" name="name" value="${employee ? employee.name : ''}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Código de Empleado</label>
            <input type="text" name="code" value="${employee ? employee.code : 'EMP-' + Math.floor(Math.random()*9000+1000)}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Correo Electrónico *</label>
            <input type="email" name="email" value="${employee ? employee.email : ''}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Cargo / Puesto</label>
            <input type="text" name="role" value="${employee ? employee.role : 'Operador de Obra'}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Sede Autorizada (Geocerca) *</label>
            <select name="branchId" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
              ${db.branches.map(b => `
                <option value="${b.id}" ${employee && employee.branchId === b.id ? 'selected' : ''}>${b.name} (${b.radius}m)</option>
              `).join('')}
            </select>
          </div>
          <div>
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Turno Laboral</label>
            <select name="shift" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
              <option value="Mañana (07:00 - 15:30)">Mañana (07:00 - 15:30)</option>
              <option value="Oficina (08:30 - 17:30)">Oficina (08:30 - 17:30)</option>
              <option value="Tarde (14:00 - 22:00)">Tarde (14:00 - 22:00)</option>
              <option value="Flexible (09:00 - 18:00)">Flexible (09:00 - 18:00)</option>
            </select>
          </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
          <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Sueldo Base Mensual (S/ PEN)</label>
          <input type="number" step="0.01" name="salary" value="${employee ? employee.salary : '2400.00'}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;">
        </div>

        <div style="display: flex; gap: 10px;">
          <button type="submit" class="btn-primary" style="flex: 1; justify-content: center;">
            ${isEdit ? 'Guardar Cambios' : 'Registrar Colaborador'}
          </button>
          <a href="/employees" class="btn-secondary">Cancelar</a>
        </div>
      </div>
    </form>
  `;
  return layout(isEdit ? 'Editar Empleado' : 'Nuevo Empleado', user, 'employees', content);
}

function renderSimulator(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem;">
      <h1 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Simulador Móvil de Marcación GPS & Selfie</h1>
      <p style="color: #94a3b8; font-size: 0.92rem;">Emula el comportamiento de la app móvil Flutter: calcula la fórmula Haversine en vivo contra las geocercas activas.</p>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 1.5rem;">
      <!-- PANEL DE CONTROL DEL SIMULADOR -->
      <div class="card-panel" style="padding: 1.5rem;">
        <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px;">
          <span>📱 Interfaz del Dispositivo Móvil</span>
        </h3>

        <form method="POST" action="/simulator/punch">
          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">1. Seleccionar Colaborador</label>
            <select name="employeeId" id="simEmployee" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;" onchange="updateSimTarget()">
              ${db.employees.map(e => `
                <option value="${e.id}" data-branch="${e.branchId}">${e.name} (${e.code}) — ${e.branch}</option>
              `).join('')}
            </select>
          </div>

          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">2. Sucursal Destino (Geocerca a Validar)</label>
            <select name="branchId" id="simBranch" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.65rem; border-radius: 8px;" onchange="recalculateSim()">
              ${db.branches.map(b => `
                <option value="${b.id}" data-lat="${b.lat}" data-lng="${b.lng}" data-radius="${b.radius}">${b.name} (Radio: ${b.radius}m)</option>
              `).join('')}
            </select>
          </div>

          <!-- BOTONES DE PRESET DE COORDENADAS -->
          <div style="margin-bottom: 1rem;">
            <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">3. Presets de Prueba de Ubicación</label>
            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
              <button type="button" onclick="setPreset('inside')" class="badge badge-success" style="cursor: pointer; padding: 6px 12px;">📍 Dentro de Geocerca (15m)</button>
              <button type="button" onclick="setPreset('outside')" class="badge badge-danger" style="cursor: pointer; padding: 6px 12px;">🚗 Fuera de Rango (180m)</button>
              <button type="button" onclick="setPreset('device')" class="badge badge-info" style="cursor: pointer; padding: 6px 12px;">🌐 Mi GPS Real</button>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
              <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Latitud Emulada</label>
              <input type="number" step="0.00000001" id="simLat" name="lat" value="-12.0561" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;" oninput="recalculateSim()">
            </div>
            <div>
              <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Longitud Emulada</label>
              <input type="number" step="0.00000001" id="simLng" name="lng" value="-77.1179" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;" oninput="recalculateSim()">
            </div>
          </div>

          <!-- COMPUTACIÓN HAVERSINE EN VIVO -->
          <div style="background: #1a1c26; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(255,255,255,0.06);">
            <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px;">CÁLCULO MATEMÁTICO HAVERSINE:</div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <div>
                <div style="font-size: 1.5rem; font-weight: 800; color: #fff;" id="simDistText">14.2 m</div>
                <div style="font-size: 0.75rem; color: #94a3b8;" id="simStatusSubtext">Distancia al centro de la geocerca</div>
              </div>
              <div id="simBadge">
                <span class="badge badge-success" style="font-size: 0.85rem;">✓ DENTRO DE GEOCERCA</span>
              </div>
            </div>
          </div>

          <div style="display: flex; gap: 10px;">
            <button type="submit" name="action" value="clock_in" class="btn-primary" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #10b981, #059669);">
              ✓ Fichar Entrada (Clock-In)
            </button>
            <button type="submit" name="action" value="clock_out" class="btn-primary" style="flex: 1; justify-content: center; background: linear-gradient(135deg, #ef4444, #dc2626);">
              ✕ Fichar Salida (Clock-Out)
            </button>
          </div>
        </form>
      </div>

      <!-- MAPA INTERACTIVO DEL SIMULADOR -->
      <div class="card-panel" style="display: flex; flex-direction: column;">
        <div class="card-panel-header">
          <div>
            <h4 style="color: #fff; font-size: 0.95rem; font-weight: 700;">Radar GPS en Tiempo Real</h4>
            <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">Arrastra el pin móvil para ver cómo reacciona la fórmula Haversine</p>
          </div>
        </div>
        <div id="simMap" style="flex: 1; min-height: 480px; background: #0a0b0f;"></div>
      </div>
    </div>

    <script>
      let simMap, simMarker, branchCircle, branchCenterMarker;

      function getSelectedBranchData() {
        const select = document.getElementById('simBranch');
        const opt = select.options[select.selectedIndex];
        return {
          lat: parseFloat(opt.getAttribute('data-lat')),
          lng: parseFloat(opt.getAttribute('data-lng')),
          radius: parseInt(opt.getAttribute('data-radius'))
        };
      }

      function haversine(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
      }

      function recalculateSim() {
        const b = getSelectedBranchData();
        const empLat = parseFloat(document.getElementById('simLat').value) || b.lat;
        const empLng = parseFloat(document.getElementById('simLng').value) || b.lng;

        const dist = haversine(empLat, empLng, b.lat, b.lng);
        const distRounded = Math.round(dist * 10) / 10;
        const isWithin = dist <= b.radius;

        document.getElementById('simDistText').innerText = distRounded + ' m';
        document.getElementById('simDistText').style.color = isWithin ? '#10b981' : '#ef4444';

        const badgeEl = document.getElementById('simBadge');
        if (isWithin) {
          badgeEl.innerHTML = '<span class="badge badge-success" style="font-size: 0.85rem;">✓ DENTRO DE GEOCERCA</span>';
        } else {
          badgeEl.innerHTML = '<span class="badge badge-danger" style="font-size: 0.85rem;">⚠ FUERA (+ ' + Math.round(dist - b.radius) + 'm)</span>';
        }

        if (branchCircle) {
          branchCircle.setLatLng([b.lat, b.lng]);
          branchCircle.setRadius(b.radius);
          branchCenterMarker.setLatLng([b.lat, b.lng]);
        }
        if (simMarker) {
          simMarker.setLatLng([empLat, empLng]);
        }
      }

      function setPreset(type) {
        const b = getSelectedBranchData();
        if (type === 'inside') {
          // A unos 15 metros al norte
          document.getElementById('simLat').value = (b.lat + 0.00012).toFixed(7);
          document.getElementById('simLng').value = b.lng.toFixed(7);
          recalculateSim();
          simMap.setView([b.lat, b.lng], 17);
        } else if (type === 'outside') {
          // A unos 200 metros
          document.getElementById('simLat').value = (b.lat + 0.0018).toFixed(7);
          document.getElementById('simLng').value = b.lng.toFixed(7);
          recalculateSim();
          simMap.setView([b.lat, b.lng], 16);
        } else if (type === 'device') {
          if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
              document.getElementById('simLat').value = pos.coords.latitude.toFixed(7);
              document.getElementById('simLng').value = pos.coords.longitude.toFixed(7);
              recalculateSim();
              simMap.setView([pos.coords.latitude, pos.coords.longitude], 16);
            });
          }
        }
      }

      function updateSimTarget() {
        const empSelect = document.getElementById('simEmployee');
        const branchId = empSelect.options[empSelect.selectedIndex].getAttribute('data-branch');
        const branchSelect = document.getElementById('simBranch');
        branchSelect.value = branchId;
        setPreset('inside');
      }

      document.addEventListener('DOMContentLoaded', function() {
        const b = getSelectedBranchData();
        const startLat = parseFloat(document.getElementById('simLat').value);
        const startLng = parseFloat(document.getElementById('simLng').value);

        simMap = L.map('simMap').setView([b.lat, b.lng], 16);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(simMap);

        branchCircle = L.circle([b.lat, b.lng], {
          color: '#6366f1',
          fillColor: '#6366f1',
          fillOpacity: 0.22,
          weight: 2,
          radius: b.radius
        }).addTo(simMap);

        branchCenterMarker = L.marker([b.lat, b.lng]).addTo(simMap).bindPopup('Centro de Geocerca');

        simMarker = L.marker([startLat, startLng], { draggable: true }).addTo(simMap);
        simMarker.on('dragend', function() {
          const pos = simMarker.getLatLng();
          document.getElementById('simLat').value = pos.lat.toFixed(7);
          document.getElementById('simLng').value = pos.lng.toFixed(7);
          recalculateSim();
        });

        simMap.on('click', function(e) {
          document.getElementById('simLat').value = e.latlng.lat.toFixed(7);
          document.getElementById('simLng').value = e.latlng.lng.toFixed(7);
          recalculateSim();
        });

        recalculateSim();
      });
    </script>
  `;
  return layout('Simulador GPS', user, 'simulator', content);
}

function renderPayslips(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Nómina & Boletas de Pago</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Emisión y distribución de boletas de pago conforme a la legislación laboral del Perú.</p>
      </div>
      <button onclick="alert('Generación masiva ejecutada para el periodo Octubre 2026.')" class="btn-primary">
        + Emitir Boletas del Periodo
      </button>
    </div>

    <div class="card-panel">
      <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
        <thead>
          <tr style="border-bottom: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.2); color: #94a3b8;">
            <th style="padding: 1rem 1.25rem;">Colaborador</th>
            <th style="padding: 1rem;">Periodo</th>
            <th style="padding: 1rem;">Sueldo Bruto</th>
            <th style="padding: 1rem;">Deducciones (AFP/ONP)</th>
            <th style="padding: 1rem;">Neto a Pagar</th>
            <th style="padding: 1rem;">Aporte ESSALUD (9%)</th>
            <th style="padding: 1rem;">Estado</th>
            <th style="padding: 1rem; text-align: right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          ${db.payslips.map(p => `
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
              <td style="padding: 1rem 1.25rem; font-weight: 600; color: #fff;">${p.employee} (${p.code})</td>
              <td style="padding: 1rem; color: #818cf8;">${p.period}</td>
              <td style="padding: 1rem; color: #cbd5e1;">S/ ${p.grossTotal.toFixed(2)}</td>
              <td style="padding: 1rem; color: #f87171;">- S/ ${p.pensionDeduction.toFixed(2)} (${p.afpName})</td>
              <td style="padding: 1rem; color: #10b981; font-weight: 700;">S/ ${p.netPay.toFixed(2)}</td>
              <td style="padding: 1rem; color: #60a5fa;">S/ ${p.essaludCompany.toFixed(2)}</td>
              <td style="padding: 1rem;"><span class="badge badge-success">Publicada</span></td>
              <td style="padding: 1rem; text-align: right;">
                <button onclick="viewBoleta(${p.id})" class="badge badge-info" style="cursor: pointer; border: none;">Ver Boleta PDF</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>

    <!-- MODAL DE BOLETA DE PAGO -->
    <div id="boletaModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 999; align-items: center; justify-content: center; padding: 2rem;">
      <div style="background: #fff; color: #0f172a; border-radius: 16px; max-width: 650px; width: 100%; padding: 2.5rem; box-shadow: 0 25px 60px rgba(0,0,0,0.5); font-family: sans-serif; position: relative;">
        <button onclick="document.getElementById('boletaModal').style.display='none'" style="position: absolute; top: 1rem; right: 1.5rem; background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">✕</button>
        <div style="border-bottom: 2px solid #0f172a; padding-bottom: 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between;">
          <div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a;">BOLETA DE PAGO ELECTRÓNICA</h2>
            <div style="font-size: 0.8rem; color: #64748b;">Constructora Alfa S.A. • RUC: 20123456789</div>
          </div>
          <div style="text-align: right; font-size: 0.8rem; font-weight: 600;">
            <div>Periodo: Octubre 2026</div>
            <div style="color: #10b981;">✓ Firmada Digitalmente</div>
          </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.85rem; margin-bottom: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 8px;">
          <div><strong>Colaborador:</strong> Juan Pérez Silva</div>
          <div><strong>Documento:</strong> DNI 45892147</div>
          <div><strong>Cargo:</strong> Operador de Obra</div>
          <div><strong>Régimen:</strong> D.L. 728 / AFP Integra</div>
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 1.5rem;">
          <tr style="background: #f1f5f9; font-weight: 700;">
            <th style="padding: 8px; text-align: left;">Conceptos Ingresos</th>
            <th style="padding: 8px; text-align: right;">Monto</th>
            <th style="padding: 8px; text-align: left;">Descuentos al Trabajador</th>
            <th style="padding: 8px; text-align: right;">Monto</th>
          </tr>
          <tr>
            <td style="padding: 8px;">Básico</td>
            <td style="padding: 8px; text-align: right;">S/ 2,400.00</td>
            <td style="padding: 8px;">Aporte Obligatorio AFP (10%)</td>
            <td style="padding: 8px; text-align: right;">S/ 240.00</td>
          </tr>
          <tr>
            <td style="padding: 8px;">Asignación Familiar</td>
            <td style="padding: 8px; text-align: right;">S/ 102.50</td>
            <td style="padding: 8px;">Comisión y Seguro AFP</td>
            <td style="padding: 8px; text-align: right;">S/ 95.68</td>
          </tr>
          <tr style="font-weight: 800; border-top: 2px solid #e2e8f0;">
            <td style="padding: 8px;">Total Ingresos</td>
            <td style="padding: 8px; text-align: right;">S/ 2,502.50</td>
            <td style="padding: 8px;">Total Descuentos</td>
            <td style="padding: 8px; text-align: right; color: #dc2626;">S/ 335.68</td>
          </tr>
        </table>
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 1rem; display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
          <span style="font-weight: 700; color: #065f46;">NETO A PAGAR:</span>
          <span style="font-size: 1.4rem; font-weight: 800; color: #047857;">S/ 2,166.82</span>
        </div>
        <button onclick="window.print()" style="width: 100%; padding: 0.75rem; background: #0f172a; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer;">
          🖨️ Imprimir / Guardar en PDF
        </button>
      </div>
    </div>

    <script>
      function viewBoleta(id) {
        document.getElementById('boletaModal').style.display = 'flex';
      }
    </script>
  `;
  return layout('Boletas de Pago', user, 'payslips', content);
}

function renderReports(user, db) {
  const content = `
    <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end;">
      <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Reportes Ejecutivos & Cumplimiento</h1>
        <p style="color: #94a3b8; font-size: 0.9rem;">Consolidado analítico de horas computadas y puntualidad laboral por sede.</p>
      </div>
      <div style="display: flex; gap: 10px;">
        <a href="/reports/export/csv" class="btn-primary">
          📥 Descargar Planilla CSV
        </a>
      </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; margin-bottom: 1.5rem;">
      <div class="card-panel" style="padding: 1.5rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px;">PUNTUALIDAD GLOBAL</div>
        <div style="font-size: 2rem; font-weight: 800; color: #10b981;">92.5%</div>
        <div style="font-size: 0.75rem; color: #64748b;">Mes en curso</div>
      </div>
      <div class="card-panel" style="padding: 1.5rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px;">HORAS REGULARES</div>
        <div style="font-size: 2rem; font-weight: 800; color: #60a5fa;">2,840 hrs</div>
        <div style="font-size: 0.75rem; color: #64748b;">Efectivas computadas</div>
      </div>
      <div class="card-panel" style="padding: 1.5rem;">
        <div style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 4px;">HORAS EXTRAS</div>
        <div style="font-size: 2rem; font-weight: 800; color: #c084fc;">142.5 hrs</div>
        <div style="font-size: 0.75rem; color: #64748b;">Para pago de planilla</div>
      </div>
    </div>
  `;
  return layout('Reportes Ejecutivos', user, 'reports', content);
}

module.exports = {
  renderLogin,
  renderDashboard,
  renderAttendanceList,
  renderAttendanceShow,
  renderBranchList,
  renderBranchForm,
  renderEmployeeList,
  renderEmployeeForm,
  renderSimulator,
  renderPayslips,
  renderReports
};
