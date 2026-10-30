{{-- Encabezado común de Ajustes: título, etiqueta de alcance y navegación entre secciones --}}
<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">{{ $title }}</h1>
        <p class="page-subtitle">{{ $subtitle }}</p>
        @isset($pill)
            <span class="status-pill tone-{{ $pill[0] }} mt-2">{{ $pill[1] }}</span>
        @endisset
    </div>
    <nav class="page-actions" aria-label="Secciones de ajustes">
        <div class="seg-tabs">
            @foreach (['branding' => ['Marca y apariencia', 'palette'], 'mobile' => ['App móvil', 'phone'], 'integrations' => ['Integraciones', 'plug']] as $key => [$label, $icon])
                <a href="{{ route('settings.' . $key) }}" class="seg-tab {{ $active === $key ? 'is-active' : '' }}" @if($active === $key) aria-current="page" @endif>
                    <i class="bi bi-{{ $icon }}"></i>{{ $label }}
                </a>
            @endforeach
        </div>
    </nav>
</header>
