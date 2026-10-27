<script setup>
import { computed, reactive, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import DeliveriesTable from '../../Components/DeliveriesTable.vue';
import TestResult from '../../Components/TestResult.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    isGlobal: Boolean,
    ownerName: String,
    values: Object,
    receivers: Object,
    inherited: Object,
    has: Object,
    maps: Object,
    fcm: Object,
    geoProbe: Object,
    userEmail: String,
    updatedAt: String,
    // Solo llega para el SuperAdmin: credenciales de Migo, Nubefact y SMTP
    services: { type: Object, default: null },
});

const SERVICE_SECRETS = ['migo_api_token', 'nubefact_token', 'smtp_password'];
const serviceFields = () => (props.services
    ? { ...props.services.values, ...Object.fromEntries(SERVICE_SECRETS.flatMap((s) => [[s, ''], [`clear_${s}`, false]])) }
    : {});

const page = usePage();
const { ask } = useConfirm();
const PROVIDERS = { nominatim: 'OpenStreetMap / Nominatim — gratis', google: 'Google Maps Geocoding' };

const form = useForm({
    ...props.values,
    maps_api_key: '',
    clear_maps_api_key: false,
    fcm_service_account_json: '',
    fcm_service_account_file: null,
    clear_fcm_service_account_json: false,
    ...serviceFields(),
});

function save() {
    form
        .transform((data) => {
            const out = { ...data, _method: 'put' };
            if (!props.has.geocoding_provider) delete out.geocoding_provider;
            if (!props.has.fcm_enabled) delete out.fcm_enabled;
            else if (props.isGlobal) out.fcm_enabled = data.fcm_enabled ? '1' : '0';
            if (!props.isGlobal || !props.has.fcm_account) {
                delete out.fcm_service_account_json;
                delete out.fcm_service_account_file;
                delete out.clear_fcm_service_account_json;
            }
            for (const flag of ['clear_maps_api_key', 'clear_fcm_service_account_json', ...SERVICE_SECRETS.map((s) => `clear_${s}`)]) {
                if (flag in out) out[flag] = out[flag] ? '1' : '0';
            }
            // Secreto vacío = no cambiar: no se envía
            for (const s of SERVICE_SECRETS) {
                if (s in out && !out[s]) delete out[s];
            }
            return out;
        })
        .post('/settings/integrations', {
            forceFormData: true,
            preserveScroll: true,
            // Sin form.reset(): Inertia toma como defaults lo último enviado; se vacían a mano los secretos
            onSuccess: () => Object.assign(form, {
                maps_api_key: '', clear_maps_api_key: false, fcm_service_account_json: '', fcm_service_account_file: null, clear_fcm_service_account_json: false,
                ...Object.fromEntries(SERVICE_SECRETS.flatMap((s) => [[s, ''], [`clear_${s}`, false]])),
            }),
        });
}

// Peticiones JSON a los endpoints de prueba
async function requestJson(url, options) {
    const res = await fetch(url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': page.props.csrf, 'Content-Type': 'application/json' }, ...options });
    let data = null;
    try { data = await res.json(); } catch (e) { /* respuesta no JSON */ }
    if (res.status === 429) throw new Error('Demasiadas pruebas seguidas. Espera un minuto.');
    if (!data) throw new Error(`Respuesta inesperada del servidor (HTTP ${res.status}).`);
    if (res.status === 422 && data.errors) throw new Error(Object.values(data.errors).flat().join(' '));
    return data;
}

const deliveries = ref(null);
const busy = reactive({});
const results = reactive({});

async function test(key, url, payload) {
    busy[key] = true;
    results[key] = null;
    try {
        results[key] = await requestJson(url, { method: 'POST', body: JSON.stringify(Object.fromEntries(Object.entries(payload).filter(([, v]) => v))) });
    } catch (e) {
        results[key] = { ok: false, status: null, ms: 0, error: e.message };
    } finally {
        busy[key] = false;
        deliveries.value?.load();
    }
}

// Secreto del webhook: mostrar/ocultar, copiar y regenerar
const showSecret = ref(false);
const secretNote = ref(null);

async function copySecret() {
    try {
        await navigator.clipboard.writeText(form.webhook_secret);
        secretNote.value = { ok: true, text: 'Secreto copiado al portapapeles.' };
    } catch (e) {
        showSecret.value = true;
        secretNote.value = { ok: false, text: 'No se pudo copiar automáticamente: selecciónalo y cópialo a mano.' };
    }
}

async function regenerateSecret() {
    const ok = await ask({
        title: '¿Regenerar secreto del webhook?',
        message: 'Las firmas de webhook calculadas con el secreto actual dejarán de ser válidas de inmediato en tu sistema.',
        confirmText: 'Sí, regenerar',
        variant: 'danger',
        icon: 'bi bi-arrow-repeat',
    });
    if (!ok) return;

    busy.secret = true;
    try {
        const data = await requestJson('/settings/branding/regenerate-webhook-secret', { method: 'POST', body: '{}' });
        form.webhook_secret = data.secret;
        form.defaults('webhook_secret', data.secret);
        secretNote.value = { ok: true, text: data.message };
    } catch (e) {
        secretNote.value = { ok: false, text: e.message };
    } finally {
        busy.secret = false;
    }
}

const googleWithoutKey = computed(() => {
    const chosen = form.geocoding_provider || props.inherited.geocoding_provider;
    const hasKey = form.maps_api_key.trim() !== '' || (props.maps.any && !form.clear_maps_api_key);
    return chosen === 'google' && !hasKey;
});

const geo = ref(null);
async function testGeocode() {
    busy.geo = true;
    const t0 = performance.now();
    try {
        const data = await requestJson(`/geocode/reverse?lat=${props.geoProbe.lat}&lng=${props.geoProbe.lng}`, { method: 'GET' });
        geo.value = data.success && data.data?.address
            ? { ok: true, provider: data.data.provider === 'google' ? 'Google Maps' : 'OpenStreetMap', ms: Math.round(performance.now() - t0), address: data.data.address }
            : { ok: false, error: data.message || 'No se encontró una dirección para esas coordenadas.' };
    } catch (e) {
        geo.value = { ok: false, error: e.message };
    } finally {
        busy.geo = false;
    }
}

const pushEmail = ref('');
</script>

<template>
    <AppLayout title="Integraciones y Webhooks">
        <!-- Header Ejecutivo Moderno con Hub de Navegación -->
        <div class="settings-header-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="settings-header-icon" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                        <i class="bi bi-plug-fill"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h1 class="settings-header-title mb-0">Integraciones y Webhooks</h1>
                            <span v-if="isGlobal" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 rounded-pill font-monospace fw-semibold" style="font-size: 0.7rem;">GLOBAL</span>
                            <span v-else class="badge bg-info-subtle text-info border border-info-subtle px-2 py-0.5 rounded-pill fw-semibold" style="font-size: 0.7rem;">{{ ownerName }}</span>
                        </div>
                        <p class="settings-header-subtitle mb-0 mt-0.5">Endpoints de webhook, geocodificación inversa y notificaciones push de {{ isGlobal ? 'la plataforma' : ownerName }}.</p>
                    </div>
                </div>
                <div class="settings-hub-nav">
                    <a href="/settings/branding" class="settings-hub-btn" title="Configurar identidad y colores">
                        <i class="bi bi-palette"></i>
                        <span>Marca y apariencia</span>
                    </a>
                    <a href="/settings/mobile" class="settings-hub-btn" title="Configurar app móvil">
                        <i class="bi bi-phone"></i>
                        <span>App móvil</span>
                    </a>
                    <span class="settings-hub-btn is-active" aria-current="page">
                        <i class="bi bi-plug-fill text-primary"></i>
                        <span>Integraciones</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Banner Ejecutivo de Modo Global -->
        <div v-if="isGlobal" class="settings-global-notice mb-4" role="status">
            <div class="d-flex align-items-start gap-3">
                <div class="settings-notice-icon">
                    <i class="bi bi-globe-americas"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <strong class="settings-notice-title">Valores por defecto de la plataforma</strong>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 font-monospace fw-bold" style="font-size: 0.68rem;">HERENCIA ACTIVA</span>
                    </div>
                    <div class="settings-notice-text">
                        El proveedor de geocodificación, la clave de mapas y las notificaciones push los heredan automáticamente las empresas que no hayan configurado los suyos propios.
                    </div>
                </div>
            </div>
        </div>

        <!-- KPIs Resumen Rápido -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm p-3 integrations-card">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(2, 132, 199, 0.1); color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-send-check-fill"></i>
                        </div>
                        <div>
                            <div class="fs-6 fw-bold text-primary-emphasis text-truncate" style="max-width: 140px;">
                                {{ form.webhook_url ? 'Configurado' : 'Sin URL' }}
                            </div>
                            <div class="small settings-helper-text">Webhook ERP</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm p-3 integrations-card">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div>
                            <div class="fs-6 fw-bold text-primary-emphasis">
                                {{ (form.geocoding_provider || inherited.geocoding_provider) === 'google' ? 'Google Maps' : 'OpenStreetMap' }}
                            </div>
                            <div class="small settings-helper-text">Geocodificación</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm p-3 integrations-card">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(139, 92, 246, 0.1); color: #8b5cf6; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-bell-fill"></i>
                        </div>
                        <div>
                            <div class="fs-6 fw-bold text-primary-emphasis">
                                {{ fcm?.configured ? 'Configurado' : 'Pendiente' }}
                            </div>
                            <div class="small settings-helper-text">Push Firebase</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm p-3 integrations-card">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 42px; height: 42px; border-radius: 10px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-shield-lock-fill"></i>
                        </div>
                        <div>
                            <div class="fs-6 fw-bold text-primary-emphasis">HMAC-SHA256</div>
                            <div class="small settings-helper-text">Firma de seguridad</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form novalidate @submit.prevent="save">
            <div class="row g-4">
                <!-- Webhook y endpoint de notificaciones -->
                <div class="col-12">
                    <div class="card border-0 shadow-sm integrations-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Webhooks y endpoints de eventos</h2>
                            <small class="settings-helper-text">Se almacenan cifrados. El secreto solo es visible para administradores y nunca se expone a la app móvil.</small>
                            <small v-if="isGlobal" class="d-block settings-helper-text mt-1">El webhook y el endpoint son específicos de cada empresa: en la vista global solo sirven para validar conectividad.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-4">
                                <div class="col-12 col-md-6">
                                    <label for="webhook_url" class="form-label small fw-semibold text-body mb-1">URL del webhook de eventos</label>
                                    <div class="modern-input-wrapper mb-1">
                                        <span class="modern-input-icon"><i class="bi bi-link-45deg"></i></span>
                                        <input id="webhook_url" v-model="form.webhook_url" type="url" maxlength="255" placeholder="https://erp.empresa.com/webhooks/asistencia" class="modern-input-control" :class="{ 'is-invalid': form.errors.webhook_url }">
                                    </div>
                                    <div v-if="form.errors.webhook_url" class="text-danger small mt-1">{{ form.errors.webhook_url }}</div>
                                    <small class="settings-helper-text d-block">Recibe un POST JSON en tiempo real por cada entrada, descanso, salida y cambio en solicitudes.</small>
                                    <div v-if="form.webhook_url === receivers.webhook_url" class="small text-info-emphasis mt-1">
                                        <i class="bi bi-info-circle me-1"></i> Precargada con el receptor de la plataforma. Reemplázala por la de tu sistema cuando la tengas.
                                    </div>
                                    <button type="button" class="btn-integration-action mt-3" :disabled="busy.webhook" @click="test('webhook', '/settings/branding/test-webhook', { url: form.webhook_url?.trim(), secret: form.webhook_secret })">
                                        <span v-if="busy.webhook" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                        <i v-else class="bi bi-send-fill me-2"></i>
                                        Probar webhook ahora
                                    </button>
                                    <TestResult :result="results.webhook" />
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="webhook_secret" class="form-label small fw-semibold text-body mb-1">Secreto compartido de firma (Secret Key)</label>
                                    <div class="modern-input-wrapper mb-1">
                                        <span class="modern-input-icon"><i class="bi bi-key-fill"></i></span>
                                        <input id="webhook_secret" v-model="form.webhook_secret" :type="showSecret ? 'text' : 'password'" maxlength="255" minlength="16" autocomplete="off" spellcheck="false" required
                                               class="modern-input-control font-monospace pe-5" :class="{ 'is-invalid': form.errors.webhook_secret }">
                                        <div class="d-flex align-items-center gap-1 pe-2">
                                            <button type="button" class="btn-input-action" :aria-pressed="showSecret" :title="showSecret ? 'Ocultar el secreto' : 'Mostrar el secreto'" @click="showSecret = !showSecret">
                                                <i class="bi" :class="showSecret ? 'bi-eye-slash-fill' : 'bi-eye-fill'"></i>
                                            </button>
                                            <button type="button" class="btn-input-action" title="Copiar el secreto" @click="copySecret">
                                                <i class="bi bi-clipboard-fill"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="form.errors.webhook_secret" class="text-danger small mt-1">{{ form.errors.webhook_secret }}</div>
                                    <small class="settings-helper-text d-block">Firma cada envío en la cabecera <code>X-Tunesa-Signature: sha256=…</code> para que tu servidor verifique la autenticidad.</small>
                                    <button type="button" class="btn-integration-warning mt-3" :disabled="busy.secret" @click="regenerateSecret">
                                        <i class="bi bi-arrow-repeat me-2"></i> Regenerar secreto
                                    </button>
                                    <div v-if="secretNote" class="mt-2 small d-flex align-items-center gap-1" :class="secretNote.ok ? 'text-success' : 'text-danger'" aria-live="polite">
                                        <i class="bi" :class="secretNote.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>
                                        <span>{{ secretNote.text }}</span>
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="notification_endpoint" class="form-label small fw-semibold text-body mb-1">Endpoint de notificaciones in-app</label>
                                    <div class="modern-input-wrapper mb-1">
                                        <span class="modern-input-icon"><i class="bi bi-bell-fill"></i></span>
                                        <input id="notification_endpoint" v-model="form.notification_endpoint" type="url" maxlength="255" placeholder="https://notificaciones.empresa.com/push" class="modern-input-control" :class="{ 'is-invalid': form.errors.notification_endpoint }">
                                    </div>
                                    <div v-if="form.errors.notification_endpoint" class="text-danger small mt-1">{{ form.errors.notification_endpoint }}</div>
                                    <small class="settings-helper-text d-block">Recibe avisos creados para usuarios de la empresa, firmados con el mismo secreto.</small>
                                    <div v-if="form.notification_endpoint === receivers.notification_endpoint" class="small text-info-emphasis mt-1">
                                        <i class="bi bi-info-circle me-1"></i> Precargada con el receptor de la plataforma.
                                    </div>
                                    <button type="button" class="btn-integration-action mt-3" :disabled="busy.notification" @click="test('notification', '/settings/branding/test-notification', { url: form.notification_endpoint?.trim(), secret: form.webhook_secret })">
                                        <span v-if="busy.notification" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                        <i v-else class="bi bi-bell-fill me-2"></i>
                                        Enviar notificación de prueba
                                    </button>
                                    <TestResult :result="results.notification" />
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="p-3 rounded-3 bg-body-tertiary border h-100 d-flex flex-column justify-content-center">
                                        <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-semibold small">
                                            <i class="bi bi-info-circle-fill fs-5"></i>
                                            <span>Mecanismo de reintentos automáticos</span>
                                        </div>
                                        <p class="small text-secondary mb-0">
                                            Las pruebas se transmiten sincrónicamente con el evento <code>ping</code> o <code>notification.test</code>.
                                            En producción, los eventos reales se procesan asíncronamente en cola y se reintentan hasta <strong>5 veces</strong> con espera exponencial ante caídas del servidor receptor.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Geocodificación -->
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm integrations-card h-100">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Geocodificación inversa</h2>
                            <small class="text-secondary">Convierte automáticamente coordenadas GPS en direcciones de texto en sucursales.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div v-if="has.geocoding_provider" class="mb-3">
                                <label for="geocoding_provider" class="form-label small fw-semibold text-body mb-1">Proveedor de mapas</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon"><i class="bi bi-compass"></i></span>
                                    <select id="geocoding_provider" v-model="form.geocoding_provider" class="modern-input-control" :class="{ 'is-invalid': form.errors.geocoding_provider }">
                                        <option v-if="!isGlobal" value="">Heredar de la plataforma (actual: {{ PROVIDERS[inherited.geocoding_provider] ?? inherited.geocoding_provider }})</option>
                                        <option v-for="(label, key) in PROVIDERS" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                </div>
                                <div v-if="form.errors.geocoding_provider" class="text-danger small mt-1">{{ form.errors.geocoding_provider }}</div>
                            </div>

                            <div class="mb-3">
                                <label for="maps_api_key" class="form-label small fw-semibold text-body mb-1">API Key de Google Maps (Geocoding API)</label>
                                <div class="modern-input-wrapper">
                                    <span class="modern-input-icon"><i class="bi bi-google"></i></span>
                                    <input id="maps_api_key" v-model="form.maps_api_key" type="password" maxlength="255" autocomplete="new-password"
                                           :placeholder="maps.mask ?? (!isGlobal && maps.any ? 'Usa la clave de la plataforma' : 'Sin clave')"
                                           class="modern-input-control font-monospace" :class="{ 'is-invalid': form.errors.maps_api_key }">
                                </div>
                                <small class="settings-helper-text d-block mt-1">Requerida solo si seleccionas Google Maps. Dejar vacío para no modificar.</small>
                                <div v-if="googleWithoutKey" class="small text-warning-emphasis mt-1">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Sin clave de Google Maps se utilizará OpenStreetMap automáticamente como respaldo.
                                </div>
                                <div v-if="maps.own" class="form-check mt-2">
                                    <input id="clear_maps_api_key" v-model="form.clear_maps_api_key" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="clear_maps_api_key">Eliminar clave personalizada guardada</label>
                                </div>
                            </div>

                            <button type="button" class="btn-integration-action" :disabled="busy.geo" @click="testGeocode">
                                <span v-if="busy.geo" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                <i v-else class="bi bi-geo-alt-fill me-2"></i>
                                Probar geocodificación
                            </button>
                            <span class="small settings-helper-text ms-2">con {{ geoProbe.label }} ({{ geoProbe.lat.toFixed(4) }}, {{ geoProbe.lng.toFixed(4) }})</span>

                            <div v-if="geo" class="mt-3 p-2 rounded bg-body-tertiary border" aria-live="polite">
                                <template v-if="geo.ok">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <span class="badge text-bg-primary">{{ geo.provider }}</span>
                                        <span class="small text-secondary">{{ geo.ms }} ms</span>
                                    </div>
                                    <div class="small fw-medium mt-1">{{ geo.address }}</div>
                                </template>
                                <div v-else class="small text-danger d-flex align-items-center gap-1">
                                    <i class="bi bi-x-circle-fill"></i>
                                    <span>{{ geo.error }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notificaciones push (Firebase Cloud Messaging) -->
                <div v-if="has.fcm_enabled || has.fcm_account" class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm integrations-card h-100">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Notificaciones push (Firebase FCM)</h2>
                            <small class="settings-helper-text">Transmisión de avisos a teléfonos inteligentes mediante Firebase Cloud Messaging.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <template v-if="has.fcm_enabled">
                                <div v-if="isGlobal" class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mb-3 ps-0">
                                    <label class="form-check-label" for="fcm_enabled">
                                        <span class="d-block fw-semibold text-body">Habilitar notificaciones push</span>
                                        <span class="d-block small settings-helper-text">Envía notificaciones nativas a los dispositivos Android/iOS además del aviso in-app.</span>
                                    </label>
                                    <input id="fcm_enabled" v-model="form.fcm_enabled" class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" style="width: 2.85em; height: 1.5em; cursor: pointer;">
                                </div>
                                <div v-else class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                                    <label class="form-label mb-0" for="fcm_enabled">
                                        <span class="d-block fw-semibold text-body">Habilitar notificaciones push</span>
                                        <span class="d-block small settings-helper-text">Envía notificaciones nativas a los teléfonos además del aviso in-app.</span>
                                    </label>
                                    <div class="modern-input-wrapper w-auto">
                                        <select id="fcm_enabled" v-model="form.fcm_enabled" class="modern-input-control" :class="{ 'is-invalid': form.errors.fcm_enabled }">
                                            <option value="">Heredar de la plataforma (actual: {{ inherited.fcm_enabled ? 'Sí' : 'No' }})</option>
                                            <option value="1">Sí</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                </div>
                            </template>

                            <div v-if="has.fcm_account" class="mb-3">
                                <span class="form-label small fw-semibold text-body d-block mb-1">Cuenta de servicio de Google Firebase</span>
                                <template v-if="fcm.configured">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Configurado{{ isGlobal ? '' : ' por la plataforma' }}</span>
                                    <span v-if="isGlobal && fcm.project_id" class="small text-secondary ms-2 font-monospace">{{ fcm.project_id }} · {{ fcm.client_email }}</span>
                                </template>
                                <span v-else class="badge bg-secondary-subtle text-secondary border px-2 py-1">No configurado</span>

                                <template v-if="isGlobal">
                                    <textarea id="fcm_service_account_json" v-model="form.fcm_service_account_json" rows="3" spellcheck="false" autocomplete="off"
                                              class="form-control font-monospace small mt-2" :class="{ 'is-invalid': form.errors.fcm_service_account_json }"
                                              placeholder='{"type": "service_account", "project_id": "…", "private_key": "…", "client_email": "…"}'></textarea>
                                    <div v-if="form.errors.fcm_service_account_json" class="text-danger small mt-1">{{ form.errors.fcm_service_account_json }}</div>
                                    <input type="file" accept="application/json,.json" class="form-control form-control-sm mt-2" :class="{ 'is-invalid': form.errors.fcm_service_account_file }"
                                           aria-label="Subir archivo JSON de credencial Firebase" @input="form.fcm_service_account_file = $event.target.files[0]">
                                    <small class="settings-helper-text d-block mt-1">Pega el JSON o sube el archivo de credenciales de Firebase. Se almacena cifrado en la base de datos.</small>
                                    <div v-if="fcm.configured" class="form-check mt-2">
                                        <input id="clear_fcm_service_account_json" v-model="form.clear_fcm_service_account_json" class="form-check-input" type="checkbox">
                                        <label class="form-check-label small" for="clear_fcm_service_account_json">Eliminar credencial guardada</label>
                                    </div>
                                </template>
                                <div v-else class="small settings-helper-text mt-1">La credencial de Firebase se administra centralizadamente desde la plataforma.</div>
                            </div>

                            <label for="push_email" class="form-label small fw-semibold text-body mb-1">Destinatario para prueba push</label>
                            <div class="modern-input-wrapper mb-1">
                                <span class="modern-input-icon"><i class="bi bi-phone-vibrate"></i></span>
                                <input id="push_email" v-model="pushEmail" type="email" maxlength="255" :placeholder="userEmail" class="modern-input-control">
                            </div>
                            <small class="settings-helper-text d-block mb-2">Correo del usuario registrado en la app (dejar vacío para tu propio dispositivo).</small>
                            <button type="button" class="btn-integration-action" :disabled="busy.push" @click="test('push', '/settings/branding/test-push', { email: pushEmail.trim() })">
                                <span v-if="busy.push" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                                <i v-else class="bi bi-phone-vibrate-fill me-2"></i>
                                Enviar push de prueba
                            </button>
                            <TestResult :result="results.push" />
                        </div>
                    </div>
                </div>

                <!-- Credenciales de servicios externos (solo SuperAdmin) -->
                <div v-if="services" class="col-12">
                    <div class="card border-0 shadow-sm integrations-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Credenciales de servicios <span class="badge text-bg-dark ms-1">SuperAdmin</span></h2>
                            <p class="small settings-helper-text mb-0 mt-1">
                                {{ isGlobal ? 'Valores de la plataforma: los usan las empresas que no definan los suyos.' : `Propias de ${ownerName}. Vacío = usar los de la plataforma.` }}
                                Las claves se guardan cifradas y nunca se muestran completas.
                            </p>
                        </div>
                        <div class="card-body p-3 pt-1 row g-4">
                            <!-- Migo -->
                            <div class="col-lg-4">
                                <h3 class="small fw-bold mb-2"><i class="bi bi-search me-1"></i>Migo (RUC / DNI)
                                    <span class="badge ms-1" :class="services.effective.migo_configured ? 'text-bg-success' : 'text-bg-secondary'">{{ services.effective.migo_configured ? 'activo' : 'sin token' }}</span>
                                </h3>
                                <label for="migo_api_token" class="form-label small fw-semibold mb-1">Token</label>
                                <input id="migo_api_token" v-model="form.migo_api_token" type="password" maxlength="500" autocomplete="new-password" class="form-control form-control-sm font-monospace"
                                       :class="{ 'is-invalid': form.errors.migo_api_token }" :placeholder="services.secrets.migo_api_token.mask ?? (isGlobal ? 'Sin token' : 'Usa el de la plataforma')">
                                <div class="invalid-feedback">{{ form.errors.migo_api_token }}</div>
                                <div v-if="services.secrets.migo_api_token.own" class="form-check mt-1">
                                    <input id="clear_migo_api_token" v-model="form.clear_migo_api_token" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="clear_migo_api_token">Eliminar token guardado</label>
                                </div>
                                <label for="migo_ruc_url" class="form-label small fw-semibold mb-1 mt-2">URL RUC</label>
                                <input id="migo_ruc_url" v-model="form.migo_ruc_url" type="url" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.migo_ruc_url }" placeholder="https://api.migo.pe/api/v1/ruc">
                                <div class="invalid-feedback">{{ form.errors.migo_ruc_url }}</div>
                                <label for="migo_dni_url" class="form-label small fw-semibold mb-1 mt-2">URL DNI</label>
                                <input id="migo_dni_url" v-model="form.migo_dni_url" type="url" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.migo_dni_url }" placeholder="https://api.migo.pe/api/v1/dni">
                                <div class="invalid-feedback">{{ form.errors.migo_dni_url }}</div>
                            </div>
                            <!-- Nubefact -->
                            <div class="col-lg-4">
                                <h3 class="small fw-bold mb-2"><i class="bi bi-receipt me-1"></i>Nubefact (facturación)
                                    <span v-if="services.effective.nubefact_configured" class="badge ms-1" :class="services.effective.nubefact_demo ? 'text-bg-warning' : 'text-bg-success'">{{ services.effective.nubefact_demo ? 'demo' : 'producción' }}</span>
                                </h3>
                                <label for="nubefact_url" class="form-label small fw-semibold mb-1">Ruta de la API</label>
                                <input id="nubefact_url" v-model="form.nubefact_url" type="url" class="form-control form-control-sm font-monospace" :class="{ 'is-invalid': form.errors.nubefact_url }" placeholder="https://api.nubefact.com/api/v1/…">
                                <div class="invalid-feedback">{{ form.errors.nubefact_url }}</div>
                                <label for="nubefact_token" class="form-label small fw-semibold mb-1 mt-2">Token</label>
                                <input id="nubefact_token" v-model="form.nubefact_token" type="password" maxlength="500" autocomplete="new-password" class="form-control form-control-sm font-monospace"
                                       :class="{ 'is-invalid': form.errors.nubefact_token }" :placeholder="services.secrets.nubefact_token.mask ?? (isGlobal ? 'Sin token' : 'Usa el de la plataforma')">
                                <div class="invalid-feedback">{{ form.errors.nubefact_token }}</div>
                                <div v-if="services.secrets.nubefact_token.own" class="form-check mt-1">
                                    <input id="clear_nubefact_token" v-model="form.clear_nubefact_token" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="clear_nubefact_token">Eliminar token guardado</label>
                                </div>
                            </div>
                            <!-- SMTP -->
                            <div class="col-lg-4">
                                <h3 class="small fw-bold mb-2"><i class="bi bi-envelope me-1"></i>Correo (SMTP)
                                    <span class="badge ms-1" :class="services.effective.smtp ? 'text-bg-success' : 'text-bg-secondary'">{{ services.effective.smtp ?? 'mailer del servidor' }}</span>
                                </h3>
                                <div class="row g-2">
                                    <div class="col-8">
                                        <label for="smtp_host" class="form-label small fw-semibold mb-1">Servidor</label>
                                        <input id="smtp_host" v-model.trim="form.smtp_host" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.smtp_host }" placeholder="smtp.gmail.com">
                                        <div class="invalid-feedback">{{ form.errors.smtp_host }}</div>
                                    </div>
                                    <div class="col-4">
                                        <label for="smtp_port" class="form-label small fw-semibold mb-1">Puerto</label>
                                        <input id="smtp_port" v-model="form.smtp_port" type="number" min="1" max="65535" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.smtp_port }" placeholder="587">
                                        <div class="invalid-feedback">{{ form.errors.smtp_port }}</div>
                                    </div>
                                    <div class="col-6">
                                        <label for="smtp_encryption" class="form-label small fw-semibold mb-1">Cifrado</label>
                                        <select id="smtp_encryption" v-model="form.smtp_encryption" class="form-select form-select-sm">
                                            <option :value="null">TLS (por defecto)</option>
                                            <option value="tls">TLS</option>
                                            <option value="ssl">SSL</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label for="smtp_username" class="form-label small fw-semibold mb-1">Usuario</label>
                                        <input id="smtp_username" v-model.trim="form.smtp_username" autocomplete="off" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.smtp_username }">
                                    </div>
                                    <div class="col-12">
                                        <label for="smtp_password" class="form-label small fw-semibold mb-1">Contraseña de aplicación</label>
                                        <input id="smtp_password" v-model="form.smtp_password" type="password" autocomplete="new-password" class="form-control form-control-sm font-monospace"
                                               :class="{ 'is-invalid': form.errors.smtp_password }" :placeholder="services.secrets.smtp_password.mask ?? 'Sin contraseña'">
                                        <div v-if="services.secrets.smtp_password.own" class="form-check mt-1">
                                            <input id="clear_smtp_password" v-model="form.clear_smtp_password" class="form-check-input" type="checkbox">
                                            <label class="form-check-label small" for="clear_smtp_password">Eliminar contraseña guardada</label>
                                        </div>
                                    </div>
                                    <div class="col-7">
                                        <label for="mail_from_address" class="form-label small fw-semibold mb-1">Remitente</label>
                                        <input id="mail_from_address" v-model.trim="form.mail_from_address" type="email" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.mail_from_address }" placeholder="notificaciones@empresa.com">
                                        <div class="invalid-feedback">{{ form.errors.mail_from_address }}</div>
                                    </div>
                                    <div class="col-5">
                                        <label for="mail_from_name" class="form-label small fw-semibold mb-1">Nombre</label>
                                        <input id="mail_from_name" v-model="form.mail_from_name" maxlength="150" class="form-control form-control-sm">
                                    </div>
                                </div>
                                <small class="settings-helper-text d-block mt-1">Sin servidor propio, los avisos salen por el correo del servidor. Cada aviso se envía una sola vez.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barra de Guardado -->
                <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 integrations-card shadow-sm">
                    <span class="small settings-helper-text">
                        <template v-if="updatedAt">
                            <i class="bi bi-clock-history me-1 text-primary"></i> <strong>Último cambio:</strong> {{ updatedAt }}
                        </template>
                    </span>
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center px-4 py-2.5 fw-bold shadow-sm" style="border-radius: 9px; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);" :disabled="form.processing">
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                        <i v-else class="bi bi-floppy2-fill me-2"></i> Guardar cambios
                    </button>
                </div>

                <!-- Historial de Envíos de Webhook -->
                <div class="col-12">
                    <DeliveriesTable ref="deliveries" :request="requestJson"
                                     :subtitle="isGlobal ? 'Pruebas hechas desde la vista global.' : `Webhook y endpoint de notificaciones de ${ownerName}. Se guardan 30 días.`" />
                </div>
            </div>
        </form>
    </AppLayout>
</template>
