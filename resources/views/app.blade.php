<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('layouts.partials.theme-boot')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/panel.css') }}?v={{ filemtime(public_path('css/panel.css')) }}">
    @vite('resources/js/app.js')
    {{-- Después del CSS del bundle: los colores de la marca deben ganarle a los de fábrica --}}
    @include('layouts.partials.brand-head', ['brandSettings' => \App\Modules\Core\Models\TenantSetting::forTenant(app(\App\Modules\Core\Services\TenantContext::class)->getOrNull() ?? Auth::user()?->tenant)])
    @inertiaHead
</head>
<body>
    @if(isset($page['props']['loadPeruLabel']))
        <span class="d-none">{{ $page['props']['loadPeruLabel'] }}</span>
    @endif
    @if(isset($page['props']['holidays']) && is_array($page['props']['holidays']))
        <div class="d-none" aria-hidden="true">
            @foreach($page['props']['holidays'] as $h)
                <span>{{ $h['name'] ?? '' }}</span>
                <span>{{ $h['formatted_date'] ?? '' }}</span>
            @endforeach
        </div>
    @endif
    @inertia
</body>
</html>
