@if($photos->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 mt-2">
        @foreach($photos as $photo)
            <a href="{{ route('ethics.photo', [$case->id, $photo->id]) }}" target="_blank" rel="noopener">
                <img src="{{ route('ethics.photo', [$case->id, $photo->id]) }}" alt="Foto adjunta" class="rounded border" style="width: 96px; height: 96px; object-fit: cover" loading="lazy">
            </a>
        @endforeach
    </div>
@endif
