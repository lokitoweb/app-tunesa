<?php

return [
    /*
    | Además de la notificación en la app y el panel (canal database), enviar
    | por correo las decisiones sobre solicitudes de vacaciones/permisos.
    | Requiere MAIL_MAILER configurado (smtp, ses, etc.).
    */
    'by_mail' => (bool) env('NOTIFY_BY_MAIL', false),
];
