# Jerarquía organizacional y alcance por territorio

Para empresas con muchas sedes (terminales, agencias, almacenes) el modelo plano
"empresa → sucursales" se amplía a **Empresa → Zona → Sede → Área**, y cada usuario del panel
tiene un **alcance**: toda la empresa, una zona o una sede.

## Modelo de datos (módulo `Core`)

| Tabla | Qué es | Notas |
|---|---|---|
| `zones` | Macro-zona o región (Zona Norte, Centro, Sur) | `name`, `code`, `is_active`; únicos por empresa |
| `branches` | Sede (ya existía) | Nuevos `zone_id` (opcional) y `branch_type`: `agency`, `terminal`, `warehouse`, `workshop`, `corporate`. El radio de marcación sigue en `allowed_radius_meters` |
| `departments` | Área operativa (Operaciones / Ruta, Caja, Almacén y estiba, Seguridad...) | Transversal a las sedes; `users.department_id` |
| `users` | — | Nuevos `department_id`, `scope_type` (`global` / `zone` / `branch`), `scope_zone_id`, `scope_branch_id`, `roaming_attendance_enabled` |

Índices compuestos: `branches (tenant_id, zone_id, id)`, `users (tenant_id, scope_zone_id,
scope_branch_id)`, `users (tenant_id, department_id)` y `employee_branch (user_id, branch_id)`.

Migración: `app/Modules/Core/Database/Migrations/2026_10_17_000001_create_organization_hierarchy.php`.
Todos los usuarios existentes quedan con alcance `global`: nada cambia hasta que se asigne uno.

## Alcance (RBAC por territorio)

El rol sigue diciendo **qué puede hacer** el usuario (matriz de permisos) y el alcance **sobre
qué registros**. Los cargos de la operación son combinaciones:

| Cargo | Rol | Alcance |
|---|---|---|
| Administrador de empresa, Gerente de RRHH, Supervisor nacional | `company_admin`, `hr_manager`, `supervisor` | Toda la empresa |
| Supervisor zonal | `supervisor` (o `hr_manager`) | Una zona |
| Administrador de agencia, encargado de sucursal, supervisor de almacén | `hr_manager` (o `supervisor`) | Una sede |
| Colaborador | `employee` | No lleva alcance: ve y marca solo lo propio |

Se asigna en **Colaboradores › Editar** (campos "Alcance en el panel", "Zona asignada", "Sede
asignada"). Quien tiene alcance limitado no puede dar más alcance que el suyo: los mandos que
registra heredan el mismo.

### Cómo se aplica

- `App\Modules\Core\Services\OrgScope` traduce el alcance del usuario autenticado en subconsultas
  de sedes y de colaboradores asignados a ellas.
- El trait `BelongsToBranchScope` registra el global scope `BranchScope` (segunda barrera después
  de `TenantScope`). Lo usan `Branch`, `User`, `Attendance`, `AttendancePunch`, `EmployeeShift`,
  `LeaveRequest`, `EmployeeDocument`, `EmployeeProfile`, `UniformDelivery`, `UniformSize`, `Payslip`
  y `AuditLog`. Cada modelo declara `BRANCH_SCOPE = [columna de sede, columna de colaborador]`.
  - **Lectura**: solo filas de sus sedes o de colaboradores asignados a ellas. Vale para el
    panel, la API y cualquier consulta nueva, sin tener que acordarse de filtrar.
  - **Escritura**: guardar un registro de otra sede u otro colaborador lanza `DomainException`,
    aunque la petición fuerce los ids. La asignación colaborador ↔ sede (`EmployeeBranch`, el
    pivote) tiene la misma barrera.
- Crear sedes, moverlas de zona y editar zonas y áreas queda para quien tiene alcance de toda la
  empresa.
- Sin usuario autenticado (consola, colas, login) no se restringe nada. Para saltarlo a propósito:
  `Model::withoutGlobalScope(BranchScope::class)`.

Lo que el alcance **no** hace: no quita permisos. Un `company_admin` con alcance de sede sigue
pudiendo abrir "Marca y app". Para un administrador de agencia usa el rol `hr_manager`.

## Marcación itinerante

`users.roaming_attendance_enabled` (switch "Marcación itinerante" del colaborador), para
choferes, relevos y técnicos:

- Colaborador fijo: solo marca dentro de la geocerca de su sede (igual que antes).
- Itinerante dentro de la geocerca de **cualquier sede activa** de la empresa: la marcación queda
  en esa sede.
- Itinerante **en ruta**, fuera de toda geocerca: se acepta, queda en su sede base y guarda
  coordenadas y dirección como cualquier marcación. No se marca como sospechosa.

Lógica en `AttendanceService::roamingBranch()`.

## Pantallas

- **Organización** (`/organization`, Vue: `Core/Pages/Organization/Index.vue`): árbol Zona → Sede
  con filtros en cascada Zona → Sede → Área, dotación activa por área y puesto, cambio de zona y
  tipo de cada sede, y catálogo de zonas y áreas (con las áreas habituales a un clic).
- **Sucursales y geocercas** (`/branches`, Blade): buscador de direcciones, pin en el mapa y radio
  de marcación (ya existía); el formulario suma tipo de sede y zona.
- **Tablero en vivo** (`/live`): un supervisor zonal o un encargado de sede ve solo a su gente;
  admite `?branch_id=` para una sede.

## Pendiente (siguiente entrega)

Motor de turnos y condiciones laborales: jornada part-time con tope semanal y alertas, guardias
12x24 / 24x24, sobretasa nocturna, asignación por cuadrillas (sede + área) y suplencias en un clic.
Los esquemas rotativos por días (4x2, 5x2, 6x1) ya se configuran en Turnos.
