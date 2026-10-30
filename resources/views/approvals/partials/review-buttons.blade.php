{{-- Aprobar / Rechazar: abren el diálogo #reviewModal con estos datos --}}
@foreach (['approved' => ['Aprobar', 'btn-success', 'check-lg'], 'rejected' => ['Rechazar', 'btn-outline-danger', 'x-lg']] as $decision => [$label, $btn, $icon])
    <button type="button" class="btn btn-sm {{ $btn }}" data-review
            data-action="{{ $action }}" data-kind="{{ $kind }}" data-decision="{{ $decision }}"
            data-who="{{ $who }}" data-what="{{ $what }}" @isset($hours) data-hours="{{ $hours }}" @endisset>
        <i class="bi bi-{{ $icon }} me-1"></i>{{ $label }}
    </button>
@endforeach
