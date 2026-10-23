// Carga del Flujo de caja (k6). Solo contra servidores locales con una COPIA de la base
// (tests/load/seed-load.php). Cada VU inicia sesión una vez con su propio usuario de
// Contabilidad y luego navega el flujo cambiando periodo y moneda.
//   k6 run -e USERS=load-users.json -e BASES=http://127.0.0.1:8101,http://127.0.0.1:8102 tests/load/cash-flow.js
import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const data = JSON.parse(open(__ENV.USERS));
const BASES = (__ENV.BASES || 'http://127.0.0.1:8101').split(',');
const cashFlow = new Trend('cash_flow_ms', true);

export const options = {
    noCookiesReset: true, // la sesión del login se conserva entre iteraciones del mismo VU
    scenarios: {
        ramp: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '20s', target: 10 },
                { duration: '40s', target: 20 },
                { duration: '20s', target: 20 },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '5s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        cash_flow_ms: ['p(95)<2000'],
        checks: ['rate>0.99'],
    },
};

const months = ['2026-06', '2026-08', '2026-10', '2026-12'];
const currencies = ['PEN', 'PEN', 'PEN', 'USD'];
let base = null;

// El login limita intentos por IP: cada VU simula su propia IP (el servidor local
// confía en el proxy 127.0.0.1, como Cloudflare en producción)
const ip = () => `10.20.${Math.floor(__VU / 250)}.${__VU % 250 + 1}`;

function login() {
    const target = BASES[(__VU - 1) % BASES.length];
    const email = data.accountants[(__VU - 1) % data.accountants.length];
    const headers = { 'X-Forwarded-For': ip() };
    const page = http.get(`${target}/login`, { headers, tags: { name: 'login' } });
    const token = page.html().find('input[name=_token]').attr('value');
    const res = http.post(`${target}/login`, { _token: token, email, password: data.password, _auth_token_verify: '' }, { headers, redirects: 5, tags: { name: 'login' } });
    if (!res.url.includes('/dashboard')) fail(`login falló para ${email}: ${res.status} ${res.url}`);
    base = target; // solo con sesión válida: si falla, la próxima iteración reintenta
}

export default function () {
    if (base === null) login();

    const from = months[Math.floor(Math.random() * months.length)];
    const currency = currencies[Math.floor(Math.random() * currencies.length)];
    const res = http.get(`${base}/cash-flow?from=${from}&currency=${currency}`, { headers: { 'X-Forwarded-For': ip() }, redirects: 0, tags: { name: 'cash-flow' } });
    cashFlow.add(res.timings.duration);

    // Inertia escapa "/" en el JSON de la página: se busca sin barras
    check(res, {
        '200 con sesión (sin redirigir al login)': (r) => r.status === 200,
        'es la pantalla del flujo': (r) => r.body.includes('CashFlow'),
        'trae conceptos con montos': (r) => currency === 'USD' || r.body.includes('Proveedores'),
    });
    sleep(Math.random() * 1.5 + 0.5);
}
