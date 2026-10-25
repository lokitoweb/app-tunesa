// Cierre de QA (k6). Solo contra servidores locales con una COPIA de la base
// (tests/load/seed-load.php + tests/load/seed-qa-closure.php).
//
// - leave_race: 2 VUs por colaborador piden las MISMAS vacaciones a la vez. Debe quedar
//   una sola solicitud por persona (la otra: 409 por fechas cruzadas), sin 5xx.
// - payslips:   lista y descarga de boletas concurrente (40 VUs, uno por colaborador, para no
//               chocar con el límite de 60 peticiones/min por usuario). Todas 200 y PDF.
//
//   k6 run -e USERS=load-users.json -e BASES=http://127.0.0.1:8101,http://127.0.0.1:8102 tests/load/qa-closure.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const data = JSON.parse(open(__ENV.USERS));
const BASES = (__ENV.BASES || 'http://127.0.0.1:8101').split(',');
const PER_EMPLOYEE = Number(__ENV.PER_EMPLOYEE || 2);

const leaveCreated = new Counter('leave_created_201');
const leaveRejected = new Counter('leave_overlap_409');
const serverErrors = new Counter('qa_5xx');
const pdfs = new Counter('payslip_pdf_ok');
const throttled = new Counter('qa_429');

export const options = {
    scenarios: {
        leave_race: {
            executor: 'per-vu-iterations', exec: 'leaveRace',
            vus: data.employees.length * PER_EMPLOYEE, iterations: 1, maxDuration: '2m',
        },
        payslips: {
            executor: 'ramping-vus', exec: 'payslips', startTime: '5s',
            stages: [{ duration: '10s', target: 40 }, { duration: '50s', target: 40 }, { duration: '5s', target: 0 }],
            gracefulRampDown: '5s',
        },
    },
    thresholds: {
        qa_5xx: ['count==0'],
        checks: ['rate==1'],
        'http_req_duration{scenario:payslips}': ['p(95)<1500'],
    },
};

const headers = (emp) => ({ Authorization: `Bearer ${emp.token}`, 'X-Tenant-Slug': data.tenant, Accept: 'application/json', 'X-App-Version': '9.9.9' });

export function leaveRace() {
    const emp = data.employees[(__VU - 1) % data.employees.length];
    // El 2.º pedido de la misma persona va a OTRO servidor (carrera real, no en fila)
    const base = BASES[(__VU - 1 + Math.floor((__VU - 1) / data.employees.length)) % BASES.length];
    const res = http.post(`${base}/api/leave-requests`, JSON.stringify({
        type: 'vacation', start_date: '2027-02-01', end_date: '2027-02-05', reason: 'Carga: vacaciones',
    }), { headers: { ...headers(emp), 'Content-Type': 'application/json' }, tags: { name: 'leave-requests' }, timeout: '60s' });

    if (res.status >= 500) serverErrors.add(1);
    if (res.status === 201) leaveCreated.add(1);
    if (res.status === 409) leaveRejected.add(1);
    check(res, { 'vacaciones: 201 o 409 por fechas cruzadas': (r) => r.status === 201 || r.status === 409 });
}

export function payslips() {
    const emp = data.employees[(__VU - 1) % data.employees.length];
    const base = BASES[(__VU - 1) % BASES.length];
    const list = http.get(`${base}/api/payslips`, { headers: headers(emp), tags: { name: 'payslips' }, timeout: '30s' });
    if (list.status >= 500) serverErrors.add(1);
    if (list.status === 429) throttled.add(1);
    const id = list.status === 200 ? list.json('data.0.id') : null;
    check(list, { 'boletas: lista 200 con su boleta': () => list.status === 200 && id !== null && id !== undefined });
    if (!id) return;

    const pdf = http.get(`${base}/api/payslips/${id}/download`, {
        headers: { ...headers(emp), Accept: 'application/pdf' }, responseType: 'binary', tags: { name: 'payslip-download' }, timeout: '30s',
    });
    if (pdf.status >= 500) serverErrors.add(1);
    const ok = pdf.status === 200 && String.fromCharCode(...new Uint8Array(pdf.body).slice(0, 4)) === '%PDF';
    if (ok) pdfs.add(1);
    check(pdf, { 'boletas: descarga 200 y es PDF': () => ok });
    sleep(3.5); // 2 peticiones cada ~3.7 s: bajo 60/min por usuario (AppServiceProvider), si no responde 429
}
