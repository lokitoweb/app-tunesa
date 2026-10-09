{{-- Modal con el cuadro de cambios (Campo | Valor previo | Valor nuevo) de un registro de la bitácora --}}
@php $changes = $p->changes(); $pairs = $p->contextPairs(); @endphp
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="{{ $modalId }}Title">{{ $p->actionLabel() }}</h2>
                    <div class="small text-body-secondary">{{ $p->actorName() }} · {{ $p->date() }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm audit-diff mb-0">
                        <thead>
                            <tr><th scope="col">Campo</th><th scope="col">Valor previo</th><th scope="col">Valor nuevo</th></tr>
                        </thead>
                        <tbody>
                            @foreach($changes as $c)
                                <tr>
                                    <th scope="row" class="fw-medium">{{ $c['label'] }}</th>
                                    <td class="changed-old">{{ $c['old'] }}</td>
                                    <td class="changed-new">{{ $c['new'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($pairs)
                    <dl class="kv small mt-3 mb-0">
                        @foreach($pairs as $label => $value)
                            <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>
    </div>
</div>
