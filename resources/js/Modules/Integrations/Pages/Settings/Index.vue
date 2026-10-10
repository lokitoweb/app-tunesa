<script setup>
import { computed, reactive, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import DeliveriesTable from '../../Components/DeliveriesTable.vue';
import TestResult from '../../Components/TestResult.vue';

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
});

const page = usePage();
const PROVIDERS = { nominatim: 'OpenStreetMap / Nominatim — gratis', google: 'Google Maps Geocoding' };

const form = useForm({
    ...props.values,
    maps_api_key: '',
    clear_maps_api_key: false,
    fcm_service_account_json: '',
    fcm_service_account_file: null,
    clear_fcm_service_account_json: false,
});

function save() {
    form
        .transform((data) => {
            // Lo que no existe en este servidor o no aplica a una empresa no se envía
            const out = { ...data, _method: 'put' };
            if (!props.has.geocoding_provider) delete out.geocoding_provider;
            if (!props.has.fcm_enabled) delete out.fcm_enabled;
            else if (props.isGlobal) out.fcm_enabled = data.fcm_enabled ? '1' : '0';
            if (!props.isGlobal || !props.has.fcm_account) {
                delete out.fcm_service_account_json;
                delete out.fcm_service_account_file;
                delete out.clear_fcm_service_account_json;
            }
            for (const flag of ['clear_maps_api_key', 'clear_fcm_service_account_json']) {
                if (flag in out) out[flag] = out[flag] ? '1' : '0';
            }
            return out;
        })
        .post('/settings/integrations', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset('maps_api_key', 'clear_maps_api_key', 'fcm_service_account_json', 'fcm_service_account_file', 'clear_fcm_service_account_json'),
        });
}

// Peticiones JSON a los endpoints de prueba (misma sesión, token CSRF del layout)
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

// Prueba al instante con lo escrito en el formulario (aunque no esté guardado)
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
        secretNote.value = { ok: true, text: 'Secreto copiado.' };
    } catch (e) {
        // Sin permiso de portapapeles (http): se muestra para copiarlo a mano
        showSecret.value = true;
        secretNote.value = { ok: false, text: 'No se pudo copiar: selecciónalo y cópialo a mano.' };
    }
}
async function regenerateSecret() {
    if (!confirm('¿Generar un secreto nuevo? Las firmas con el actual dejarán de ser válidas en tu sistema.')) return;
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

// Aviso de respaldo a OpenStreetMap si se elige Google sin clave
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
    <AppLayout title="Integraciones">
        <div class="page-header">
            <div>
                <h1>Integraciones</h1>
                <p>Webhook, geocodificación y notificaciones push de {{ isGlobal ? 'la plataforma' : ownerName }}.</p>
            </div>
            <a href="/settings/branding" class="btn btn-outline-secondary"><i class="bi bi-palette me-1"></i> Marca y app</a>
        </div>

        <div v-if="isGlobal" class="alert alert-info d-flex align-items-start gap-2" role="status">
            <i class="bi bi-globe2"></i>
            <div>
                <strong>Valores por defecto de la plataforma.</strong>
                El proveedor de geocodificación, la clave de mapas y el push los heredan las empresas que no definieron los suyos.
                Para editar una empresa en particular, elígela en el selector superior.
            </div>
        </div>

        <form novalidate @submit.prevent="save">
            <div class="row g-3">
                <!-- Webhook y endpoint de notificaciones -->
                <div class="col-12">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Webhook y notificaciones</h2>
                            <small class="text-body-secondary">Se guardan cifradas. El secreto solo lo ven quienes administran {{ isGlobal ? 'la plataforma' : 'la empresa' }} y nunca se envía a la app.</small>
                            <small v-if="isGlobal" class="d-block text-body-secondary">El webhook y el endpoint de notificaciones son propios de cada empresa: en la vista global solo sirven para probar la conexión, no se heredan ni reciben eventos.</small>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="webhook_url" class="form-label">URL del webhook</label>
                                    <input id="webhook_url" v-model="form.webhook_url" type="url" maxlength="255" placeholder="https://erp.empresa.com/webhooks/asistencia" class="form-control" :class="{ 'is-invalid': form.errors.webhook_url }">
                                    <div v-if="form.errors.webhook_url" class="invalid-feedback">{{ form.errors.webhook_url }}</div>
                                    <div class="form-text">Recibe un POST JSON por cada entrada, inicio y fin de descanso y salida, y por cada cambio de estado de un permiso (para ERP o planilla).</div>
                                    <div v-if="form.webhook_url === receivers.webhook_url" class="form-text text-info-emphasis"><i class="bi bi-info-circle me-1"></i>Precargada con el receptor de la plataforma, que verifica la firma. Reemplázala por la URL de tu sistema cuando la tengas.</div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" :disabled="busy.webhook" @click="test('webhook', '/settings/branding/test-webhook', { url: form.webhook_url?.trim(), secret: form.webhook_secret })">
                                        <span v-if="busy.webhook" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><i v-else class="bi bi-send me-1"></i>Probar Webhook Ahora
                                    </button>
                                    <TestResult :result="results.webhook" />
                                </div>
                                <div class="col-md-6">
                                    <label for="webhook_secret" class="form-label">Secreto del webhook</label>
                                    <div class="input-group" :class="{ 'has-validation': form.errors.webhook_secret }">
                                        <input id="webhook_secret" v-model="form.webhook_secret" :type="showSecret ? 'text' : 'password'" maxlength="255" minlength="16" autocomplete="off" spellcheck="false" required
                                               class="form-control font-monospace" :class="{ 'is-invalid': form.errors.webhook_secret }">
                                        <button type="button" class="btn btn-outline-secondary" :aria-pressed="showSecret" :title="showSecret ? 'Ocultar el secreto' : 'Mostrar el secreto'" :aria-label="showSecret ? 'Ocultar el secreto' : 'Mostrar el secreto'" @click="showSecret = !showSecret">
                                            <i class="bi" :class="showSecret ? 'bi-eye-slash' : 'bi-eye'"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" title="Copiar el secreto" aria-label="Copiar el secreto" @click="copySecret">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <div v-if="form.errors.webhook_secret" class="invalid-feedback">{{ form.errors.webhook_secret }}</div>
                                    </div>
                                    <div class="form-text">Firma cada envío en la cabecera <code>X-Tunesa-Signature: sha256=…</code> (HMAC-SHA256 del cuerpo). Configura este mismo valor en tu sistema para validar la firma.</div>
                                    <button type="button" class="btn btn-sm btn-outline-warning mt-2" :disabled="busy.secret" @click="regenerateSecret">
                                        <i class="bi bi-arrow-repeat me-1"></i>Regenerar secreto
                                    </button>
                                    <div v-if="secretNote" class="mt-2 small" :class="secretNote.ok ? 'text-success' : 'text-danger'" aria-live="polite">
                                        <i class="bi me-1" :class="secretNote.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'"></i>{{ secretNote.text }}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="notification_endpoint" class="form-label">Endpoint de notificaciones</label>
                                    <input id="notification_endpoint" v-model="form.notification_endpoint" type="url" maxlength="255" placeholder="https://notificaciones.empresa.com/push" class="form-control" :class="{ 'is-invalid': form.errors.notification_endpoint }">
                                    <div v-if="form.errors.notification_endpoint" class="invalid-feedback">{{ form.errors.notification_endpoint }}</div>
                                    <div class="form-text">Recibe un POST JSON por cada aviso in-app creado para un usuario de la empresa, con la misma firma que el webhook.</div>
                                    <div v-if="form.notification_endpoint === receivers.notification_endpoint" class="form-text text-info-emphasis"><i class="bi bi-info-circle me-1"></i>Precargada con el receptor de la plataforma. Reemplázala por la URL de tu sistema cuando la tengas.</div>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" :disabled="busy.notification" @click="test('notification', '/settings/branding/test-notification', { url: form.notification_endpoint?.trim(), secret: form.webhook_secret })">
                                        <span v-if="busy.notification" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><i v-else class="bi bi-bell me-1"></i>Enviar Notificación de Prueba
                                    </button>
                                    <TestResult :result="results.notification" />
                                </div>
                                <div class="col-md-6">
                                    <p class="small text-body-secondary mb-0 mt-md-4">
                                        Las pruebas se envían al instante a la URL escrita arriba (aunque no la hayas guardado) con el evento
                                        <code>ping</code> o <code>notification.test</code>, firmadas con el secreto del webhook.
                                        Los eventos reales se envían en segundo plano y se reintentan hasta 5 veces si fallan.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Geocodificación -->
                <div class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Geocodificación</h2>
                            <small class="text-body-secondary">Convierte coordenadas en direcciones (y al revés) en el formulario de sucursales.</small>
                        </div>
                        <div class="card-body">
                            <div v-if="has.geocoding_provider" class="mb-3">
                                <label for="geocoding_provider" class="form-label">Proveedor</label>
                                <select id="geocoding_provider" v-model="form.geocoding_provider" class="form-select" :class="{ 'is-invalid': form.errors.geocoding_provider }">
                                    <option v-if="!isGlobal" value="">Heredar de la plataforma (actual: {{ PROVIDERS[inherited.geocoding_provider] ?? inherited.geocoding_provider }})</option>
                                    <option v-for="(label, key) in PROVIDERS" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <div v-if="form.errors.geocoding_provider" class="invalid-feedback">{{ form.errors.geocoding_provider }}</div>
                            </div>
                            <div class="mb-3">
                                <label for="maps_api_key" class="form-label">Clave de API de Google Maps</label>
                                <input id="maps_api_key" v-model="form.maps_api_key" type="password" maxlength="255" autocomplete="new-password"
                                       :placeholder="maps.mask ?? (!isGlobal && maps.any ? 'Usa la clave de la plataforma' : 'Sin clave')"
                                       class="form-control font-monospace" :class="{ 'is-invalid': form.errors.maps_api_key }">
                                <div class="form-text">Necesaria solo con Google (API "Geocoding" habilitada). Dejar vacío para no cambiar.</div>
                                <div v-if="googleWithoutKey" class="form-text text-warning-emphasis">
                                    <i class="bi bi-info-circle me-1"></i>Sin clave de Google se usará OpenStreetMap automáticamente.
                                </div>
                                <div v-if="maps.own" class="form-check mt-1">
                                    <input id="clear_maps_api_key" v-model="form.clear_maps_api_key" class="form-check-input" type="checkbox">
                                    <label class="form-check-label small" for="clear_maps_api_key">Borrar la clave guardada</label>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy.geo" @click="testGeocode">
                                <i class="bi bi-geo-alt me-1"></i>Probar geocodificación
                            </button>
                            <span class="small text-body-secondary ms-1">con {{ geoProbe.label }} ({{ geoProbe.lat.toFixed(5) }}, {{ geoProbe.lng.toFixed(5) }})</span>
                            <div v-if="geo" class="mt-2" aria-live="polite">
                                <template v-if="geo.ok">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <span class="badge text-bg-primary">{{ geo.provider }}</span>
                                        <span class="small text-body-secondary">{{ geo.ms }} ms</span>
                                    </div>
                                    <div class="small mt-1">{{ geo.address }}</div>
                                </template>
                                <div v-else class="small text-danger"><i class="bi bi-x-circle-fill me-1"></i>{{ geo.error }}</div>
                            </div>
                            <p class="small text-body-secondary mt-2 mb-0">La prueba usa la configuración guardada: guarda antes si cambiaste el proveedor o la clave.</p>
                        </div>
                    </div>
                </div>

                <!-- Notificaciones push (Firebase Cloud Messaging) -->
                <div v-if="has.fcm_enabled || has.fcm_account" class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Notificaciones push</h2>
                            <small class="text-body-secondary">Avisos al celular del colaborador con Firebase Cloud Messaging.</small>
                        </div>
                        <div class="card-body">
                            <template v-if="has.fcm_enabled">
                                <!-- Plataforma: interruptor. Empresa: Heredar / Sí / No -->
                                <div v-if="isGlobal" class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mb-3 ps-0">
                                    <label class="form-check-label" for="fcm_enabled">
                                        <span class="d-block fw-semibold">Enviar notificaciones push</span>
                                        <span class="d-block small text-body-secondary">Además del aviso dentro de la app, se envía una notificación al teléfono.</span>
                                    </label>
                                    <input id="fcm_enabled" v-model="form.fcm_enabled" class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch">
                                </div>
                                <div v-else class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                                    <label class="form-label mb-0" for="fcm_enabled">
                                        <span class="d-block fw-semibold">Enviar notificaciones push</span>
                                        <span class="d-block small text-body-secondary">Además del aviso dentro de la app, se envía una notificación al teléfono.</span>
                                    </label>
                                    <select id="fcm_enabled" v-model="form.fcm_enabled" class="form-select flex-shrink-0 w-auto" :class="{ 'is-invalid': form.errors.fcm_enabled }">
                                        <option value="">Heredar de la plataforma (actual: {{ inherited.fcm_enabled ? 'Sí' : 'No' }})</option>
                                        <option value="1">Sí</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                            </template>

                            <div v-if="has.fcm_account" class="mb-3">
                                <span class="form-label d-block">Cuenta de servicio de Firebase</span>
                                <template v-if="fcm.configured">
                                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Configurado{{ isGlobal ? '' : ' por la plataforma' }}</span>
                                    <span v-if="isGlobal && fcm.project_id" class="small text-body-secondary ms-1">Proyecto <code>{{ fcm.project_id }}</code> · {{ fcm.client_email }}</span>
                                </template>
                                <span v-else class="badge text-bg-secondary">No configurado</span>
                                <template v-if="isGlobal">
                                    <textarea id="fcm_service_account_json" v-model="form.fcm_service_account_json" rows="4" spellcheck="false" autocomplete="off"
                                              class="form-control font-monospace small mt-2" :class="{ 'is-invalid': form.errors.fcm_service_account_json }"
                                              placeholder='{"type": "service_account", "project_id": "…", "private_key": "…", "client_email": "…"}'></textarea>
                                    <div v-if="form.errors.fcm_service_account_json" class="invalid-feedback">{{ form.errors.fcm_service_account_json }}</div>
                                    <input type="file" accept="application/json,.json" class="form-control form-control-sm mt-2" :class="{ 'is-invalid': form.errors.fcm_service_account_file }"
                                           aria-label="Subir el archivo JSON de la cuenta de servicio" @input="form.fcm_service_account_file = $event.target.files[0]">
                                    <div v-if="form.errors.fcm_service_account_file" class="invalid-feedback">{{ form.errors.fcm_service_account_file }}</div>
                                    <div class="form-text">Pega el JSON o sube el archivo que descargas en Firebase › Configuración del proyecto › Cuentas de servicio. Debe incluir <code>project_id</code>, <code>client_email</code> y <code>private_key</code>. Se guarda cifrado; dejar vacío para no cambiar.</div>
                                    <div v-if="fcm.configured" class="form-check mt-1">
                                        <input id="clear_fcm_service_account_json" v-model="form.clear_fcm_service_account_json" class="form-check-input" type="checkbox">
                                        <label class="form-check-label small" for="clear_fcm_service_account_json">Borrar la cuenta de servicio guardada</label>
                                    </div>
                                </template>
                                <div v-else class="form-text">La cuenta de Firebase la administra la plataforma para todas las empresas.</div>
                            </div>

                            <label for="push_email" class="form-label">Probar push</label>
                            <input id="push_email" v-model="pushEmail" type="email" maxlength="255" :placeholder="userEmail" class="form-control mb-1">
                            <div class="form-text mb-2">Correo de un usuario que ya inició sesión en la app (vacío: tu propio celular). Se envía aunque el push esté desactivado.</div>
                            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy.push" @click="test('push', '/settings/branding/test-push', { email: pushEmail.trim() })">
                                <span v-if="busy.push" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span><i v-else class="bi bi-phone-vibrate me-1"></i>Enviar push de prueba
                            </button>
                            <TestResult :result="results.push" />
                        </div>
                    </div>
                </div>

                <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small text-body-secondary"><template v-if="updatedAt">Último cambio: {{ updatedAt }}</template></span>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
                </div>

                <div class="col-12">
                    <DeliveriesTable ref="deliveries" :request="requestJson"
                                     :subtitle="isGlobal ? 'Pruebas hechas desde la vista global.' : `Webhook y endpoint de notificaciones de ${ownerName}. Se guardan 30 días.`" />
                </div>
            </div>
        </form>
    </AppLayout>
</template>
