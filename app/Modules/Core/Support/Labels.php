<?php

namespace App\Modules\Core\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Diccionario de etiquetas en español para TODO el panel: el usuario nunca
 * debe ver claves de base de datos, snake_case ni valores de enum en crudo.
 *
 *  Labels::action('tenant_settings.updated')       → "Marca y app actualizada"
 *  Labels::field('default_grace_minutes')          → "Tolerancia por defecto (min)"
 *  Labels::enum('leave_status', 'approved')        → "Aprobada"
 *  Labels::tone('leave_status', 'approved')        → "success" (color Bootstrap)
 *  Labels::value('lunch_paid', true)               → "Sí"
 *
 * En Blade: label('leave_status', $x->status) y <x-status-badge type="leave_status" :value="$x->status"/>.
 */
final class Labels
{
    /**
     * Acciones de la bitácora: [etiqueta, color Bootstrap, icono bootstrap-icons].
     * AuditActionsCoverageTest falla si alguna acción usada en app/ no está aquí.
     */
    public const ACTIONS = [
        'attendance.offshift_authorized'     => ['Marcación fuera de turno autorizada', 'primary', 'calendar-plus'],
        'attendance.offshift_revoked'        => ['Autorización fuera de turno retirada', 'secondary', 'calendar-x'],
        // Acceso
        'auth.login'                         => ['Inicio de sesión', 'secondary', 'box-arrow-in-right'],
        'auth.logout'                        => ['Cierre de sesión', 'secondary', 'box-arrow-right'],
        'auth.login_failed'                  => ['Intento de ingreso fallido', 'warning', 'shield-exclamation'],
        'auth.account_locked'                => ['Cuenta bloqueada por intentos fallidos', 'danger', 'lock'],
        'auth.account_unlocked'              => ['Cuenta desbloqueada', 'success', 'unlock'],
        'auth.access_reset'                  => ['Acceso restablecido por RRHH', 'warning', 'key'],
        'auth.password_reset'                => ['Contraseña restablecida', 'info', 'key'],
        'auth.password_changed'              => ['Contraseña cambiada', 'info', 'key'],

        // Configuración
        'company_configuration.updated'      => ['Configuración actualizada', 'primary', 'sliders'],
        'tenant_settings.updated'            => ['Marca y app actualizada', 'primary', 'palette'],
        'integration.webhook_failed'         => ['Fallo al enviar a la integración', 'danger', 'plug'],
        'integration.webhook_tested'         => ['Prueba de integración enviada', 'info', 'plug'],
        'integration.webhook_secret_regenerated' => ['Secreto del webhook regenerado', 'warning', 'key'],
        'push.tested'                        => ['Notificación push de prueba', 'info', 'phone-vibrate'],

        // Empresas (Super Admin)
        'tenant.created'                     => ['Empresa creada', 'success', 'building-add'],
        'tenant.updated'                     => ['Empresa actualizada', 'primary', 'building-gear'],
        'tenant.suspended'                   => ['Empresa suspendida', 'warning', 'building-slash'],
        'tenant.activated'                   => ['Empresa reactivada', 'success', 'building-check'],
        'tenant.deleted'                     => ['Empresa dada de baja', 'danger', 'building-x'],
        'tenant.document_code_updated'       => ['Abreviatura de correlativos cambiada', 'primary', 'hash'],
        'tenant.flag_changed'                => ['Bandera de asistencia cambiada', 'warning', 'flag'],

        // Sucursales y turnos
        'branch.created'                     => ['Sucursal creada', 'success', 'geo-alt'],
        'branch.updated'                     => ['Sucursal actualizada', 'primary', 'geo-alt'],
        'branch.deleted'                     => ['Sucursal eliminada', 'danger', 'geo-alt'],
        // Jerarquía organizacional
        'zone.created'                       => ['Zona creada', 'success', 'diagram-3'],
        'zone.updated'                       => ['Zona actualizada', 'primary', 'diagram-3'],
        'zone.deleted'                       => ['Zona eliminada', 'danger', 'diagram-3'],
        'department.created'                 => ['Área creada', 'success', 'diagram-2'],
        'department.updated'                 => ['Área actualizada', 'primary', 'diagram-2'],
        'department.deleted'                 => ['Área eliminada', 'danger', 'diagram-2'],
        'position.created'                   => ['Cargo creado', 'success', 'person-badge'],
        'position.updated'                   => ['Cargo actualizado', 'primary', 'person-badge'],
        'position.deleted'                   => ['Cargo eliminado', 'danger', 'person-badge'],
        'branch.staffing_updated'            => ['Plantilla de sede actualizada', 'primary', 'people'],
        'shift.created'                      => ['Turno creado', 'success', 'calendar-week'],
        'shift.updated'                      => ['Turno actualizado', 'primary', 'calendar-week'],
        'shift.deleted'                      => ['Turno eliminado', 'danger', 'calendar-week'],
        'announcement.created'               => ['Comunicado creado', 'success', 'megaphone'],
        'announcement.updated'               => ['Comunicado actualizado', 'primary', 'megaphone'],
        'announcement.scheduled'             => ['Comunicado programado', 'primary', 'clock'],
        'announcement.sent'                  => ['Comunicado enviado', 'success', 'send'],
        'announcement.deleted'               => ['Comunicado eliminado', 'danger', 'megaphone'],
        'benefit.created'                    => ['Beneficio creado', 'success', 'gift'],
        'benefit.updated'                    => ['Beneficio actualizado', 'primary', 'gift'],
        'benefit.published'                  => ['Beneficio publicado', 'success', 'gift'],
        'benefit.archived'                   => ['Beneficio archivado', 'secondary', 'gift'],
        'benefit.draft'                      => ['Beneficio pasado a borrador', 'secondary', 'gift'],
        'benefit.deleted'                    => ['Beneficio eliminado', 'danger', 'gift'],
        'benefit_category.created'           => ['Categoría de beneficios creada', 'success', 'tags'],
        'benefit_category.updated'           => ['Categoría de beneficios actualizada', 'primary', 'tags'],
        'benefit_category.deleted'           => ['Categoría de beneficios eliminada', 'danger', 'tags'],
        'supplier.created'                   => ['Proveedor registrado', 'success', 'truck'],
        'supplier.updated'                   => ['Proveedor actualizado', 'primary', 'truck'],
        'supplier.deleted'                   => ['Proveedor eliminado', 'danger', 'truck'],
        'requisition.created'                => ['Requerimiento registrado', 'success', 'cart'],
        'requisition.updated'                => ['Requerimiento actualizado', 'primary', 'cart'],
        'requisition.approved'               => ['Requerimiento aprobado', 'success', 'cart-check'],
        'requisition.rejected'               => ['Requerimiento rechazado', 'danger', 'cart-x'],
        'requisition.cancelled'              => ['Requerimiento anulado', 'secondary', 'cart-x'],
        'requisition.ordered'                => ['Orden de compra/servicio emitida', 'success', 'receipt'],
        'quotation.created'                  => ['Cotización agregada', 'primary', 'file-earmark-text'],
        'quotation.deleted'                  => ['Cotización eliminada', 'danger', 'file-earmark-text'],
        'requisition.detraction'             => ['Detracción registrada', 'primary', 'percent'],
        'installment.scheduled'              => ['Cuota programada', 'primary', 'calendar-check'],
        'installment.authorized'             => ['Pago autorizado', 'success', 'check2-circle'],
        'installment.paid'                   => ['Pago registrado', 'success', 'cash-coin'],
        'petty_cash.created'                 => ['Gasto de caja chica registrado', 'success', 'wallet2'],
        'petty_cash.imported'                => ['Caja chica importada desde Excel', 'success', 'file-earmark-spreadsheet'],
        'vehicle.created'                    => ['Vehículo registrado', 'success', 'bus-front'],
        'vehicle.updated'                    => ['Vehículo actualizado', 'primary', 'bus-front'],
        'vehicle.deleted'                    => ['Vehículo eliminado', 'danger', 'bus-front'],
        'signatory.created'                  => ['Apoderado designado', 'success', 'pen'],
        'signatory.updated'                  => ['Apoderado actualizado', 'primary', 'pen'],
        'stock.in'                           => ['Entrada de almacén', 'success', 'box-arrow-in-down'],
        'stock.out'                          => ['Salida de almacén', 'warning', 'box-arrow-up'],
        'requisition.received'               => ['Orden recibida en almacén', 'success', 'box-seam'],
        'requisition.invoiced'               => ['Factura registrada', 'primary', 'receipt'],
        'payroll.imported'                   => ['Planilla enviada a Tesorería', 'success', 'people'],
        'fuel.created'                       => ['Carga de combustible registrada', 'success', 'fuel-pump'],
        'fuel.updated'                       => ['Carga de combustible actualizada', 'primary', 'fuel-pump'],
        'fuel.deleted'                       => ['Carga de combustible eliminada', 'danger', 'fuel-pump'],
        'petty_cash.updated'                 => ['Gasto de caja chica actualizado', 'primary', 'wallet2'],
        'petty_cash.deleted'                 => ['Gasto de caja chica eliminado', 'danger', 'wallet2'],
        'fixed_cost.created'                 => ['Costo fijo registrado', 'success', 'calendar2-range'],
        'fixed_cost.updated'                 => ['Cuota de costo fijo actualizada', 'primary', 'calendar2-range'],
        'fixed_cost.cancelled'               => ['Cuota de costo fijo anulada', 'secondary', 'calendar2-x'],
        'fixed_cost.paid'                    => ['Cuota de costo fijo pagada', 'success', 'cash-coin'],
        'company_document.created'           => ['Documento de la empresa subido', 'success', 'folder2-open'],
        'company_document.updated'           => ['Documento de la empresa actualizado', 'primary', 'folder2-open'],
        'company_document.published'         => ['Documento de la empresa publicado', 'success', 'folder2-open'],
        'company_document.archived'          => ['Documento de la empresa archivado', 'secondary', 'folder2-open'],
        'company_document.draft'             => ['Documento de la empresa pasado a borrador', 'secondary', 'folder2-open'],
        'company_document.deleted'           => ['Documento de la empresa eliminado', 'danger', 'folder2-open'],
        'company_document_folder.created'    => ['Carpeta de documentos creada', 'success', 'folder-plus'],
        'company_document_folder.updated'    => ['Carpeta de documentos actualizada', 'primary', 'folder'],
        'company_document_folder.deleted'    => ['Carpeta de documentos eliminada', 'danger', 'folder-x'],
        'birthdays.settings_updated'         => ['Ajustes de cumpleaños actualizados', 'primary', 'balloon-heart'],
        'training.created'                   => ['Capacitación creada', 'success', 'mortarboard'],
        'training.updated'                   => ['Capacitación actualizada', 'primary', 'mortarboard'],
        'training.published'                 => ['Capacitación publicada', 'success', 'mortarboard'],
        'training.archived'                  => ['Capacitación archivada', 'secondary', 'mortarboard'],
        'training.deleted'                   => ['Capacitación eliminada', 'danger', 'mortarboard'],
        'training.locked'                    => ['Cuestionario bloqueado por intentos', 'warning', 'lock'],
        'training.unlocked'                  => ['Intentos extra en cuestionario', 'primary', 'unlock'],
        'holiday.created'                    => ['Feriado creado', 'success', 'calendar-heart'],
        'holiday.deleted'                    => ['Feriado eliminado', 'danger', 'calendar-x'],

        // Colaboradores
        'employee.created'                   => ['Colaborador registrado', 'success', 'person-plus'],
        'employee.updated'                   => ['Colaborador actualizado', 'primary', 'person-gear'],
        'employee.deleted'                   => ['Colaborador dado de baja', 'danger', 'person-x'],
        'profile.updated_by_hr'              => ['Ficha laboral editada por RRHH', 'primary', 'person-vcard'],
        'payment_info.revealed'              => ['Cuenta bancaria revelada', 'warning', 'eye'],
        'payment_info.verified'              => ['Cuenta bancaria verificada', 'success', 'bank'],
        'payment_info.rejected'              => ['Cuenta bancaria observada', 'danger', 'bank'],
        'payment_info.updated_by_employee'   => ['Datos de pago editados por el colaborador', 'info', 'bank'],

        // Legajo
        'document.uploaded'                  => ['Documento subido', 'info', 'file-earmark-arrow-up'],
        'document.viewed'                    => ['Documento abierto', 'secondary', 'file-earmark-text'],
        'document.approved'                  => ['Documento aprobado', 'success', 'file-earmark-check'],
        'document.rejected'                  => ['Documento rechazado', 'danger', 'file-earmark-x'],
        'document.deleted'                   => ['Documento eliminado', 'danger', 'file-earmark-minus'],

        // Asistencia
        'attendance.clock_in'                => ['Marcación de entrada', 'success', 'box-arrow-in-right'],
        'attendance.clock_out'               => ['Marcación de salida', 'secondary', 'box-arrow-right'],
        'attendance.break_start'             => ['Inicio de descanso', 'secondary', 'cup-hot'],
        'attendance.break_end'               => ['Fin de descanso', 'secondary', 'cup-hot'],
        'attendance.offline_synced'          => ['Marcación sin conexión sincronizada', 'info', 'cloud-arrow-up'],
        'attendance.clock_in_out_of_geofence'  => ['Entrada fuera de la geocerca', 'warning', 'geo'],
        'attendance.clock_out_out_of_geofence' => ['Salida fuera de la geocerca', 'warning', 'geo'],
        'attendance.break_start_out_of_geofence' => ['Inicio de descanso fuera de la geocerca', 'warning', 'geo'],
        'attendance.break_end_out_of_geofence'   => ['Fin de descanso fuera de la geocerca', 'warning', 'geo'],
        'attendance.mock_location_detected'  => ['Ubicación simulada detectada', 'danger', 'phone-vibrate'],
        'attendance.time_drift_detected'     => ['Hora del celular desfasada', 'warning', 'clock'],
        'attendance.reviewed'                => ['Marcación revisada', 'primary', 'clipboard-check'],
        'attendance.photo_viewed'            => ['Selfie de marcación consultada', 'secondary', 'eye'],
        'attendance.photos_purged'           => ['Selfies borradas por retención', 'secondary', 'trash'],
        'attendance.overtime_approved'       => ['Horas extra aprobadas', 'success', 'clock-history'],
        'attendance.overtime_rejected'       => ['Horas extra rechazadas', 'danger', 'clock-history'],
        'attendance.manual_edit'             => ['Marcación corregida a mano', 'warning', 'pencil-square'],
        'attendance.evidence_altered'        => ['Evidencia alterada (la selfie no coincide con su huella)', 'danger', 'shield-exclamation'],
        'justification.approved'             => ['Justificación aprobada', 'success', 'clipboard-check'],
        'justification.rejected'             => ['Justificación rechazada', 'danger', 'clipboard-x'],

        // Permisos y vacaciones
        'leave_request.submitted'            => ['Permiso solicitado', 'info', 'calendar2-plus'],
        'leave_request.approved'             => ['Permiso aprobado', 'success', 'calendar2-check'],
        'leave_request.rejected'             => ['Permiso rechazado', 'danger', 'calendar2-x'],
        'leave_request.cancelled'            => ['Permiso cancelado', 'secondary', 'calendar2-minus'],

        // Boletas y planilla
        'payslip.created'                    => ['Boleta cargada', 'info', 'file-earmark-plus'],
        'payslip.published'                  => ['Boleta publicada', 'success', 'file-earmark-text'],
        'payslip.deleted'                    => ['Boleta eliminada', 'danger', 'file-earmark-x'],
        'payslip.archived'                   => ['Boleta anulada', 'warning', 'file-earmark-lock'],
        'payroll.exported'                   => ['Planilla exportada', 'info', 'file-earmark-spreadsheet'],

        // Incorporación
        'onboarding.submitted'               => ['Incorporación enviada a revisión', 'info', 'send'],
        'onboarding.approved'                => ['Incorporación aprobada', 'success', 'person-check'],
        'onboarding.rejected'                => ['Incorporación observada', 'danger', 'person-exclamation'],
        'onboarding.stages_updated'          => ['Etapas de incorporación actualizadas', 'primary', 'list-check'],

        // Canal ético (solo acciones de quien investiga: el denunciante no deja rastro)
        'ethics.viewed'                      => ['Denuncia consultada', 'secondary', 'eye'],
        'ethics.replied'                     => ['Respuesta al denunciante', 'info', 'chat-left-text'],
        'ethics.status_changed'              => ['Estado de denuncia cambiado', 'primary', 'shield-check'],
        'ethics.identity_viewed'             => ['Identidad del denunciante consultada', 'warning', 'person-lock'],
        'ethics.assigned'                    => ['Investigador asignado', 'info', 'person-badge'],

        // Dotación
        'uniform.delivered'                  => ['Uniforme entregado', 'info', 'bag-check'],
    ];

    /** Área de la acción (para el resumen "cambió 3 campos de Marca y app") */
    public const AREAS = [
        'company_configuration' => 'Configuración de la app',
        'tenant_settings'       => 'Marca y app',
        'tenant'                => 'la empresa',
        'branch'                => 'la sucursal',
        'shift'                 => 'el turno',
        'holiday'               => 'el feriado',
        'employee'              => 'el colaborador',
        'profile'               => 'la ficha laboral',
        'payment_info'          => 'los datos de pago',
        'document'              => 'el documento',
        'attendance'            => 'la marcación',
        'leave_request'         => 'la solicitud',
        'payslip'               => 'la boleta',
        'onboarding'            => 'la incorporación',
        'ethics'                => 'la denuncia',
    ];

    /** Nombre de los registros (subject_type de la bitácora) */
    public const SUBJECTS = [
        'Attendance'           => 'Marcación',
        'AuditLog'             => 'Registro de auditoría',
        'Branch'               => 'Sucursal',
        'Zone'                 => 'Zona',
        'Department'           => 'Área',
        'CompanyConfiguration' => 'Configuración de la app',
        'EmployeeDocument'     => 'Documento',
        'EthicsCase'           => 'Denuncia',
        'EmployeeProfile'      => 'Ficha laboral',
        'EmployeeShift'        => 'Asignación de turno',
        'Holiday'              => 'Feriado',
        'LeaveRequest'         => 'Solicitud',
        'Payslip'              => 'Boleta',
        'Role'                 => 'Rol',
        'Shift'                => 'Turno',
        'Tenant'               => 'Empresa',
        'TenantSetting'        => 'Marca y app',
        'UniformDelivery'      => 'Entrega de uniforme',
        'UniformSize'          => 'Tallas',
        'User'                 => 'Colaborador',
    ];

    /**
     * Columnas → etiqueta. Cubre tenant_settings, company_configurations,
     * branches, users, employee_profiles, shifts, tenants, leave_requests,
     * payslips, attendances y los ajustes nuevos (FieldLabelsCoverageTest).
     */
    public const FIELDS = [
        // Comunes
        'name'                    => 'Nombre',
        'code'                    => 'Código',
        'document_series'         => 'Serie de documentos',
        'address'                 => 'Dirección',
        'notes'                   => 'Notas',
        'description'             => 'Descripción',
        'status'                  => 'Estado',
        'type'                    => 'Tipo',
        'is_active'               => 'Activo',
        'timezone'                => 'Zona horaria',
        'currency'                => 'Moneda',
        'email'                   => 'Correo',
        'phone'                   => 'Teléfono',
        'title'                   => 'Título',
        'reason'                  => 'Motivo',
        'date'                    => 'Fecha',
        'user_id'                 => 'Colaborador',
        'branch_id'               => 'Sucursal',
        'shift_id'                => 'Turno',
        'reviewed_by'             => 'Revisado por',
        'reviewed_at'             => 'Fecha de revisión',
        'uploaded_by'             => 'Subido por',
        'updated_by'              => 'Actualizado por',
        'created_at'              => 'Fecha de creación',
        'updated_at'              => 'Última actualización',
        'deleted_at'              => 'Fecha de baja',
        'tenant_id'               => 'Empresa',
        'id'                      => 'Número',

        // Marca y app (tenant_settings)
        'trade_name'              => 'Nombre comercial',
        'logo_path'               => 'Logo',
        'logo_dark_path'          => 'Logo para fondo oscuro',
        'favicon_path'            => 'Ícono del navegador',
        'splash_path'             => 'Pantalla de inicio de la app',
        'contact_email'           => 'Correo de contacto',
        'contact_phone'           => 'Teléfono de contacto',
        'website'                 => 'Sitio web',
        'login_email_domain'      => 'Dominio del correo de acceso',
        'primary_color'           => 'Color primario',
        'secondary_color'         => 'Color secundario',
        'accent_color'            => 'Color de acento',
        'navbar_color'            => 'Color de la barra de navegación',
        'api_base_url'            => 'Dirección del servidor para la app',
        'min_supported_version'   => 'Versión mínima de la app',
        'latest_version'          => 'Última versión de la app',
        'force_update'            => 'Obligar a actualizar la app',
        'update_url'              => 'Enlace para descargar la app',
        'selfie_required'         => 'Selfie obligatoria al marcar',
        'face_detection_required' => 'Detección de rostro obligatoria',
        'offline_mode_enabled'    => 'Marcación sin conexión',
        'self_password_change'    => 'Cambio de contraseña desde la app',
        'offline_max_hours'       => 'Máximo sin conexión (h)',
        'max_gps_accuracy_meters' => 'Precisión GPS máxima (m)',
        'default_grace_minutes'   => 'Tolerancia por defecto (min)',
        'lunch_minutes'           => 'Duración del almuerzo (min)',
        'lunch_paid'              => 'Almuerzo pagado',
        'punch_requires_schedule' => 'Marcar solo con turno programado',
        'default_geofence_radius' => 'Radio de geocerca por defecto (m)',
        'webhook_url'             => 'Dirección del webhook',
        'webhook_secret'          => 'Clave secreta del webhook',
        'maps_api_key'            => 'Clave de Google Maps',
        'notification_endpoint'   => 'Dirección para notificaciones',
        'mock_location_policy'    => 'Ubicación simulada (GPS falso)',
        'max_time_drift_seconds'  => 'Desfase máximo de la hora del celular (s)',
        'geocoding_provider'      => 'Proveedor de mapas y direcciones',
        'geocoding_api_key'       => 'Clave del proveedor de mapas',
        'fcm_enabled'             => 'Notificaciones push',
        'fcm_service_account_json' => 'Credencial de notificaciones push',
        'migo_api_token'          => 'Token de Migo',
        'migo_ruc_url'            => 'URL de consulta RUC',
        'migo_dni_url'            => 'URL de consulta DNI',
        'nubefact_url'            => 'Ruta de Nubefact',
        'nubefact_token'          => 'Token de Nubefact',
        'smtp_host'               => 'Servidor SMTP',
        'smtp_port'               => 'Puerto SMTP',
        'smtp_encryption'         => 'Cifrado SMTP',
        'smtp_username'           => 'Usuario SMTP',
        'smtp_password'           => 'Contraseña SMTP',
        'mail_from_address'       => 'Correo remitente',
        'mail_from_name'          => 'Nombre del remitente',
        'fcm_project_id'          => 'Proyecto de notificaciones push',
        'company_name'            => 'Razón social',

        // Configuración de la app (company_configurations)
        'mobile_payslips_enabled'     => 'App: boletas de pago',
        'mobile_documents_enabled'    => 'App: mi legajo',
        'mobile_documents_upload'     => 'App: subir documentos',
        'mobile_payment_info_enabled' => 'App: datos de pago y previsión',
        'mobile_payment_info_edit'    => 'App: editar cuenta bancaria',
        'mobile_uniforms_enabled'     => 'App: dotación',
        'mobile_uniforms_edit'        => 'App: editar tallas',
        'lock_verified_payment_info'  => 'Bloquear datos verificados',
        'required_documents'          => 'Documentos obligatorios',
        'max_upload_mb'               => 'Tamaño máximo de archivo (MB)',
        'geofence_policy'             => 'Política de geocerca',

        // Sucursales
        'city'                    => 'Ciudad',
        'country'                 => 'País',
        'latitude'                => 'Latitud',
        'longitude'               => 'Longitud',
        'allowed_radius_meters'   => 'Radio de la geocerca (m)',
        'geofence_tolerance_meters' => 'Tolerancia de borde (m)',
        'supervisor_id'           => 'Supervisor',
        'zone_id'                 => 'Zona',
        'branch_type'             => 'Tipo de sede',
        'department_id'           => 'Área',
        'position_id'             => 'Cargo',
        'is_roaming'              => 'Cargo itinerante',
        'planned_headcount'       => 'Plazas previstas',
        'scope_type'              => 'Alcance',
        'scope_zone_id'           => 'Zona asignada',
        'scope_branch_id'         => 'Sede asignada',
        'roaming_attendance_enabled' => 'Marcación itinerante',

        // Colaboradores (users)
        'first_name'              => 'Nombres',
        'last_name'               => 'Apellidos',
        'email_verified_at'       => 'Correo verificado el',
        'password'                => 'Contraseña',
        'document_number'         => 'Número de documento',
        'document_type'           => 'Tipo de documento',
        'avatar_path'             => 'Foto de perfil',
        'birth_date'              => 'Fecha de nacimiento',
        'birth_md'                => 'Cumpleaños (mes y día)',
        'gender'                  => 'Género',
        'role'                    => 'Rol',
        'employee_code'           => 'Código de colaborador',
        'hire_date'               => 'Fecha de ingreso',
        'termination_date'        => 'Fecha de cese',
        'contract_type'           => 'Tipo de contrato',
        'base_salary'             => 'Sueldo base',
        'position'                => 'Cargo',
        'department'              => 'Área',
        'remember_token'          => 'Sesión recordada',
        'two_factor_secret'       => 'Verificación en dos pasos',
        'two_factor_recovery_codes' => 'Códigos de recuperación',
        'fcm_token'               => 'Dispositivo para notificaciones',
        'fcm_token_updated_at'    => 'Dispositivo registrado el',
        'notification_preferences' => 'Preferencias de notificaciones',
        'failed_login_attempts'   => 'Intentos fallidos de ingreso',
        'onboarding_status'       => 'Estado de incorporación',
        'locked_until'            => 'Bloqueado hasta',
        'last_login_at'           => 'Último ingreso',
        'last_login_ip'           => 'Dirección del último ingreso',
        'must_change_password'    => 'Debe cambiar su contraseña',
        'temp_password_expires_at' => 'Vence la contraseña temporal',

        // Ficha laboral (employee_profiles)
        'district'                => 'Distrito',
        'emergency_contact_name'  => 'Contacto de emergencia',
        'emergency_contact_phone' => 'Teléfono de emergencia',
        'bank_name'               => 'Banco',
        'bank_account_type'       => 'Tipo de cuenta',
        'bank_account_currency'   => 'Moneda de la cuenta',
        'bank_account_number'     => 'Número de cuenta',
        'interbank_code'          => 'Código interbancario (CCI)',
        'pension_system'          => 'Sistema de pensiones',
        'pension_fund'            => 'AFP',
        'pension_code'            => 'Código de afiliado (CUSPP)',
        'health_insurance'        => 'Seguro de salud',
        'labor_regime'            => 'Régimen laboral',
        'cts_bank_name'           => 'Banco CTS',
        'cts_account_number'      => 'Cuenta CTS',
        'cts_currency'            => 'Moneda CTS',
        'payment_info_status'     => 'Estado de los datos de pago',
        'payment_info_updated_at' => 'Datos de pago actualizados el',
        'payment_verified_by'     => 'Verificado por',
        'payment_verified_at'     => 'Verificado el',
        'payment_rejection_reason' => 'Motivo de la observación',

        // Turnos
        'profile'                 => 'Perfil del turno',
        'start_time'              => 'Hora de inicio',
        'end_time'                => 'Hora de fin',
        'crosses_midnight'        => 'Pasa la medianoche',
        'grace_period_minutes'    => 'Tolerancia (min)',
        'break_allowed'           => 'Con descanso',
        'break_duration_minutes'  => 'Duración del descanso (min)',
        'working_days'            => 'Días laborables',
        'day_schedules'           => 'Horario por día',
        'daily_hours'             => 'Horas por día',
        'color'                   => 'Color',
        'rotation_work_days'      => 'Días de trabajo del ciclo',
        'rotation_rest_days'      => 'Días de descanso del ciclo',
        'rotation_anchor_date'    => 'Inicio de ciclo',
        'effective_from'          => 'Vigente desde',
        'effective_until'         => 'Vigente hasta',
        'assigned_by'             => 'Asignado por',

        // Empresas (tenants)
        'slug'                    => 'Subdominio',
        'ruc'                     => 'RUC',
        'plan'                    => 'Plan',
        'trial_ends_at'           => 'Fin de la prueba',
        'subscription_ends_at'    => 'Fin de la suscripción',
        'custom_domain'           => 'Dominio propio',
        'settings'                => 'Preferencias',

        // Solicitudes
        'start_date'              => 'Desde',
        'end_date'                => 'Hasta',
        'days'                    => 'Días',
        'attachment_path'         => 'Adjunto',
        'review_notes'            => 'Comentario de la revisión',

        // Boletas
        'period_month'            => 'Mes',
        'period_year'             => 'Año',
        'file_path'               => 'Archivo',
        'file_name'               => 'Nombre del archivo',
        'file_size'               => 'Tamaño del archivo',
        'mime_type'               => 'Formato del archivo',
        'gross_salary'            => 'Total bruto',
        'deductions'              => 'Descuentos',
        'net_salary'              => 'Neto a pagar',
        'breakdown'               => 'Detalle de conceptos',
        'published_at'            => 'Publicada el',
        'read_at'                 => 'Leída el',

        // Marcaciones
        'work_date'               => 'Día',
        'clock_in_at'             => 'Hora de entrada',
        'clock_in_latitude'       => 'Latitud de entrada',
        'clock_in_longitude'      => 'Longitud de entrada',
        'clock_in_distance_meters' => 'Distancia a la sucursal en la entrada (m)',
        'clock_in_photo'          => 'Selfie de entrada',
        'clock_in_device_id'      => 'Celular de la entrada',
        'clock_in_meta'           => 'Datos de la entrada',
        'break_start_at'          => 'Inicio del descanso',
        'break_start_latitude'    => 'Latitud al iniciar el descanso',
        'break_start_longitude'   => 'Longitud al iniciar el descanso',
        'break_end_at'            => 'Fin del descanso',
        'break_end_latitude'      => 'Latitud al terminar el descanso',
        'break_end_longitude'     => 'Longitud al terminar el descanso',
        'clock_out_at'            => 'Hora de salida',
        'clock_out_latitude'      => 'Latitud de salida',
        'clock_out_longitude'     => 'Longitud de salida',
        'clock_out_distance_meters' => 'Distancia a la sucursal en la salida (m)',
        'clock_out_photo'         => 'Selfie de salida',
        'clock_out_device_id'     => 'Celular de la salida',
        'clock_out_meta'          => 'Datos de la salida',
        'late_minutes'            => 'Tardanza (min)',
        'break_minutes'           => 'Descanso (min)',
        'worked_hours'            => 'Horas trabajadas',
        'overtime_hours'          => 'Horas extra',
        'overtime_status'         => 'Aprobación de horas extra',
        'overtime_approved_hours' => 'Horas extra aprobadas',
        'overtime_reviewed_by'    => 'Horas extra revisadas por',
        'overtime_reviewed_at'    => 'Fecha de revisión de horas extra',
        'overtime_review_notes'   => 'Comentario sobre las horas extra',
        'manual_edited_by'        => 'Corregida a mano por',
        'manual_edited_at'        => 'Fecha de la corrección manual',
        'manual_edit_reason'      => 'Motivo de la corrección',
        'calculated_hours'        => 'Horas extra calculadas',
        'approved_hours'          => 'Horas extra aprobadas',
        'clock_in_within_geofence'  => 'Entrada dentro de la geocerca',
        'clock_out_within_geofence' => 'Salida dentro de la geocerca',
        'clock_in_accuracy_meters'  => 'Precisión GPS de la entrada (m)',
        'clock_out_accuracy_meters' => 'Precisión GPS de la salida (m)',
        'clock_in_device_time'      => 'Hora del celular en la entrada',
        'clock_out_device_time'     => 'Hora del celular en la salida',
        'clock_in_time_drift_seconds'  => 'Desfase de hora en la entrada (s)',
        'clock_out_time_drift_seconds' => 'Desfase de hora en la salida (s)',
        'clock_in_mock_location'    => 'Ubicación simulada en la entrada',
        'clock_out_mock_location'   => 'Ubicación simulada en la salida',
        'clock_in_face_detected'    => 'Rostro detectado en la entrada',
        'clock_out_face_detected'   => 'Rostro detectado en la salida',
        'is_suspicious'             => 'Marcación sospechosa',
        'justification_file'      => 'Archivo de justificación',
        'justification_status'    => 'Estado de la justificación',

        // Documentos y dotación
        'original_name'           => 'Nombre del archivo',
        'size_bytes'              => 'Tamaño',
        'sha256'                  => 'Huella del archivo',
        'rejection_reason'        => 'Motivo del rechazo',
        'expires_at'              => 'Vence el',
        'item'                    => 'Prenda',
        'size'                    => 'Talla',
        'quantity'                => 'Cantidad',
        'delivered_at'            => 'Entregado el',
        'delivered_by'            => 'Entregado por',
        'shirt_size'              => 'Talla de polo',
        'pants_size'              => 'Talla de pantalón',
        'shoe_size'               => 'Talla de calzado',
        'shoe_type'               => 'Tipo de calzado',

        // Contexto de la bitácora (filas sin cambios estructurados)
        'scope'                   => 'Ámbito',
        'changed'                 => 'Campos cambiados',
        'secrets_changed'         => 'Claves cambiadas',
        'fields'                  => 'Campos',
        'period'                  => 'Periodo',
        'rows'                    => 'Filas',
        'format'                  => 'Formato',
        'moment'                  => 'Momento',
        'recorded_at'             => 'Marcado en el celular',
        'synced_at'               => 'Recibido en el servidor',
        'within_geofence'         => 'Dentro de la geocerca',
        'distance_meters'         => 'Distancia (m)',
        'allowed_radius'          => 'Radio permitido (m)',
        'excess_meters'           => 'Exceso (m)',
        'branch_name'             => 'Sucursal',
        'message'                 => 'Mensaje',
        'admin_email'             => 'Correo del administrador',
        'ip'                      => 'Dirección IP',
        'decision'                => 'Decisión',
        'employee'                => 'Colaborador',
        'employee_id'             => 'Colaborador',
        'punch_id'                => 'Registro de la bitácora de marcaciones',
        'count'                   => 'Cantidad',
        'sent'                    => 'Enviado',
        'ok'                      => 'Enviado',
        'http_status'             => 'Respuesta HTTP',
        'response_ms'             => 'Tiempo de respuesta (ms)',
        'ms'                      => 'Tiempo de respuesta (ms)',
        'error'                   => 'Error',
        'total'                   => 'Total',
    ];

    /** Columnas cuyo valor es un enum: field → conjunto de ENUMS */
    public const FIELD_ENUMS = [
        'geofence_policy'      => 'geofence_policy',
        'scope_type'           => 'scope_type',
        'branch_type'          => 'branch_type',
        'mock_location_policy' => 'mock_location_policy',
        'geocoding_provider'   => 'geocoding_provider',
        'role'                 => 'user_role',
        'document_type'        => 'id_document_type',
        'gender'               => 'gender',
        'contract_type'        => 'contract_type',
        'plan'                 => 'tenant_plan',
        'bank_account_type'    => 'bank_account_type',
        'pension_system'       => 'pension_system',
        'health_insurance'     => 'health_insurance',
        'labor_regime'         => 'labor_regime',
        'payment_info_status'  => 'payment_info_status',
        'justification_status' => 'justification_status',
        'shoe_type'            => 'shoe_type',
        'item'                 => 'uniform_item',
        'scope'                => 'audit_scope',
        'moment'               => 'attendance_moment',
        'decision'             => 'payment_info_status',
        'format'               => 'export_format',
        'period_month'         => 'month',
    ];

    /** `status` y `type` dependen del registro (subject_type) */
    public const SUBJECT_ENUMS = [
        'Attendance'       => ['status' => 'attendance_status'],
        'LeaveRequest'     => ['status' => 'leave_status', 'type' => 'leave_type'],
        'EmployeeDocument' => ['status' => 'document_status', 'type' => 'document_type'],
        'Payslip'          => ['status' => 'payslip_status'],
        'User'             => ['status' => 'user_status'],
        'Tenant'           => ['status' => 'tenant_status'],
        'Shift'            => ['type' => 'shift_type'],
    ];

    /**
     * Valores de enum → [etiqueta, color Bootstrap].
     */
    public const ENUMS = [
        'attendance_status' => [
            'present'    => ['Puntual', 'success'],
            'late'       => ['Tardanza', 'warning'],
            'absent'     => ['Ausente', 'danger'],
            'excused'    => ['Justificado', 'info'],
            'half_day'   => ['Medio día', 'info'],
            'holiday'    => ['Feriado', 'secondary'],
            'day_off'    => ['Descanso', 'secondary'],
            'incomplete' => ['Sin salida', 'warning'],
            'suspicious' => ['Sospechosa', 'danger'],
        ],
        'justification_status' => [
            'none'     => ['Sin justificación', 'secondary'],
            'pending'  => ['Pendiente', 'warning'],
            'approved' => ['Aprobada', 'success'],
            'rejected' => ['Rechazada', 'danger'],
        ],
        'leave_status' => [
            'pending'   => ['Pendiente', 'warning'],
            'approved'  => ['Aprobada', 'success'],
            'rejected'  => ['Rechazada', 'danger'],
            'cancelled' => ['Cancelada', 'secondary'],
        ],
        'leave_type' => [
            'vacation'      => ['Vacaciones', 'info'],
            'permission'    => ['Permiso', 'primary'],
            'medical_leave' => ['Licencia médica', 'warning'],
        ],
        'document_status' => [
            'pending'    => ['Por revisar', 'warning'],
            'approved'   => ['Aprobado', 'success'],
            'rejected'   => ['Rechazado', 'danger'],
            'superseded' => ['Reemplazado', 'secondary'],
        ],
        'document_type' => [
            'dni'              => ['Documento de identidad', 'secondary'],
            'cv'               => ['Currículum (CV)', 'secondary'],
            'contract'         => ['Contrato', 'secondary'],
            'proof_of_address' => ['Recibo de servicios / domicilio', 'secondary'],
            'certificate'      => ['Certificado', 'secondary'],
            'other'            => ['Otro', 'secondary'],
        ],
        'payslip_status' => [
            'draft'     => ['Borrador', 'secondary'],
            'published' => ['Publicada', 'success'],
            'archived'  => ['Anulada', 'danger'],
        ],
        'uniform_item' => [
            'shirt'  => ['Polo / camisa', 'secondary'],
            'pants'  => ['Pantalón', 'secondary'],
            'shoes'  => ['Calzado', 'secondary'],
            'jacket' => ['Casaca', 'secondary'],
            'helmet' => ['Casco', 'secondary'],
            'vest'   => ['Chaleco', 'secondary'],
            'other'  => ['Otro', 'secondary'],
        ],
        'uniform_status' => [
            'complete'   => ['Tallas completas', 'success'],
            'incomplete' => ['Tallas pendientes', 'warning'],
            'delivered'  => ['Entregado', 'success'],
            'pending'    => ['Por entregar', 'warning'],
        ],
        'shoe_type' => [
            'safety'  => ['Calzado de seguridad', 'secondary'],
            'formal'  => ['Zapatos de vestir', 'secondary'],
            'sneaker' => ['Zapatillas', 'secondary'],
        ],
        'user_role' => [
            'super_admin'   => ['Super Admin', 'dark'],
            'company_admin' => ['Administrador', 'primary'],
            'hr_manager'    => ['RRHH', 'info'],
            'manager'       => ['Gerencia', 'primary'],
            'accountant'    => ['Contabilidad', 'info'],
            'logistics'     => ['Logística', 'info'],
            'supervisor'    => ['Supervisor', 'secondary'],
            'employee'      => ['Colaborador', 'light'],
        ],
        'user_status' => [
            'active'    => ['Activo', 'success'],
            'inactive'  => ['Inactivo', 'secondary'],
            'suspended' => ['Suspendido', 'warning'],
        ],
        'tenant_status' => [
            'active'    => ['Activa', 'success'],
            'suspended' => ['Suspendida', 'warning'],
            'cancelled' => ['Dada de baja', 'secondary'],
        ],
        'tenant_plan' => [
            'trial'        => ['Prueba', 'secondary'],
            'basic'        => ['Básico', 'info'],
            'professional' => ['Profesional', 'primary'],
            'enterprise'   => ['Empresarial', 'dark'],
        ],
        'branch_status' => [
            '1' => ['Activa', 'success'],
            '0' => ['Inactiva', 'secondary'],
        ],
        'active_status' => [
            '1' => ['Activo', 'success'],
            '0' => ['Inactivo', 'secondary'],
        ],
        'scope_type' => [
            'global' => ['Toda la empresa', 'secondary'],
            'zone'   => ['Una zona', 'info'],
            'branch' => ['Una sede', 'primary'],
        ],
        'branch_type' => [
            'agency'    => ['Agencia', 'primary'],
            'terminal'  => ['Terminal', 'info'],
            'warehouse' => ['Almacén', 'warning'],
            'workshop'  => ['Taller', 'secondary'],
            'corporate' => ['Corporativo', 'dark'],
        ],
        'shift_type' => [
            'fixed'    => ['Fijo', 'primary'],
            'rotating' => ['Rotativo', 'info'],
        ],
        'holiday_type' => [
            'national' => ['Feriado nacional', 'danger'],
            'regional' => ['Feriado regional', 'warning'],
            'company'  => ['Día no laborable de la empresa', 'info'],
        ],
        'notification_type' => [
            'leave_request.submitted' => ['Nueva solicitud por aprobar', 'info'],
            'leave_request.approved'  => ['Solicitud aprobada', 'success'],
            'leave_request.rejected'  => ['Solicitud rechazada', 'danger'],
            'payslip.published'       => ['Nueva boleta de pago', 'primary'],
            'document.approved'       => ['Documento aprobado', 'success'],
            'document.rejected'       => ['Documento rechazado', 'danger'],
            'justification.approved'  => ['Justificación aprobada', 'success'],
            'justification.rejected'  => ['Justificación rechazada', 'danger'],
            'overtime.approved'       => ['Horas extra aprobadas', 'success'],
            'overtime.rejected'       => ['Horas extra rechazadas', 'danger'],
        ],
        'overtime_status' => [
            'pending'  => ['Por aprobar', 'warning'],
            'approved' => ['Aprobadas', 'success'],
            'rejected' => ['Rechazadas', 'danger'],
        ],
        'geofence_policy' => [
            'reject' => ['Rechazar', 'danger'],
            'flag'   => ['Registrar como incidencia', 'warning'],
        ],
        'mock_location_policy' => [
            'reject' => ['Rechazar', 'danger'],
            'flag'   => ['Registrar como sospechosa', 'warning'],
            'allow'  => ['Permitir', 'secondary'],
        ],
        'geocoding_provider' => [
            'nominatim' => ['OpenStreetMap (gratis)', 'secondary'],
            'google'    => ['Google Maps', 'primary'],
            'none'      => ['Sin direcciones', 'secondary'],
        ],
        'id_document_type' => [
            'dni'      => ['DNI', 'secondary'],
            'ce'       => ['Carné de extranjería', 'secondary'],
            'passport' => ['Pasaporte', 'secondary'],
        ],
        'gender' => [
            'male'   => ['Masculino', 'secondary'],
            'female' => ['Femenino', 'secondary'],
            'other'  => ['Otro', 'secondary'],
        ],
        'contract_type' => [
            'full_time'  => ['Tiempo completo', 'secondary'],
            'part_time'  => ['Tiempo parcial', 'secondary'],
            'contractor' => ['Locación de servicios', 'secondary'],
            'intern'     => ['Practicante', 'secondary'],
        ],
        'bank_account_type' => [
            'savings'  => ['Ahorros', 'secondary'],
            'checking' => ['Corriente', 'secondary'],
            'payroll'  => ['Cuenta sueldo', 'secondary'],
        ],
        'pension_system' => [
            'afp'             => ['AFP (privado)', 'secondary'],
            'onp'             => ['ONP (estatal)', 'secondary'],
            'social_security' => ['Seguridad Social', 'secondary'],
            'none'            => ['Sin régimen', 'secondary'],
        ],
        'health_insurance' => [
            'essalud' => ['EsSalud', 'secondary'],
            'eps'     => ['EPS', 'secondary'],
            'sis'     => ['SIS', 'secondary'],
            'imss'    => ['IMSS', 'secondary'],
            'private' => ['Seguro privado', 'secondary'],
            'none'    => ['Ninguno', 'secondary'],
        ],
        'labor_regime' => [
            'general'       => ['Régimen general', 'secondary'],
            'micro_company' => ['Microempresa', 'secondary'],
            'small_company' => ['Pequeña empresa', 'secondary'],
            'agrarian'      => ['Agrario', 'secondary'],
            'construction'  => ['Construcción civil', 'secondary'],
            'part_time'     => ['Tiempo parcial', 'secondary'],
            'other'         => ['Otro', 'secondary'],
        ],
        'payment_info_status' => [
            'incomplete' => ['Incompleto', 'secondary'],
            'pending'    => ['Por verificar', 'warning'],
            'verified'   => ['Verificado', 'success'],
            'rejected'   => ['Observado', 'danger'],
        ],
        'punch_type' => [
            'clock_in'    => ['Entrada', 'success'],
            'break_start' => ['Inicio de descanso', 'secondary'],
            'break_end'   => ['Fin de descanso', 'secondary'],
            'clock_out'   => ['Salida', 'primary'],
        ],
        'onboarding_status' => [
            'pending'   => ['En proceso', 'secondary'],
            'submitted' => ['Por revisar', 'warning'],
            'approved'  => ['Aprobada', 'success'],
            'rejected'  => ['Observada', 'danger'],
        ],
        'onboarding_stage_status' => [
            'completed'      => ['Completa', 'success'],
            'pending'        => ['Pendiente', 'warning'],
            'not_applicable' => ['No aplica', 'secondary'],
        ],
        'ethics_status' => [
            'received'   => ['Recibida', 'warning'],
            'in_review'  => ['En investigación', 'primary'],
            'needs_info' => ['Necesita información', 'info'],
            'closed'     => ['Cerrada', 'secondary'],
        ],
        'punch_attempt_code' => [
            'ONBOARDING_PENDING'      => ['Incorporación sin aprobar', 'secondary'],
            'GEOFENCE_VIOLATION'      => ['Fuera de la geocerca', 'danger'],
            'MOCK_LOCATION_DETECTED'  => ['Ubicación simulada', 'danger'],
            'GPS_ACCURACY_TOO_LOW'    => ['GPS impreciso', 'warning'],
            'FACE_NOT_DETECTED'       => ['Sin rostro en la selfie', 'warning'],
            'NO_BRANCH_ASSIGNED'      => ['Sin sucursal asignada', 'secondary'],
            'BREAK_NOT_ALLOWED'       => ['Turno sin descanso', 'secondary'],
            'OFFLINE_NOT_ALLOWED'     => ['Sin conexión no permitido', 'warning'],
            'OFFLINE_TOO_OLD'         => ['Sin conexión demasiado antigua', 'warning'],
            'OFFLINE_BEFORE_CLOCK_IN' => ['Sin conexión antes de la entrada', 'warning'],
            'OFFLINE_OUT_OF_ORDER'    => ['Sin conexión fuera de orden', 'warning'],
            'PHOTO_UNREADABLE'        => ['Foto ilegible', 'warning'],
        ],
        'suspicion_reason' => [
            'MOCK_LOCATION'    => ['Ubicación simulada', 'danger'],
            'TIME_DRIFT'       => ['Hora del celular desfasada', 'warning'],
            'FACE_UNVERIFIED'  => ['Rostro no verificado', 'warning'],
            'OUT_OF_GEOFENCE'  => ['Fuera de la geocerca', 'warning'],
            'OUTSIDE_SCHEDULE' => ['Fuera del horario', 'warning'],
            'UNSAFE_DEVICE'    => ['Teléfono con root o app modificada', 'danger'],
            'GEOFENCE_EDGE'        => ['En el borde de la geocerca', 'warning'],
            'IMPOSSIBLE_TRAVEL'    => ['Viaje imposible desde la marcación anterior', 'danger'],
            'REPEATED_COORDINATES' => ['Coordenadas idénticas a otro día', 'danger'],
            'STALE_PHOTO'          => ['Selfie tomada mucho antes de enviar', 'warning'],
        ],
        'device_flag' => [
            'rooted'   => ['Root / jailbreak', 'danger'],
            'emulator' => ['Emulador', 'danger'],
            'debugger' => ['Depurador conectado', 'warning'],
            'hooked'   => ['Herramienta de manipulación (Frida/Xposed)', 'danger'],
            'tampered' => ['App modificada (firma distinta)', 'danger'],
        ],
        'webhook_event' => [
            'ping'                         => ['Prueba del webhook', 'info'],
            'notification.test'            => ['Prueba del endpoint de notificaciones', 'info'],
            'attendance.clock_in'          => ['Marcación de entrada', 'success'],
            'attendance.break_start'       => ['Inicio de descanso', 'secondary'],
            'attendance.break_end'         => ['Fin de descanso', 'secondary'],
            'attendance.clock_out'         => ['Marcación de salida', 'primary'],
            'leave_request.status_changed' => ['Cambio de estado de una solicitud', 'primary'],
            'notification.created'         => ['Nuevo aviso para un colaborador', 'info'],
        ],
        'audit_scope' => [
            'tenant'   => ['Empresa', 'secondary'],
            'platform' => ['Toda la plataforma', 'dark'],
        ],
        'attendance_moment' => [
            'clock_in'    => ['Entrada', 'secondary'],
            'clock_out'   => ['Salida', 'secondary'],
            'break_start' => ['Inicio de descanso', 'secondary'],
            'break_end'   => ['Fin de descanso', 'secondary'],
        ],
        'export_format' => [
            'csv'  => ['Excel (CSV)', 'secondary'],
            'xlsx' => ['Excel', 'secondary'],
            'pdf'  => ['PDF', 'secondary'],
        ],
        'weekday' => [
            '1' => ['Lunes', 'secondary'], '2' => ['Martes', 'secondary'], '3' => ['Miércoles', 'secondary'],
            '4' => ['Jueves', 'secondary'], '5' => ['Viernes', 'secondary'], '6' => ['Sábado', 'secondary'],
            '7' => ['Domingo', 'secondary'], '0' => ['Domingo', 'secondary'],
        ],
        'month' => [
            '1' => ['Enero', 'secondary'], '2' => ['Febrero', 'secondary'], '3' => ['Marzo', 'secondary'],
            '4' => ['Abril', 'secondary'], '5' => ['Mayo', 'secondary'], '6' => ['Junio', 'secondary'],
            '7' => ['Julio', 'secondary'], '8' => ['Agosto', 'secondary'], '9' => ['Septiembre', 'secondary'],
            '10' => ['Octubre', 'secondary'], '11' => ['Noviembre', 'secondary'], '12' => ['Diciembre', 'secondary'],
        ],
    ];

    /** Tipo de valor para formatear (lo que no está aquí se infiere del nombre) */
    public const FIELD_TYPES = [
        'working_days'       => 'weekdays',
        'supervisor_id'      => 'reference',
        'zone_id'            => 'reference',
        'department_id'      => 'reference',
        'position_id'        => 'reference',
        'scope_zone_id'      => 'reference',
        'scope_branch_id'    => 'reference',
        'user_id'            => 'reference',
        'employee_id'        => 'reference',
        'punch_id'           => 'hidden',
        'branch_id'          => 'reference',
        'shift_id'           => 'reference',
        'reviewed_by'        => 'reference',
        'overtime_reviewed_by' => 'reference',
        'manual_edited_by'   => 'reference',
        'uploaded_by'        => 'reference',
        'updated_by'         => 'reference',
        'payment_verified_by' => 'reference',
        'assigned_by'        => 'reference',
        'delivered_by'       => 'reference',
        'tenant_id'          => 'reference',
        'required_documents' => 'document_types',
        'changed'            => 'fields',
        'fields'             => 'fields',
        'secrets_changed'    => 'fields',
        'daily_hours'        => 'hours',
        'worked_hours'       => 'hours',
        'overtime_hours'     => 'hours',
        'calculated_hours'   => 'hours',
        'overtime_approved_hours' => 'hours',
        'approved_hours'     => 'hours',
        'offline_max_hours'  => 'hours',
        'max_upload_mb'      => 'megabytes',
        'size_bytes'         => 'bytes',
        'file_size'          => 'bytes',
        'max_time_drift_seconds' => 'seconds',
        'gross_salary'       => 'money',
        'net_salary'         => 'money',
        'deductions'         => 'money',
        'base_salary'        => 'money',
        'start_time'         => 'time',
        'end_time'           => 'time',
        'is_active'          => 'bool',
        'crosses_midnight'   => 'bool',
        'break_allowed'      => 'bool',
        'must_change_password' => 'bool',
        'force_update'       => 'bool',
        'lunch_paid'         => 'bool',
        'punch_requires_schedule' => 'bool',
        'offline_mode_enabled' => 'bool',
        'self_password_change' => 'bool',
        'within_geofence'    => 'bool',
        'clock_in_within_geofence'  => 'bool',
        'clock_out_within_geofence' => 'bool',
        'clock_in_mock_location'    => 'bool',
        'clock_out_mock_location'   => 'bool',
        'clock_in_face_detected'    => 'bool',
        'clock_out_face_detected'   => 'bool',
        'clock_in_time_drift_seconds'  => 'seconds',
        'clock_out_time_drift_seconds' => 'seconds',
        'lock_verified_payment_info' => 'bool',
        'late_minutes'       => 'minutes',
        'break_minutes'      => 'minutes',
        'rotation_work_days' => 'number',
        'rotation_rest_days' => 'number',
        'failed_login_attempts' => 'number',
        'latitude'           => 'text',
        'longitude'          => 'text',
        'days'               => 'number',
        'sha256'             => 'hidden',
        'clock_in_meta'      => 'hidden',
        'clock_out_meta'     => 'hidden',
        'breakdown'          => 'hidden',
        'settings'           => 'hidden',
        'last_login_ip'      => 'hidden',
        'message'            => 'text',
    ];

    // =========================================================================
    // API
    // =========================================================================

    public static function action(?string $action): string
    {
        if ($action === null || $action === '') {
            return '—';
        }

        return self::ACTIONS[$action][0] ?? self::humanize(str_replace('.', ' ', $action));
    }

    /** ['label' => ..., 'tone' => ..., 'icon' => ...] */
    public static function actionBadge(?string $action): array
    {
        [$label, $tone, $icon] = self::ACTIONS[$action] ?? [self::action($action), 'secondary', 'journal-text'];

        return compact('label', 'tone', 'icon');
    }

    public static function area(string $action): string
    {
        return self::AREAS[Str::before($action, '.')] ?? 'el registro';
    }

    public static function subject(?string $type): string
    {
        return $type ? (self::SUBJECTS[$type] ?? self::humanize(Str::snake($type))) : '—';
    }

    public static function field(string $field, ?string $subjectType = null): string
    {
        if ($subjectType === 'Branch' && $field === 'is_active') {
            return 'Activa';
        }

        return self::FIELDS[$field] ?? self::humanize($field);
    }

    public static function hasField(string $field): bool
    {
        return isset(self::FIELDS[$field]);
    }

    /** Etiqueta de un valor de enum (o el valor "humanizado" si no se conoce) */
    public static function enum(string $set, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            $value = (int) $value;
        }

        return self::ENUMS[$set][(string) $value][0] ?? self::humanize((string) $value);
    }

    public static function tone(string $set, mixed $value): string
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }

        return self::ENUMS[$set][(string) $value][1] ?? 'secondary';
    }

    /** [valor => etiqueta] para un <select> */
    public static function options(string $set): array
    {
        return array_map(fn ($pair) => $pair[0], self::ENUMS[$set] ?? []);
    }

    /** Conjunto de enum que aplica a una columna (o null) */
    public static function enumSetFor(string $field, ?string $subjectType = null): ?string
    {
        return self::SUBJECT_ENUMS[$subjectType][$field] ?? self::FIELD_ENUMS[$field] ?? null;
    }

    /**
     * Valor listo para mostrar. Devuelve HtmlString solo para colores
     * (muestra + hex); lo demás es texto plano.
     *
     * @param  bool  $inherits  true en Marca y app: null = "Hereda de la plataforma"
     */
    public static function value(string $field, mixed $value, ?string $subjectType = null, bool $inherits = false, ?string $timezone = null): string|HtmlString
    {
        if ($value === null || $value === '' || $value === []) {
            return $inherits ? 'Hereda de la plataforma' : '—';
        }

        if (SensitiveFields::isSensitive($field)) {
            return SensitiveFields::isMasked($value) ? (string) $value : (string) SensitiveFields::mask($field, $value);
        }

        if ($set = self::enumSetFor($field, $subjectType)) {
            return is_array($value)
                ? self::join(array_map(fn ($v) => self::enum($set, $v), $value))
                : self::enum($set, $value);
        }

        $type = self::FIELD_TYPES[$field] ?? self::inferType($field, $value);

        return match ($type) {
            'bool'           => self::bool($value),
            'minutes'        => self::number($value) . ' min',
            'meters'         => self::number($value) . ' m',
            'hours'          => self::number($value) . ' h',
            'seconds'        => self::number($value) . ' s',
            'megabytes'      => self::number($value) . ' MB',
            'bytes'          => self::bytes((int) $value),
            'money'          => 'S/ ' . number_format((float) $value, 2),
            'number'         => self::number($value),
            'color'          => self::color((string) $value),
            'image'          => 'Imagen cargada',
            'file'           => 'Archivo cargado',
            'datetime'       => self::datetime($value, $timezone),
            'date'           => self::date($value),
            'time'           => substr((string) $value, 0, 5),
            'weekdays'       => self::join(array_map(fn ($d) => self::enum('weekday', $d), (array) $value)),
            'document_types' => self::join(array_map(fn ($d) => self::enum('document_type', $d), (array) $value)),
            'fields'         => self::join(array_map(fn ($f) => self::field((string) $f, $subjectType), self::listOf($value))),
            'hidden'         => 'Dato técnico',
            'reference'      => 'Registro vinculado',
            default          => self::text($value),
        };
    }

    public static function bool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No';
    }

    /** "A, B y C" */
    public static function join(array $items, int $max = 0): string
    {
        $items = array_values(array_filter($items, fn ($i) => $i !== '' && $i !== null));
        if ($max && count($items) > $max) {
            $rest  = count($items) - $max;
            $items = array_slice($items, 0, $max);
            $items[] = "{$rest} más";
        }
        if (count($items) <= 1) {
            return $items[0] ?? '—';
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' y ' . $last;
    }

    /** Fecha y hora en la zona de la empresa, dd/mm/yyyy HH:mm */
    public static function datetime(mixed $value, ?string $timezone = null): string
    {
        try {
            $date = $value instanceof CarbonInterface ? $value->copy() : Carbon::parse($value);
        } catch (\Throwable) {
            return self::text($value);
        }

        return $date->setTimezone($timezone ?: self::timezone())->format('d/m/Y h:i a');
    }

    public static function date(mixed $value): string
    {
        try {
            $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);
        } catch (\Throwable) {
            return self::text($value);
        }

        return $date->format('d/m/Y');
    }

    /** "Chrome en Android", "Safari en iPhone", "App TuneSA en Android" */
    public static function userAgent(?string $ua): string
    {
        if (blank($ua)) {
            return '—';
        }

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => str_contains($ua, 'iPad') ? 'iPad' : 'iPhone',
            (bool) preg_match('/Android/i', $ua)         => 'Android',
            (bool) preg_match('/Windows/i', $ua)         => 'Windows',
            (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'Mac',
            (bool) preg_match('/CrOS/i', $ua)            => 'Chromebook',
            (bool) preg_match('/Linux/i', $ua)           => 'Linux',
            default                                      => null,
        };

        $client = match (true) {
            (bool) preg_match('/TuneSA|Negreiros|okhttp|Dart|Expo|ReactNative|CFNetwork/i', $ua) => 'App móvil',
            (bool) preg_match('/Edg\//i', $ua)            => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua)      => 'Opera',
            (bool) preg_match('/SamsungBrowser/i', $ua)   => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/i', $ua)    => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/i', $ua)     => 'Chrome',
            (bool) preg_match('/Safari/i', $ua)           => 'Safari',
            (bool) preg_match('/curl|Postman|Guzzle|Symfony|python|axios/i', $ua) => 'Integración',
            default                                       => 'Navegador',
        };

        return $os ? "{$client} en {$os}" : $client;
    }

    public static function humanize(string $value): string
    {
        return Str::ucfirst(trim(str_replace(['_', '-', '.'], ' ', Str::snake($value))));
    }

    // =========================================================================

    private static function inferType(string $field, mixed $value): string
    {
        return match (true) {
            is_bool($value)                               => 'bool',
            str_ends_with($field, '_color') || $field === 'color' => 'color',
            str_ends_with($field, '_minutes') || $field === 'lunch_minutes' => 'minutes',
            str_ends_with($field, '_meters') || str_ends_with($field, '_radius') => 'meters',
            str_ends_with($field, '_hours')               => 'hours',
            str_ends_with($field, '_seconds')             => 'seconds',
            str_ends_with($field, '_path') || str_ends_with($field, '_photo') => str_contains($field, 'logo') || str_contains($field, 'photo') || str_contains($field, 'favicon') || str_contains($field, 'splash') || str_contains($field, 'avatar') ? 'image' : 'file',
            $field === 'justification_file'               => 'file',
            str_ends_with($field, '_at') || str_ends_with($field, '_until') && ! str_contains($field, 'effective') => 'datetime',
            str_ends_with($field, '_date') || in_array($field, ['date', 'effective_from', 'effective_until'], true) => 'date',
            str_starts_with($field, 'is_') || str_starts_with($field, 'has_') || str_ends_with($field, '_enabled') || str_ends_with($field, '_required') => 'bool',
            default                                       => 'text',
        };
    }

    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : array_map('trim', explode(',', (string) $value));
    }

    private static function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }

        return (float) $value == (int) $value
            ? number_format((int) $value, 0, ',', '.')
            : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private static function color(string $hex): HtmlString|string
    {
        if (! preg_match('/^#[0-9A-Fa-f]{3,8}$/', $hex)) {
            return self::text($hex);
        }
        $hex = strtoupper($hex);

        return new HtmlString(
            '<span class="d-inline-flex align-items-center gap-1"><span class="color-swatch" style="display:inline-block;width:.9em;height:.9em;border-radius:.2em;border:1px solid rgba(0,0,0,.15);background:' . e($hex) . '"></span>' . e($hex) . '</span>'
        );
    }

    /** Texto plano: los arreglos nunca se muestran como JSON */
    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return self::bool($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return self::join(array_map(fn ($v) => is_scalar($v) ? (string) $v : 'Dato técnico', $value));
            }

            return self::join(array_map(
                fn ($k, $v) => self::field((string) $k) . ': ' . (is_scalar($v) ? (string) $v : '…'),
                array_keys($value), $value
            ));
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : 'Dato técnico';
        }

        return (string) $value;
    }

    private static function timezone(): string
    {
        return function_exists('tenant_timezone') ? tenant_timezone() : config('app.timezone');
    }
}
