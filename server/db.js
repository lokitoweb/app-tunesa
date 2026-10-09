const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const DB_FILE = path.join(__dirname, '..', 'database', 'data.json');
const SALT = process.env.HASH_SALT || 'tunesa_secure_salt_2026';

function hashPassword(password) {
  return crypto.pbkdf2Sync(password, SALT, 10000, 64, 'sha512').toString('hex');
}

function verifyPassword(password, storedHash) {
  const calculated = hashPassword(password);
  return crypto.timingSafeEqual(Buffer.from(calculated), Buffer.from(storedHash));
}

const defaultData = {
  users: [
    {
      id: 1,
      name: process.env.SUPERADMIN_NAME || 'Super Administrador General',
      email: (process.env.SUPERADMIN_EMAIL || 'superadmin@tunesa.app').toLowerCase(),
      passwordHash: hashPassword(process.env.SUPERADMIN_PASSWORD || 'SuperAdmin2024!'),
      role: 'super_admin',
      roleLabel: 'Super Admin Global',
      tenant: 'TuneSA Global SaaS (Matriz)',
      avatarInitials: 'SA'
    },
    {
      id: 2,
      name: 'Carlos Ramírez',
      email: 'admin@empresa-demo.com',
      passwordHash: hashPassword('Admin2024!'),
      role: 'company_admin',
      roleLabel: 'Company Admin',
      tenant: 'Constructora Alfa S.A.',
      avatarInitials: 'CR'
    },
    {
      id: 3,
      name: 'María González',
      email: 'rrhh@empresa-demo.com',
      passwordHash: hashPassword('RRHH2024!'),
      role: 'hr_manager',
      roleLabel: 'RRHH Manager',
      tenant: 'Constructora Alfa S.A.',
      avatarInitials: 'MG'
    }
  ],
  branches: [
    {
      id: 1,
      name: 'Sede Central Miraflores',
      code: 'MIR-01',
      address: 'Av. Larco 850, Miraflores',
      city: 'Lima',
      country: 'Perú',
      lat: -12.1219,
      lng: -77.0298,
      radius: 60,
      supervisor: 'Carlos Ramírez',
      active: true
    },
    {
      id: 2,
      name: 'Obra Almacén Callao',
      code: 'CAL-02',
      address: 'Av. Elmer Faucett 2100, Callao',
      city: 'Callao',
      country: 'Perú',
      lat: -12.0560,
      lng: -77.1180,
      radius: 100,
      supervisor: 'Jorge Prado',
      active: true
    },
    {
      id: 3,
      name: 'Sede San Isidro Financiero',
      code: 'SI-03',
      address: 'Av. Rivera Navarrete 525, San Isidro',
      city: 'Lima',
      country: 'Perú',
      lat: -12.0969,
      lng: -77.0255,
      radius: 50,
      supervisor: 'María González',
      active: true
    }
  ],
  employees: [
    {
      id: 101,
      name: 'Juan Pérez Silva',
      code: 'EMP-1001',
      role: 'Operador de Obra',
      branchId: 2,
      branch: 'Obra Almacén Callao',
      shift: 'Mañana (07:00 - 15:30)',
      salary: 2400.0,
      status: 'Activo',
      email: 'juan.perez@empresa-demo.com',
      avatar: 'JP'
    },
    {
      id: 102,
      name: 'Ana Torres Vega',
      code: 'EMP-1002',
      role: 'Supervisora de Calidad',
      branchId: 1,
      branch: 'Sede Central Miraflores',
      shift: 'Oficina (08:30 - 17:30)',
      salary: 3200.0,
      status: 'Activo',
      email: 'ana.torres@empresa-demo.com',
      avatar: 'AT'
    },
    {
      id: 103,
      name: 'Luis Mendoza Castro',
      code: 'EMP-1003',
      role: 'Técnico Instalador',
      branchId: 3,
      branch: 'Sede San Isidro Financiero',
      shift: 'Flexible (09:00 - 18:00)',
      salary: 2600.0,
      status: 'Activo',
      email: 'luis.mendoza@empresa-demo.com',
      avatar: 'LM'
    },
    {
      id: 104,
      name: 'Sofía Vargas Ramos',
      code: 'EMP-1004',
      role: 'Asistente de Logística',
      branchId: 2,
      branch: 'Obra Almacén Callao',
      shift: 'Mañana (07:00 - 15:30)',
      salary: 2100.0,
      status: 'Activo',
      email: 'sofia.vargas@empresa-demo.com',
      avatar: 'SV'
    },
    {
      id: 105,
      name: 'Roberto Quispe Huamán',
      code: 'EMP-1005',
      role: 'Chofer de Carga Pesada',
      branchId: 2,
      branch: 'Obra Almacén Callao',
      shift: 'Ruta (06:00 - 14:00)',
      salary: 2500.0,
      status: 'Activo',
      email: 'roberto.quispe@empresa-demo.com',
      avatar: 'RQ'
    }
  ],
  attendances: [
    {
      id: 501,
      employeeId: 101,
      employee: 'Juan Pérez Silva',
      branchId: 2,
      branch: 'Obra Almacén Callao',
      workDate: '2026-10-08',
      clockIn: '06:58:12',
      clockOut: '15:35:00',
      clockInLat: -12.0561,
      clockInLng: -77.1179,
      clockOutLat: -12.0560,
      clockOutLng: -77.1180,
      clockInPhoto: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&h=300&fit=crop',
      clockOutPhoto: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=300&h=300&fit=crop',
      distance: 14.2,
      withinGeo: true,
      status: 'on_time',
      lateMinutes: 0,
      workedHours: 8.5,
      overtimeHours: 0.5,
      notes: null,
      justificationStatus: null
    },
    {
      id: 502,
      employeeId: 102,
      employee: 'Ana Torres Vega',
      branchId: 1,
      branch: 'Sede Central Miraflores',
      workDate: '2026-10-08',
      clockIn: '08:26:45',
      clockOut: null,
      clockInLat: -12.1220,
      clockInLng: -77.0296,
      clockOutLat: null,
      clockOutLng: null,
      clockInPhoto: 'https://images.unsplash.com/photo-1580489944761-15a19d654956?w=300&h=300&fit=crop',
      clockOutPhoto: null,
      distance: 22.0,
      withinGeo: true,
      status: 'on_time',
      lateMinutes: 0,
      workedHours: 0.0,
      overtimeHours: 0.0,
      notes: null,
      justificationStatus: null
    },
    {
      id: 503,
      employeeId: 103,
      employee: 'Luis Mendoza Castro',
      branchId: 3,
      branch: 'Sede San Isidro Financiero',
      workDate: '2026-10-08',
      clockIn: '09:14:30',
      clockOut: null,
      clockInLat: -12.0970,
      clockInLng: -77.0253,
      clockOutLat: null,
      clockOutLng: null,
      clockInPhoto: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=300&h=300&fit=crop',
      clockOutPhoto: null,
      distance: 35.8,
      withinGeo: true,
      status: 'late',
      lateMinutes: 14,
      workedHours: 0.0,
      overtimeHours: 0.0,
      notes: 'Demora imprevista en el Metropolitano por congestión vehicular.',
      justificationStatus: 'pending'
    },
    {
      id: 504,
      employeeId: 104,
      employee: 'Sofía Vargas Ramos',
      branchId: 2,
      branch: 'Obra Almacén Callao',
      workDate: '2026-10-08',
      clockIn: '07:02:10',
      clockOut: null,
      clockInLat: -12.0559,
      clockInLng: -77.1181,
      clockOutLat: null,
      clockOutLng: null,
      clockInPhoto: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=300&h=300&fit=crop',
      clockOutPhoto: null,
      distance: 18.5,
      withinGeo: true,
      status: 'on_time',
      lateMinutes: 2,
      workedHours: 0.0,
      overtimeHours: 0.0,
      notes: null,
      justificationStatus: null
    }
  ],
  payslips: [
    {
      id: 1,
      employeeId: 101,
      employee: 'Juan Pérez Silva',
      code: 'EMP-1001',
      period: 'Octubre 2026',
      basicSalary: 2400.0,
      familyAllowance: 102.5,
      overtimePay: 120.0,
      grossTotal: 2622.5,
      afpName: 'AFP Integra (12.8%)',
      pensionDeduction: 335.68,
      taxDeduction: 0.0,
      netPay: 2286.82,
      essaludCompany: 236.03,
      status: 'published'
    },
    {
      id: 2,
      employeeId: 102,
      employee: 'Ana Torres Vega',
      code: 'EMP-1002',
      period: 'Octubre 2026',
      basicSalary: 3200.0,
      familyAllowance: 0.0,
      overtimePay: 0.0,
      grossTotal: 3200.0,
      afpName: 'AFP Prima (12.9%)',
      pensionDeduction: 412.8,
      taxDeduction: 35.0,
      netPay: 2752.2,
      essaludCompany: 288.0,
      status: 'published'
    }
  ]
};

let data = null;

function loadData() {
  if (data) return data;
  if (fs.existsSync(DB_FILE)) {
    try {
      data = JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
      return data;
    } catch (e) {
      console.error('Error cargando data.json, usando defaults:', e);
    }
  }
  data = JSON.parse(JSON.stringify(defaultData));
  saveData();
  return data;
}

function saveData() {
  try {
    const dir = path.dirname(DB_FILE);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(DB_FILE, JSON.stringify(data, null, 2), 'utf8');
  } catch (err) {
    console.error('Error guardando data.json:', err);
  }
}

module.exports = {
  loadData,
  saveData,
  hashPassword,
  verifyPassword
};
