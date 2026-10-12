{{-- Credenciales recién generadas (alta o "Restablecer acceso"): se muestran una sola vez --}}
@if($credentials = session('credentials'))
    <div class="alert alert-warning" role="status">
        <i class="bi bi-key"></i>
        <div>
            <strong class="d-block mb-1">Entrega estas credenciales a {{ $credentials['name'] }}</strong>
            <dl class="kv mb-2">
                <dt>Correo</dt>
                <dd><code id="credEmail">{{ $credentials['email'] }}</code></dd>
                <dt>Contraseña temporal</dt>
                <dd><code id="credPassword">{{ $credentials['password'] }}</code></dd>
            </dl>
            <span class="small">Vence en {{ \App\Modules\Core\Services\CredentialProvisioner::TEMP_TTL_HOURS }} horas y deberá cambiarla al ingresar. No se volverá a mostrar.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="credCopy"><i class="bi bi-clipboard me-1"></i>Copiar</button>
        </div>
    </div>
    <script>
        document.getElementById('credCopy').addEventListener('click', function () {
            var text = 'Correo: ' + document.getElementById('credEmail').textContent + '\nContraseña temporal: ' + document.getElementById('credPassword').textContent;
            navigator.clipboard && navigator.clipboard.writeText(text).then(() => { this.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado'; });
        });
    </script>
@endif
