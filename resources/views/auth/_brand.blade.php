{{-- Logo y nombre de la empresa (marca blanca) o de TuneSA --}}
    <div class="text-center mb-4">
        @if(isset($tenant) && $tenant)
            @if($tenant->logo_path)
                <img src="{{ asset('storage/' . $tenant->logo_path) }}" alt="{{ $tenant->company_name }}" class="mb-2" style="max-height: 56px;">
            @endif
            <div class="h5 fw-bold mb-0">{{ $tenant->company_name }}</div>
        @else
            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white mb-2" style="width: 48px; height: 48px;">
                <i class="bi bi-shield-check fs-4"></i>
            </span>
            <div class="h5 fw-bold mb-0">TuneSA</div>
        @endif
        <div class="small text-body-secondary">Asistencia, geocercas y planilla</div>
    </div>
