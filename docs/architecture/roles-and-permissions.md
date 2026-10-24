# Roles, permisos y paneles

Cada persona tiene **un rol** (`users.role`) y cada rol tiene **una lista de
permisos** (`database/seeders/RolesAndPermissionsSeeder.php`). El panel web y la
app no preguntan "¿es RRHH?": preguntan "¿tiene `reports.view`?". Por eso un menú
o una pantalla aparece sola cuando el rol tiene el permiso, y además el módulo
debe estar activo para la empresa (Empresas › Módulos).

## Los 7 roles

| Rol (slug) | Para quién | Puede |
|---|---|---|
| Super Admin (`super_admin`) | Operador de la plataforma | Todo, en todas las empresas. Configura la plataforma (Marca y app global, geocodificación, push). |
| Administrador (`company_admin`) | Dueño de la cuenta de la empresa | Todo dentro de su empresa, menos administrar otras empresas. |
| RRHH (`hr_manager`) | Recursos Humanos | Colaboradores, turnos, solicitudes, justificaciones, boletas, legajos, capacitaciones, comunicados, beneficios, documentos. No toca marca, configuración de la app ni bitácora. |
| Gerencia (`manager`) | Gerentes / dirección | Ve toda la empresa: tablero, reportes (con exportación), asistencia, calendario, faltas, tardanzas y horas extra; aprueba solicitudes, **corrige marcaciones** (p. ej. una salida olvidada) y **autoriza marcación fuera de turno**. No crea ni edita personas. |
| Contabilidad (`accountant`) | Contabilidad / planilla | Boletas de pago, cruce con planilla (horas pagables, horas extra aprobadas, cuentas), reportes y asistencia de toda la empresa. No aprueba solicitudes. |
| Supervisor (`supervisor`) | Jefes de sede o de turno | Asistencia y solicitudes de **su equipo** (sedes donde es supervisor). |
| Colaborador (`employee`) | Todo el personal | La app: marcar, historial, solicitudes, boletas, legajo, capacitaciones, comunicados, beneficios, cumpleaños, documentos. |

Rango para crear usuarios (`User::ROLE_RANKS`): nadie crea a alguien de rango
superior; RRHH no crea administradores ni Gerencia, y viceversa.

## Qué ve cada rol en el panel

| Sección del panel | Permiso | Admin | RRHH | Gerencia | Contab. | Superv. |
|---|---|:-:|:-:|:-:|:-:|:-:|
| Tablero y mapa en vivo | `dashboard.view` | ✔ | ✔ | ✔ | ✔ | ✔ |
| Asistencia / calendario / faltas | `attendance.view_all` (o `view_team`) | ✔ | ✔ | ✔ | ✔ | equipo |
| Aprobaciones (solicitudes, vacaciones, justificaciones, horas extra) | `leave.approve_*`, `attendance.review` | ✔ | ✔ | ✔ | — | equipo |
| Control de faltas (programación vs marcaciones; botón Regularizar) | `attendance.view_all` (o `view_team`) | ✔ | ✔ | ✔ | ✔ | equipo |
| Corrección manual de una jornada (completar entrada/salida) | `attendance.edit` | ✔ | ✔ | ✔ | — | equipo |
| Marcación fuera de turno (autorizar un día sin programación) | `attendance.authorize_offshift` | ✔ | ✔ | ✔ | — | — |
| Reportes (tardanzas, horas, horas extra, faltas; filtros por sede/área/fecha; Excel) | `reports.view`, `reports.export` | ✔ | ✔ | ✔ | ✔ | ver |
| Colaboradores | `users.manage` | ✔ | ✔ | — | — | — |
| Turnos, feriados | `shifts.manage` | ✔ | ✔ | — | — | — |
| Sedes y geocercas, organización | `branches.manage` | ✔ | ✔ | — | — | — |
| Boletas de pago | `payslips.manage` | ✔ | ✔ | — | ✔ | — |
| Cruce con planilla | `payroll.export` | ✔ | ✔ | — | ✔ | — |
| Legajo digital (CV, documentos) | `documents.review` | ✔ | ✔ | — | — | — |
| Dotación y uniformes | `uniforms.manage` | ✔ | ✔ | — | — | — |
| Capacitaciones y evaluaciones | `training.manage` | ✔ | ✔ | — | — | — |
| Comunicados de RR. HH. | `announcements.manage` | ✔ | ✔ | — | — | — |
| Beneficios / Cumpleaños / Documentos de la empresa | `benefits.manage`, `birthdays.manage`, `company_docs.manage` | ✔ | ✔ | — | — | — |
| Configuración de la app, Marca, Integraciones | `company.configure` | ✔ | — | — | — | — |
| Bitácora de auditoría | `audit.view` | ✔ | — | — | — | — |

## Usuarios de prueba por rol

Para revisar cada panel con un usuario real:

```bash
php artisan app:role-test-users empresa-demo           # un usuario por rol
php artisan app:role-test-users mi-empresa --domain=miempresa.pe
```

Crea `admin.prueba@`, `rrhh.prueba@`, `gerencia.prueba@`, `contabilidad.prueba@`,
`supervisor.prueba@` y `colaborador.prueba@` en la primera sede activa. Si el
correo ya existe **no lo duplica ni cambia su contraseña**. Las contraseñas nuevas
se muestran una sola vez. En producción exige `--force`; desactiva estos usuarios
antes de salir en vivo.

El seeder demo (`php artisan migrate:fresh --seed`, nunca en producción) también
crea `gerencia@empresa-demo.com` (Gerencia2024!) y `contabilidad@empresa-demo.com`
(Contabilidad2024!).

## Agregar o cambiar un permiso

1. Agrégalo a `PERMISSIONS` y a los roles en `ROLES` del seeder.
2. Crea una migración que lo agregue a las bases existentes (patrón en
   `app/Modules/Announcements/Database/Migrations/2026_10_26_000002_add_announcement_permissions.php`).
3. Protégelo: `permission:x` en la API, `can:x` en el panel, y el ítem en
   `App\Modules\Core\Support\PanelNavigation`.

## Reglas de negocio que dependen del rol

- **Horas de jornada**: lo trabajado cuenta con el turno como tope (8 h aunque se
  quede más); el exceso es hora extra y solo se paga si un aprobador la acepta.
  La planilla paga *jornada + horas extra aprobadas* (`Attendance::payableHours()`).
- **Marcación solo con turno programado**: un día de descanso o sin programación
  la app bloquea los botones ("Hoy no tienes turno programado") y la API rechaza
  la entrada (`NOT_SCHEDULED`). RRHH o Gerencia pueden autorizar un día puntual en
  Asistencia › Marcación fuera de turno. Se apaga por empresa en Configuración ›
  Reglas ("Marcar solo con turno programado"). Un turno que cae en feriado sí
  permite marcar.
- **Sin duplicados**: entrada, inicio y fin de break, y salida solo una vez por
  día; repetirlas responde 409. Lo que faltó se completa con la corrección manual.
- **Faltas**: el calendario, Control de faltas y el historial de la app usan el
  mismo cálculo (turno programado sin marcación; vacaciones, permisos y feriados
  no cuentan). El historial de la app registra las faltas pendientes del mes para
  que la persona pueda justificarlas.
- **GPS**: si la precisión es peor que la permitida, la marcación se acepta solo si
  todo el círculo de error cae dentro de la geocerca; si no, se rechaza. Es
  automático: no hay cola de revisión manual.
- **Avisos push**: solo los importantes (recordatorios de entrada/salida, boletas,
  solicitudes, cumpleaños, comunicados). Los inicios de sesión en un dispositivo
  nuevo quedan en la bandeja sin push. Los comunicados *urgentes* llegan aunque la
  persona haya silenciado los comunicados.
