@php
    [$label, $tone] = match ($status) {
        'present' => ['Puntual', 'success'],
        'late'    => ['Tardanza', 'warning'],
        'absent'  => ['Ausente', 'danger'],
        'excused' => ['Justificado', 'info'],
        'holiday' => ['Feriado', 'secondary'],
        'day_off' => ['Descanso', 'secondary'],
        default   => [ucfirst((string) $status), 'secondary'],
    };
@endphp
<span class="badge rounded-pill bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis border border-{{ $tone }}-subtle">{{ $label }}</span>
