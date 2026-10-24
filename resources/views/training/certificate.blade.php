<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Constancia de capacitación</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1f2937; }
    .frame { position: absolute; top: 24px; left: 24px; right: 24px; bottom: 24px; border: 6px solid {{ $color }}; padding: 48px 64px; }
    .company { font-size: 14px; letter-spacing: 3px; text-transform: uppercase; color: {{ $color }}; font-weight: bold; }
    h1 { font-size: 36px; margin: 28px 0 8px; }
    .lead { font-size: 15px; color: #4b5563; }
    .name { font-size: 30px; font-weight: bold; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 8px; }
    .course { font-size: 22px; font-weight: bold; color: {{ $color }}; margin: 14px 0; }
    .meta { margin-top: 36px; font-size: 13px; color: #4b5563; }
    .meta td { padding: 4px 24px 4px 0; }
    .code { position: absolute; bottom: 36px; right: 64px; font-size: 11px; color: #6b7280; }
    .logo { position: absolute; top: 40px; right: 64px; max-height: 70px; max-width: 220px; }
    .signature { position: absolute; bottom: 64px; left: 64px; width: 260px; text-align: center; font-size: 12px; color: #374151; }
    .signature img { max-height: 70px; max-width: 220px; }
    .signature .line { border-top: 1px solid #6b7280; margin-top: 4px; padding-top: 6px; }
</style>
</head>
<body>
<div class="frame">
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="{{ $company }}">
    @endif
    <div class="company">{{ $company }}</div>
    <h1>Constancia de capacitación</h1>
    <div class="lead">Se deja constancia de que</div>
    <div class="name">{{ $employee->full_name }}</div>
    <div class="lead">completó satisfactoriamente la capacitación</div>
    <div class="course">{{ $course->title }}</div>
    <table class="meta">
        <tr>
            <td><strong>Fecha:</strong> {{ $enrollment->completed_at?->timezone($employee->tenant?->timezone ?: config('app.timezone'))->format('d/m/Y') }}</td>
            @if ($employee->document_number ?? null)
                <td><strong>Documento:</strong> {{ $employee->document_number }}</td>
            @endif
            @if ($enrollment->score !== null)
                <td><strong>Nota:</strong> {{ $enrollment->score }}/100</td>
            @endif
            @if ($course->estimated_minutes)
                <td><strong>Duración:</strong> {{ $course->estimated_minutes }} min</td>
            @endif
        </tr>
    </table>
    <div class="signature">
        @if ($signer)
            <img src="{{ $signer['signature'] }}" alt="Firma">
            <div class="line"><strong>{{ $signer['name'] }}</strong><br>{{ $signer['position'] ?: 'Gerencia' }} · {{ $company }}</div>
        @else
            <div class="line" style="margin-top: 74px;">{{ $company }}</div>
        @endif
    </div>
    <div class="code">Código de verificación: {{ $code }}</div>
</div>
</body>
</html>
