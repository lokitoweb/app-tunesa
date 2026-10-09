{{-- Badge semántico: @include('partials.badge', ['tone' => 'success', 'label' => 'Aprobado']) --}}
<span class="badge rounded-pill bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis border border-{{ $tone }}-subtle">{{ $label }}</span>
