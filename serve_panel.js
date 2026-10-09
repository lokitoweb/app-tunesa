const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Load environment from .env
function loadEnv() {
  const envPath = path.join(__dirname, '.env');
  const env = {};
  if (fs.existsSync(envPath)) {
    const lines = fs.readFileSync(envPath, 'utf8').split('\n');
    for (const line of lines) {
      const trimmed = line.trim();
      if (trimmed && !trimmed.startsWith('#')) {
        const idx = trimmed.indexOf('=');
        if (idx > 0) {
          env[trimmed.substring(0, idx).trim()] = trimmed.substring(idx + 1).trim();
        }
      }
    }
  }
  return env;
}

const env = loadEnv();
const PORT = parseInt(env.PORT || '8000', 10);

const { loadData, saveData, verifyPassword } = require('./server/db');
const { calculateDistance, checkGeofence } = require('./server/geofence');
const views = require('./server/views');

const db = loadData();
const sessions = new Map(); // token -> user

function getSessionUser(req) {
  const cookieHeader = req.headers.cookie || '';
  const match = cookieHeader.match(/tunesa_session=([^;]+)/);
  if (match) {
    const token = match[1];
    return sessions.get(token) || null;
  }
  return null;
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      const contentType = req.headers['content-type'] || '';
      if (contentType.includes('application/json')) {
        try {
          resolve(JSON.parse(body || '{}'));
        } catch (e) {
          resolve({});
        }
      } else {
        const params = new URLSearchParams(body);
        const obj = {};
        for (const [key, val] of params.entries()) {
          obj[key] = val;
        }
        resolve(obj);
      }
    });
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://' + req.headers.host);
  const pathname = url.pathname;
  const user = getSessionUser(req);

  // 1. Static Assets
  if (pathname.startsWith('/css/') || pathname.startsWith('/js/')) {
    const filePath = path.join(__dirname, 'public', pathname);
    if (fs.existsSync(filePath)) {
      const ext = path.extname(filePath);
      const contentType = ext === '.css' ? 'text/css' : 'application/javascript';
      res.writeHead(200, { 'Content-Type': contentType });
      return fs.createReadStream(filePath).pipe(res);
    }
  }

  // 2. REST API v1 (Mobile & Integrations)
  if (pathname.startsWith('/api/v1/')) {
    res.setHeader('Content-Type', 'application/json');

    if (pathname === '/api/v1/auth/login' && req.method === 'POST') {
      const body = await parseBody(req);
      const found = db.users.find(u => u.email.toLowerCase() === (body.email || '').trim().toLowerCase());
      if (found && verifyPassword(body.password || '', found.passwordHash)) {
        const token = crypto.randomBytes(32).toString('hex');
        sessions.set(token, found);
        return res.end(JSON.stringify({
          success: true,
          token,
          user: { id: found.id, name: found.name, email: found.email, role: found.role, tenant: found.tenant }
        }));
      }
      res.writeHead(401);
      return res.end(JSON.stringify({ success: false, message: 'Credenciales inválidas' }));
    }

    if (pathname === '/api/v1/branches' && req.method === 'GET') {
      return res.end(JSON.stringify({ success: true, branches: db.branches }));
    }

    if (pathname === '/api/v1/attendance/today' && req.method === 'GET') {
      return res.end(JSON.stringify({ success: true, attendances: db.attendances }));
    }

    if (pathname === '/api/v1/attendance/clock-in' && req.method === 'POST') {
      const body = await parseBody(req);
      const empId = parseInt(body.employeeId || '101', 10);
      const emp = db.employees.find(e => e.id === empId) || db.employees[0];
      const branchId = parseInt(body.branchId || emp.branchId || '1', 10);
      const branch = db.branches.find(b => b.id === branchId) || db.branches[0];

      const lat = parseFloat(body.lat || branch.lat);
      const lng = parseFloat(body.lng || branch.lng);
      const geoCheck = checkGeofence(lat, lng, branch);

      const now = new Date();
      const timeStr = now.toTimeString().split(' ')[0];
      const isLate = now.getHours() > 8 || (now.getHours() === 8 && now.getMinutes() > 15);

      const newAtt = {
        id: db.attendances.length > 0 ? Math.max(...db.attendances.map(a => a.id)) + 1 : 501,
        employeeId: emp.id,
        employee: emp.name,
        branchId: branch.id,
        branch: branch.name,
        workDate: now.toISOString().split('T')[0],
        clockIn: timeStr,
        clockOut: null,
        clockInLat: lat,
        clockInLng: lng,
        clockOutLat: null,
        clockOutLng: null,
        clockInPhoto: body.photo || 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&h=300&fit=crop',
        clockOutPhoto: null,
        distance: geoCheck.distance_meters,
        withinGeo: geoCheck.within_geofence,
        status: isLate ? 'late' : 'on_time',
        lateMinutes: isLate ? Math.max(5, (now.getHours() - 8) * 60 + now.getMinutes() - 15) : 0,
        workedHours: 0,
        overtimeHours: 0,
        notes: body.notes || null,
        justificationStatus: null
      };

      db.attendances.unshift(newAtt);
      saveData();
      return res.end(JSON.stringify({ success: true, attendance: newAtt, geofence: geoCheck }));
    }

    if (pathname === '/api/v1/payslips' && req.method === 'GET') {
      return res.end(JSON.stringify({ success: true, payslips: db.payslips }));
    }

    res.writeHead(404);
    return res.end(JSON.stringify({ success: false, message: 'Endpoint no encontrado' }));
  }

  // 3. Web Auth Routes
  if (pathname === '/login') {
    if (req.method === 'GET') {
      if (user) {
        res.writeHead(302, { Location: '/dashboard' });
        return res.end();
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderLogin('', env.SUPERADMIN_EMAIL, env.SUPERADMIN_PASSWORD));
    }

    if (req.method === 'POST') {
      const body = await parseBody(req);
      const email = (body.email || '').trim().toLowerCase();
      const password = body.password || '';

      const foundUser = db.users.find(u => u.email.toLowerCase() === email);
      if (foundUser && verifyPassword(password, foundUser.passwordHash)) {
        const sessionToken = crypto.randomBytes(32).toString('hex');
        sessions.set(sessionToken, foundUser);
        res.writeHead(302, {
          'Set-Cookie': 'tunesa_session=' + sessionToken + '; Path=/; HttpOnly; SameSite=Lax',
          'Location': '/dashboard'
        });
        return res.end();
      }
      res.writeHead(401, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderLogin('Credenciales inválidas. Verifica tu correo y contraseña.', env.SUPERADMIN_EMAIL, env.SUPERADMIN_PASSWORD));
    }
  }

  if (pathname === '/logout' && req.method === 'POST') {
    res.writeHead(302, {
      'Set-Cookie': 'tunesa_session=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
      'Location': '/login'
    });
    return res.end();
  }

  // Session guard
  if (!user) {
    res.writeHead(302, { Location: '/login' });
    return res.end();
  }

  // 4. Web Views & Actions
  if (pathname === '/' || pathname === '/dashboard') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderDashboard(user, db));
  }

  if (pathname === '/dashboard/live-stream') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    const present = db.attendances.length;
    const late = db.attendances.filter(a => a.status === 'late').length;
    return res.end(JSON.stringify({
      success: true,
      timestamp: new Date().toISOString(),
      kpis: { present_count: present, late_count: late, on_break_count: 2, absent_count: Math.max(0, db.employees.length - present) },
      branches: db.branches,
      attendances: db.attendances
    }));
  }

  // --- Attendance ---
  if (pathname === '/attendance') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderAttendanceList(user, db));
  }

  // Forensic Audit Show View
  const attMatch = pathname.match(/^\/attendance\/(\d+)$/);
  if (attMatch) {
    const attId = parseInt(attMatch[1], 10);
    const attendance = db.attendances.find(a => a.id === attId);
    if (!attendance) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      return res.end('Marcación no encontrada');
    }
    const branch = db.branches.find(b => b.id === attendance.branchId);
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderAttendanceShow(user, attendance, branch));
  }

  const reviewMatch = pathname.match(/^\/attendance\/(\d+)\/review$/);
  if (reviewMatch && req.method === 'POST') {
    const attId = parseInt(reviewMatch[1], 10);
    const attendance = db.attendances.find(a => a.id === attId);
    if (attendance) {
      const body = await parseBody(req);
      attendance.status = body.status === 'justified' ? 'justified' : 'late';
      attendance.reviewerNotes = body.notes || 'Revisado por RRHH';
      saveData();
    }
    res.writeHead(302, { Location: `/attendance/${attId}` });
    return res.end();
  }

  // --- Branches ---
  if (pathname === '/branches') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderBranchList(user, db));
  }

  if (pathname === '/branches/create') {
    if (req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderBranchForm(user, null));
    }
  }

  if (pathname === '/branches' && req.method === 'POST') {
    const body = await parseBody(req);
    const newId = db.branches.length > 0 ? Math.max(...db.branches.map(b => b.id)) + 1 : 1;
    db.branches.push({
      id: newId,
      name: body.name || 'Nueva Sede',
      code: body.code || 'SUC-' + newId,
      address: body.address || 'Av. Principal 100',
      city: 'Lima',
      country: 'Perú',
      lat: parseFloat(body.lat || '-12.09418'),
      lng: parseFloat(body.lng || '-77.03560'),
      radius: parseInt(body.radius || '60', 10),
      supervisor: body.supervisor || 'Carlos Ramírez',
      active: true
    });
    saveData();
    res.writeHead(302, { Location: '/branches' });
    return res.end();
  }

  const branchEditMatch = pathname.match(/^\/branches\/(\d+)\/edit$/);
  if (branchEditMatch) {
    const branchId = parseInt(branchEditMatch[1], 10);
    const branch = db.branches.find(b => b.id === branchId);
    if (!branch) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      return res.end('Sucursal no encontrada');
    }
    if (req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderBranchForm(user, branch));
    }
    if (req.method === 'POST') {
      const body = await parseBody(req);
      branch.name = body.name || branch.name;
      branch.code = body.code || branch.code;
      branch.address = body.address || branch.address;
      branch.supervisor = body.supervisor || branch.supervisor;
      branch.lat = parseFloat(body.lat || branch.lat);
      branch.lng = parseFloat(body.lng || branch.lng);
      branch.radius = parseInt(body.radius || branch.radius, 10);
      saveData();
      res.writeHead(302, { Location: '/branches' });
      return res.end();
    }
  }

  const branchDeleteMatch = pathname.match(/^\/branches\/(\d+)\/delete$/);
  if (branchDeleteMatch && req.method === 'POST') {
    const branchId = parseInt(branchDeleteMatch[1], 10);
    db.branches = db.branches.filter(b => b.id !== branchId);
    saveData();
    res.writeHead(302, { Location: '/branches' });
    return res.end();
  }

  // --- Employees ---
  if (pathname === '/employees') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderEmployeeList(user, db));
  }

  if (pathname === '/employees/create') {
    if (req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderEmployeeForm(user, db, null));
    }
  }

  if (pathname === '/employees' && req.method === 'POST') {
    const body = await parseBody(req);
    const newId = db.employees.length > 0 ? Math.max(...db.employees.map(e => e.id)) + 1 : 101;
    const branchId = parseInt(body.branchId || '1', 10);
    const branch = db.branches.find(b => b.id === branchId);

    db.employees.push({
      id: newId,
      name: body.name || 'Nuevo Colaborador',
      code: body.code || 'EMP-' + newId,
      role: body.role || 'Operador',
      branchId: branchId,
      branch: branch ? branch.name : 'Sede Principal',
      shift: body.shift || 'Mañana (07:00 - 15:30)',
      salary: parseFloat(body.salary || '2000.00'),
      status: 'Activo',
      email: body.email || `emp${newId}@empresa-demo.com`,
      avatar: (body.name || 'EP').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
    });
    saveData();
    res.writeHead(302, { Location: '/employees' });
    return res.end();
  }

  const empEditMatch = pathname.match(/^\/employees\/(\d+)\/edit$/);
  if (empEditMatch) {
    const empId = parseInt(empEditMatch[1], 10);
    const employee = db.employees.find(e => e.id === empId);
    if (!employee) {
      res.writeHead(404, { 'Content-Type': 'text/plain' });
      return res.end('Empleado no encontrado');
    }
    if (req.method === 'GET') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(views.renderEmployeeForm(user, db, employee));
    }
    if (req.method === 'POST') {
      const body = await parseBody(req);
      employee.name = body.name || employee.name;
      employee.code = body.code || employee.code;
      employee.email = body.email || employee.email;
      employee.role = body.role || employee.role;
      employee.branchId = parseInt(body.branchId || employee.branchId, 10);
      const branch = db.branches.find(b => b.id === employee.branchId);
      if (branch) employee.branch = branch.name;
      employee.shift = body.shift || employee.shift;
      employee.salary = parseFloat(body.salary || employee.salary);
      saveData();
      res.writeHead(302, { Location: '/employees' });
      return res.end();
    }
  }

  const empToggleMatch = pathname.match(/^\/employees\/(\d+)\/toggle$/);
  if (empToggleMatch && req.method === 'POST') {
    const empId = parseInt(empToggleMatch[1], 10);
    const employee = db.employees.find(e => e.id === empId);
    if (employee) {
      employee.status = employee.status === 'Activo' ? 'Inactivo' : 'Activo';
      saveData();
    }
    res.writeHead(302, { Location: '/employees' });
    return res.end();
  }

  // --- Mobile Clock-in Simulator ---
  if (pathname === '/simulator') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderSimulator(user, db));
  }

  if (pathname === '/simulator/punch' && req.method === 'POST') {
    const body = await parseBody(req);
    const empId = parseInt(body.employeeId || '101', 10);
    const emp = db.employees.find(e => e.id === empId) || db.employees[0];
    const branchId = parseInt(body.branchId || '1', 10);
    const branch = db.branches.find(b => b.id === branchId) || db.branches[0];

    const lat = parseFloat(body.lat || branch.lat);
    const lng = parseFloat(body.lng || branch.lng);
    const geoCheck = checkGeofence(lat, lng, branch);

    const now = new Date();
    const timeStr = now.toTimeString().split(' ')[0];

    if (body.action === 'clock_out') {
      // Clock Out
      const existing = db.attendances.find(a => a.employeeId === emp.id && !a.clockOut);
      if (existing) {
        existing.clockOut = timeStr;
        existing.clockOutLat = lat;
        existing.clockOutLng = lng;
        existing.clockOutPhoto = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&h=300&fit=crop';
        existing.workedHours = 8.0;
        saveData();
        res.writeHead(302, { Location: `/attendance/${existing.id}` });
        return res.end();
      }
    }

    // Clock In
    const newAtt = {
      id: db.attendances.length > 0 ? Math.max(...db.attendances.map(a => a.id)) + 1 : 501,
      employeeId: emp.id,
      employee: emp.name,
      branchId: branch.id,
      branch: branch.name,
      workDate: now.toISOString().split('T')[0],
      clockIn: timeStr,
      clockOut: null,
      clockInLat: lat,
      clockInLng: lng,
      clockOutLat: null,
      clockOutLng: null,
      clockInPhoto: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&h=300&fit=crop',
      clockOutPhoto: null,
      distance: geoCheck.distance_meters,
      withinGeo: geoCheck.within_geofence,
      status: geoCheck.within_geofence ? 'on_time' : 'late',
      lateMinutes: geoCheck.within_geofence ? 0 : 15,
      workedHours: 0,
      overtimeHours: 0,
      notes: geoCheck.within_geofence ? null : 'Marcación fuera de geocerca emulada desde el simulador GPS.',
      justificationStatus: geoCheck.within_geofence ? null : 'pending'
    };

    db.attendances.unshift(newAtt);
    saveData();
    res.writeHead(302, { Location: `/attendance/${newAtt.id}` });
    return res.end();
  }

  // --- Payslips ---
  if (pathname === '/payslips') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderPayslips(user, db));
  }

  // --- Reports ---
  if (pathname === '/reports') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(views.renderReports(user, db));
  }

  if (pathname === '/reports/export/csv') {
    res.writeHead(200, {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': 'attachment; filename="tunesa_asistencias_' + new Date().toISOString().split('T')[0] + '.csv"'
    });
    let csv = 'ID,Colaborador,Sede,Fecha,HoraEntrada,HoraSalida,DistanciaMetros,DentroGeocerca,Estado\n';
    for (const a of db.attendances) {
      csv += `${a.id},"${a.employee}","${a.branch}",${a.workDate},${a.clockIn},${a.clockOut || ''},${a.distance},${a.withinGeo ? 'SI' : 'NO'},${a.status}\n`;
    }
    return res.end(csv);
  }

  res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('404 Not Found');
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`🚀 TuneSA SaaS Server activo en http://localhost:${PORT}`);
  console.log(`👤 Super Admin configurado: ${env.SUPERADMIN_EMAIL || 'superadmin@tunesa.app'}`);
});
