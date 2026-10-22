import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/**
 * Super Admin en la vista global ("Todas las empresas"): antes de crear algo
 * pregunta "¿En qué empresa?" en lugar de un aviso.
 *
 * - Con una sola empresa activa no pregunta: el servidor (SetWebTenant) crea
 *   el registro en ella.
 * - Con varias, abre el selector; al elegir, el panel pasa a esa empresa y
 *   vuelve a la misma pantalla con ?nuevo=<intent> para reabrir el formulario
 *   (consumeIntent).
 * - Al editar, el registro ya trae su empresa: withTenant() la envía como
 *   _tenant y el servidor entra en ella sin preguntar.
 */
const pickerState = ref({ show: false, title: '', message: '', intent: null });

export function useTenantPicker() {
    const page = usePage();

    const activeTenants = () => (page.props.tenants || []).filter((t) => t.status === 'active');

    /** true si se puede seguir creando aquí; false si se abrió el selector */
    function ensureTenant({ intent = null, title = '¿En qué empresa?', message = '' } = {}) {
        if (!page.props.globalView || activeTenants().length <= 1) {
            return true;
        }

        pickerState.value = { show: true, title, message, intent };

        return false;
    }

    function choose(tenantId) {
        const url = new URL(window.location.href);
        url.searchParams.delete('nuevo');
        if (pickerState.value.intent) {
            url.searchParams.set('nuevo', pickerState.value.intent);
        }
        pickerState.value.show = false;

        router.post('/admin/switch-tenant', { tenant_id: tenantId, next: url.pathname + url.search });
    }

    function close() {
        pickerState.value.show = false;
    }

    /** Lee y limpia ?nuevo=<intent> de la URL (tras elegir empresa) */
    function consumeIntent(intent) {
        const url = new URL(window.location.href);
        if (url.searchParams.get('nuevo') !== intent) {
            return false;
        }
        url.searchParams.delete('nuevo');
        window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);

        return true;
    }

    /** Datos de un formulario de edición con la empresa del registro (vista global) */
    function withTenant(data, tenantId) {
        return page.props.globalView && tenantId ? { ...data, _tenant: tenantId } : data;
    }

    return { pickerState, activeTenants, ensureTenant, choose, close, consumeIntent, withTenant };
}
