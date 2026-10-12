{{-- Contacto de la empresa (o de la plataforma) para temas de privacidad --}}
@if(! empty($contact))
    <ul class="list-unstyled small mb-0">
        @if(! empty($contact['email']))
            <li><i class="bi bi-envelope me-1"></i><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>
        @endif
        @if(! empty($contact['phone']))
            <li><i class="bi bi-telephone me-1"></i>{{ $contact['phone'] }}</li>
        @endif
        @if(! empty($contact['address']))
            <li><i class="bi bi-geo-alt me-1"></i>{{ $contact['address'] }}</li>
        @endif
    </ul>
@else
    <p class="small mb-0">Escribe al área de Recursos Humanos de tu empresa.</p>
@endif
