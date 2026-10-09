{{-- Detalle de un registro: resumen + [Ver cambios] (abre audit.partials.changes-modal) o lista de datos. Ver AuditLogPresenter. --}}
@php $changes = $p->changes(); $pairs = $p->contextPairs(); @endphp

@if($p->isStructured())
    <div>{{ $p->summary() }}</div>
    @if($changes)
        <button type="button" class="btn btn-sm btn-outline-primary mt-1" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">
            <i class="bi bi-list-columns-reverse me-1" aria-hidden="true"></i>Ver cambios
        </button>

    @endif
@elseif($pairs)
    <dl class="kv small mb-0">
        @foreach($pairs as $label => $value)
            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
        @endforeach
    </dl>
@else
    <span class="text-body-secondary">—</span>
@endif
