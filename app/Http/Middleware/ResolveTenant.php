<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: ResolveTenant
 *
 * Estrategia de resolución del tenant por ORDEN DE PRIORIDAD:
 *
 * 1. Cabecera HTTP:    X-Tenant-Slug: empresa-acme       (App móvil Flutter)
 * 2. Subdominio:       empresa-acme.tunesa.app            (Panel web)
 * 3. Parámetro URL:    ?tenant=empresa-acme               (Solo para testing)
 *
 * Una vez resuelto, el tenant se inyecta en:
 * - TenantContext (singleton en el Service Container)
 * - config('tenant') para acceso rápido
 * - Todos los modelos a través del GlobalScope automático
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenantFromRequest($request);

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant no identificado. Verifica la cabecera X-Tenant-Slug.',
                'code'    => 'TENANT_NOT_FOUND',
            ], 404);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta de la empresa está suspendida o cancelada.',
                'code'    => 'TENANT_INACTIVE',
            ], 403);
        }

        // Registrar el tenant resuelto en el contexto global
        $this->tenantContext->set($tenant);

        // Hacer el tenant disponible en toda la request
        $request->merge(['resolved_tenant' => $tenant]);
        $request->attributes->set('tenant', $tenant);

        // Configurar la zona horaria del tenant para esta petición
        config(['app.timezone' => $tenant->timezone]);
        date_default_timezone_set($tenant->timezone);

        return $next($request);
    }

    // =========================================================================
    // RESOLUCIÓN DEL TENANT
    // =========================================================================

    /**
     * Intenta resolver el tenant usando las 3 estrategias disponibles.
     */
    private function resolveTenantFromRequest(Request $request): ?Tenant
    {
        // Estrategia 1: Cabecera HTTP (prioritaria para la app móvil)
        if ($slug = $request->header('X-Tenant-Slug')) {
            return $this->findTenantBySlug($slug);
        }

        // Estrategia 2: Subdominio (para el panel web)
        if ($slug = $this->extractSlugFromSubdomain($request)) {
            return $this->findTenantBySlug($slug);
        }

        // Estrategia 3: Query param (solo desarrollo/testing)
        if (app()->isLocal() && $slug = $request->query('tenant')) {
            return $this->findTenantBySlug($slug);
        }

        return null;
    }

    /**
     * Extrae el slug del subdominio de la URL.
     * Ej: "empresa-acme.tunesa.app" → "empresa-acme"
     */
    private function extractSlugFromSubdomain(Request $request): ?string
    {
        $host       = $request->getHost();
        $appDomain  = config('app.domain', 'tunesa.app');

        // Si el host coincide exactamente con el dominio base, no hay subdominio
        if ($host === $appDomain) {
            return null;
        }

        // Verifica que el host termina con el dominio base
        if (str_ends_with($host, ".{$appDomain}")) {
            $subdomain = str_replace(".{$appDomain}", '', $host);

            // Excluir subdominios del sistema
            $systemSubdomains = ['www', 'api', 'admin', 'app', 'mail', 'cdn'];
            if (in_array(strtolower($subdomain), $systemSubdomains)) {
                return null;
            }

            return $subdomain;
        }

        return null;
    }

    /**
     * Busca y cachea el tenant por slug.
     * Cache de 5 minutos para evitar consultas repetidas en cada request.
     */
    private function findTenantBySlug(string $slug): ?Tenant
    {
        return cache()->remember(
            "tenant:slug:{$slug}",
            now()->addMinutes(5),
            fn () => Tenant::where('slug', $slug)->first()
        );
    }
}
