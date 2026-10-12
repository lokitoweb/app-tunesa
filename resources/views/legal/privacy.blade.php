@extends('layouts.guest')

@section('title', 'Política de privacidad')

@section('content')
<div class="auth-card" style="max-width: 760px;">
    @include('auth._brand')

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4 legal-text">
            <h1 class="h4 fw-semibold mb-1">Política de privacidad de {{ $appName }}</h1>
            <p class="small text-body-secondary">Última actualización: {{ $updatedAt }}</p>

            <p>{{ $appName }} es la app de asistencia y autoservicio que tu empleador te entrega para marcar tu
                entrada y salida, pedir vacaciones o permisos, ver tus boletas y mantener tu legajo al día. Tu
                empleador decide para qué usa tus datos (es el responsable del tratamiento) y la plataforma los
                procesa por encargo suyo. Esta política explica qué datos usa la app, para qué y cómo puedes
                ejercer tus derechos según la Ley N.° 29733 de Protección de Datos Personales del Perú.</p>

            <h2 class="h6 fw-semibold mt-4">1. Datos que usamos</h2>
            <ul>
                <li><strong>Identificación y empleo:</strong> nombre, correo, documento de identidad, código de colaborador, cargo, sede y turno. Los registra tu empresa al crear tu cuenta.</li>
                <li><strong>Ubicación precisa:</strong> solo cuando abres la pantalla de marcación y al registrar una marcación, para comprobar que estás en tu sede. La app <strong>no</strong> usa tu ubicación en segundo plano.</li>
                <li><strong>Fotos:</strong> la selfie de cada marcación (si tu empresa la exige), tu foto de perfil y las imágenes de documentos que tú adjuntas. Si tu empresa activa la detección de rostro, el teléfono solo comprueba que hay un rostro en la foto; no se crean ni guardan huellas faciales ni datos biométricos.</li>
                <li><strong>Documentos y solicitudes:</strong> lo que tú subes (DNI, certificados, sustentos y certificados médicos de licencias), tus datos de pago, contacto de emergencia y tallas de uniforme.</li>
                <li><strong>Asistencia y planilla:</strong> horas de marcación, tardanzas, justificaciones, boletas de pago y avance en capacitaciones.</li>
                <li><strong>Dispositivo:</strong> modelo, sistema operativo, versión de la app, un identificador de instalación, el token de notificaciones y señales de seguridad del teléfono (por ejemplo, si está rooteado o usa ubicación simulada), para proteger tu cuenta y evitar marcaciones falsas.</li>
            </ul>

            <h2 class="h6 fw-semibold mt-4">2. Para qué los usamos</h2>
            <ul>
                <li>Registrar y validar tu asistencia, y calcular tardanzas y horas trabajadas.</li>
                <li>Gestionar tus solicitudes, documentos, boletas, uniformes y capacitaciones.</li>
                <li>Enviarte avisos (solicitudes revisadas, boletas nuevas, recordatorios de turno, alertas de seguridad).</li>
                <li>Proteger tu cuenta y la de tu empresa frente a accesos y marcaciones indebidas.</li>
            </ul>
            <p>No vendemos tus datos, no mostramos publicidad y no los usamos para fines distintos a la relación laboral.</p>

            <h2 class="h6 fw-semibold mt-4">3. Con quién se comparten</h2>
            <ul>
                <li><strong>Tu empleador:</strong> RRHH, administradores y tu supervisor ven lo necesario para su función.</li>
                <li><strong>Google Firebase Cloud Messaging:</strong> entrega las notificaciones push (recibe el token del teléfono y el texto del aviso).</li>
                <li><strong>Servicio de direcciones (geocodificación):</strong> recibe las coordenadas de una marcación para mostrar la dirección legible.</li>
                <li><strong>Autoridades:</strong> solo cuando la ley lo exige.</li>
            </ul>

            <h2 class="h6 fw-semibold mt-4">4. Seguridad</h2>
            <p>Los datos viajan cifrados (HTTPS). En el teléfono, la sesión y las marcaciones pendientes sin conexión
                se guardan cifradas, no se incluyen en copias de seguridad y los documentos se abren desde la memoria
                con enlaces temporales. En el servidor, los archivos son privados y cada acceso queda registrado.</p>

            <h2 class="h6 fw-semibold mt-4">5. Cuánto tiempo se guardan</h2>
            <p>Mientras dure tu relación laboral y, después, solo el tiempo que la ley laboral y tributaria obliga a
                conservar planillas, boletas y registros de asistencia. El resto se elimina cuando lo pides o cuando
                deja de ser necesario.</p>

            <h2 class="h6 fw-semibold mt-4">6. Tus derechos y cómo eliminar tu cuenta</h2>
            <p>Puedes pedir acceso, rectificación, cancelación u oposición (derechos ARCO) sobre tus datos. Para
                eliminar tu cuenta y tus datos, entra a <strong>Mi perfil › Privacidad y datos › Eliminar mi cuenta</strong>
                en la app, o usa <a href="{{ route('legal.account-deletion') }}">esta página</a> sin iniciar sesión.
                Respondemos en un máximo de 30 días. Si no estás conforme, puedes acudir a la Autoridad Nacional de
                Protección de Datos Personales.</p>

            <h2 class="h6 fw-semibold mt-4">7. Permisos del teléfono</h2>
            <ul>
                <li><strong>Ubicación:</strong> solo mientras la app está abierta, para validar la marcación.</li>
                <li><strong>Cámara:</strong> para la selfie de marcación y para fotografiar documentos.</li>
                <li><strong>Notificaciones:</strong> para los avisos de tu empresa. Puedes apagarlos en Mi perfil › Notificaciones.</li>
            </ul>
            <p>Puedes negar o quitar cualquier permiso en los ajustes del teléfono; solo dejarán de funcionar las opciones que lo necesitan.</p>

            <h2 class="h6 fw-semibold mt-4">8. Menores de edad</h2>
            <p>La app es solo para colaboradores de las empresas que la usan; no está dirigida a menores de 18 años.</p>

            <h2 class="h6 fw-semibold mt-4">9. Cambios y contacto</h2>
            <p>Si cambiamos esta política, actualizaremos la fecha de arriba y te avisaremos en la app. Para cualquier consulta sobre privacidad:</p>
            @include('legal._contact')
        </div>
    </div>
</div>
@endsection
