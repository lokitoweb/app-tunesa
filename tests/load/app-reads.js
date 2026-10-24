// Lecturas de la app móvil (k6). Solo contra servidores locales con una COPIA de la base
// (tests/load/seed-load.php). Cada VU es un colaborador con su token que abre la app y
// recorre Inicio, Asistencia, Solicitudes, Boletas, Avisos y Comunicados, como en el teléfono.
//   k6 run -e USERS=load-users.json -e BASES=http://127.0.0.1:8101,http://127.0.0.1:8102 tests/load/app-reads.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const data = JSON.parse(open(__ENV.USERS));
const BASES = (__ENV.BASES || 'http://127.0.0.1:8101').split(',');
const serverErrors = new Counter('app_5xx');
const notOk = new Counter('app_not_200'); // por status y ruta (--summary-mode=full lo desglosa)

// Lo que pide la app al abrir cada pantalla (mobile/lib/core/network/api_endpoints.dart)
const SCREENS = [
    '/api/auth/me', '/api/attendance/today', '/api/my-shifts', '/api/my-branches', '/api/birthdays/upcoming',
    '/api/leave-requests', '/api/payslips', '/api/notifications', '/api/trainings', '/api/announcements',
];

export const options = {
    scenarios: {
        ramp: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '20s', target: 20 },
                { duration: '40s', target: 40 },
                { duration: '20s', target: 40 },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '5s',
        },
    },
    thresholds: {
        app_5xx: ['count==0'],
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1500'],
        checks: ['rate>0.99'],
    },
};

export default function () {
    const emp = data.employees[(__VU - 1) % data.employees.length];
    const base = BASES[(__VU - 1) % BASES.length];
    const headers = { Authorization: `Bearer ${emp.token}`, 'X-Tenant-Slug': data.tenant, Accept: 'application/json', 'X-App-Version': '9.9.9' };

    for (const path of SCREENS) {
        const res = http.get(`${base}${path}`, { headers, tags: { name: path }, timeout: '30s' });
        if (res.status >= 500) serverErrors.add(1);
        if (res.status !== 200) notOk.add(1, { status: String(res.status), path });
        check(res, {
            '200 con JSON': (r) => r.status === 200 && (() => { try { return r.json('success') !== false; } catch (e) { return false; } })(),
        });
        sleep(0.2); // el usuario toca la siguiente pestaña
    }
}
