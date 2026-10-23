// Concurrencia de marcaciones (k6). Solo contra servidores locales con una COPIA de la base.
// 2 VUs por colaborador disparan la entrada a la vez (selfie + GPS dentro de la geocerca):
// debe quedar exactamente una marcación por persona (la otra: 409 ALREADY_CLOCKED_IN), sin 5xx.
//   k6 run -e USERS=load-users.json -e SELFIE=selfie.jpg -e BASES=http://127.0.0.1:8101,... tests/load/clock-in-race.js
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const data = JSON.parse(open(__ENV.USERS));
const selfie = open(__ENV.SELFIE, 'b');
const BASES = (__ENV.BASES || 'http://127.0.0.1:8101').split(',');
const PER_EMPLOYEE = Number(__ENV.PER_EMPLOYEE || 2);

const accepted = new Counter('punch_accepted');
const duplicates = new Counter('punch_duplicate_409');
const serverErrors = new Counter('punch_5xx');

export const options = {
    scenarios: {
        race: { executor: 'per-vu-iterations', vus: data.employees.length * PER_EMPLOYEE, iterations: 1, maxDuration: '2m' },
    },
    thresholds: {
        punch_5xx: ['count==0'],
        checks: ['rate==1'],
    },
};

export default function () {
    const emp = data.employees[(__VU - 1) % data.employees.length];
    const base = BASES[(__VU - 1) % BASES.length];
    const jitter = () => (Math.random() - 0.5) * 0.0002; // ±10 m alrededor del centro de la sede

    const res = http.post(`${base}/api/attendance/clock-in`, {
        latitude: String(data.branch.lat + jitter()),
        longitude: String(data.branch.lng + jitter()),
        accuracy_meters: '10',
        device_id: `k6-device-${emp.id}`,
        device_os: 'android',
        is_mocked: '0',
        photo: http.file(selfie, 'selfie.jpg', 'image/jpeg'),
    }, {
        headers: { Authorization: `Bearer ${emp.token}`, 'X-Tenant-Slug': data.tenant, Accept: 'application/json' },
        tags: { name: 'clock-in' },
        timeout: '60s',
    });

    if (res.status === 200 || res.status === 201) accepted.add(1);
    if (res.status === 409) duplicates.add(1);
    if (res.status >= 500) serverErrors.add(1);

    check(res, {
        'aceptada o duplicada (nunca 5xx ni otro error)': (r) => [200, 201, 409].includes(r.status),
        'respuesta JSON válida': (r) => { try { return typeof r.json('success') === 'boolean'; } catch (e) { return false; } },
    });
}
