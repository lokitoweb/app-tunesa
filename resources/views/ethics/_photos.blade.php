@if($photos->isNotEmpty())
    <div class="ethics-photo-grid mt-2">
        @foreach($photos as $photo)
            <div class="ethics-photo-card" 
                 onclick="previewEthicsPhoto('{{ route('ethics.photo', [$case->id, $photo->id]) }}', 'Foto adjunta — Caso #{{ $case->public_code }}')" 
                 title="Clic para ampliar foto ({{ number_format($photo->size / 1024, 1) }} KB)">
                <img src="{{ route('ethics.photo', [$case->id, $photo->id]) }}" alt="Foto adjunta" loading="lazy">
                <div class="photo-overlay">
                    <i class="bi bi-arrows-fullscreen"></i>
                </div>
            </div>
        @endforeach
    </div>
@endif
