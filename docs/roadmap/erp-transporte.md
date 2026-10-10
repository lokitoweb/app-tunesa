# Propuesta: ERP para transporte interprovincial

Hoja de ruta para llevar Tunesa de "asistencia y RRHH" a un ERP completo para una empresa de
transporte de pasajeros y encomiendas con muchas agencias. Es una propuesta: el orden y el
alcance de cada fase se ajustan con la operación real.

## Punto de partida (ya construido)

| Capa | Estado |
|---|---|
| Monolito modular (`app/Modules/*`), módulos activables por empresa | Hecho |
| Multi-empresa con aislamiento por `tenant_id` | Hecho |
| Jerarquía Zona → Sede → Área y alcance por territorio (empresa / zona / sede) | Hecho |
| Asistencia con GPS, selfie, geocercas, marcación itinerante, modo sin conexión | Hecho |
| RRHH: colaboradores, turnos, feriados, solicitudes, legajo, dotación | Hecho |
| Boletas de pago y exportación para planilla | Hecho |
| Integraciones: webhooks firmados, geocodificación, push | Hecho |
| Panel Vue 3 + Inertia (migración por pantallas) y app Flutter | En curso |

## Módulos nuevos

Cada módulo es una carpeta en `app/Modules` con sus rutas, modelos, servicios y migraciones, y
su carpeta de pantallas en `resources/js/Modules`. Todos heredan el aislamiento por empresa y
el alcance por zona o sede sin código adicional.

### 1. Flota (`Fleet`)
- Unidades: placa, marca, modelo, año, tipo de servicio, asientos por piso, estado.
- Documentos con vencimiento y alertas: SOAT, revisión técnica, tarjeta de circulación, pólizas.
- Kilometraje acumulado y sede base de cada unidad.
- Conductores habilitados: licencia, categoría, vencimiento, récord.

### 2. Rutas y viajes (`Trips`)
- Rutas con paradas ordenadas (origen, escalas, destino), distancia y duración estimada.
- Itinerarios (salidas recurrentes por día y hora) y programación de viajes concretos.
- Asignación de unidad y tripulación (piloto, copiloto, terramozo) validando descansos
  obligatorios contra los turnos de RRHH y la asistencia.
- Bitácora del viaje: salida real, llegada real, incidencias, kilometraje.

### 3. Boletería (`Ticketing`)
- Mapa de asientos por unidad y venta por agencia con bloqueo de asiento mientras se cobra.
- Tarifas por ruta, tramo, servicio y temporada; descuentos y cortesías con autorización.
- Manifiesto de pasajeros por viaje.
- Comprobantes electrónicos mediante un proveedor autorizado (integración, no desarrollo propio).

### 4. Encomiendas (`Cargo`)
- Recepción en agencia con guía, peso, valor declarado y destinatario.
- Seguimiento por estados entre agencias (recibida, en tránsito, en destino, entregada).
- Asignación a un viaje y manifiesto de carga; entrega con firma o código.

### 5. Combustible (`Fuel`)
- Cargas por unidad y viaje: grifo, galones, precio, kilometraje al cargar.
- Rendimiento por unidad, ruta y conductor; alertas por consumo fuera de rango.
- Vales y conciliación con la factura del proveedor.

### 6. Mantenimiento (`Maintenance`)
- Planes preventivos por kilometraje o tiempo, con aviso anticipado.
- Órdenes de trabajo de taller, repuestos consumidos y costo por unidad.
- Una unidad con orden abierta crítica no se puede programar en un viaje.

### 7. Caja y finanzas (`Finance`)
- Apertura y cierre de caja por agencia y turno, con arqueo.
- Gastos de viaje (peajes, viáticos) y liquidación por viaje y por tripulación.
- Depósitos y conciliación; exportación al sistema contable.

### 8. Reportes gerenciales (`Insights`)
- Tablero por empresa, zona y agencia: ocupación, ingresos, costo por kilómetro, puntualidad.
- Rentabilidad por ruta y por unidad; comparativos entre periodos.
- Exportación a Excel y envío programado a gerencia.

## Orden recomendado

| Fase | Entrega | Por qué en ese orden |
|---|---|---|
| 0 | Motor de turnos (part-time con tope, guardias 12x24 / 24x24, sobretasa nocturna, cuadrillas, suplencias) | Cierra lo pendiente de RRHH antes de sumar tripulaciones |
| 1 | Flota | Todo lo demás cuelga de la unidad |
| 2 | Rutas y viajes | Une unidad, tripulación y sedes; habilita el resto |
| 3 | Combustible y Mantenimiento | Controlan el mayor costo operativo con poco riesgo |
| 4 | Boletería | El de mayor volumen y exigencia; conviene llegar con la base probada |
| 5 | Encomiendas | Reutiliza viajes, agencias y caja |
| 6 | Caja y finanzas, Reportes gerenciales | Consolidan lo que ya generan los otros módulos |

## Arquitectura para crecer

- **Límites entre módulos**: un módulo solo usa los servicios públicos de otro, nunca sus tablas.
  `ModularArchitectureTest` ya impide que un módulo sirva controladores de otro; se amplía para
  vigilar también los imports entre módulos.
- **Eventos de dominio**: "viaje cerrado", "caja cerrada", "unidad fuera de servicio". Los
  módulos reaccionan a eventos en cola en lugar de llamarse entre sí, y los mismos eventos
  salen por webhook hacia sistemas externos.
- **Roles y permisos por módulo**: cada módulo declara sus permisos (`trips.schedule`,
  `tickets.sell`, `cash.close`) y sus roles sugeridos (despachador, boletero, jefe de taller,
  cajero). El alcance por zona o sede ya aplica a todos.
- **Venta sin doble asiento**: la reserva de asiento usa bloqueo en base de datos y una
  restricción única (viaje, asiento); no depende de la interfaz.
- **Base de datos**: MySQL o PostgreSQL en producción (no SQLite), índices compuestos que
  empiezan por `tenant_id`, y particionado por fecha en las tablas de alto volumen (marcaciones,
  boletos, movimientos de caja) cuando el tamaño lo pida.
- **Colas y caché**: Redis para colas, caché y sesiones; workers separados por tipo de tarea
  (notificaciones, webhooks, reportes pesados).
- **Tiempo real**: canal de eventos (WebSocket) para el tablero de salidas y el mapa de asientos,
  reemplazando el sondeo actual.
- **Archivos**: almacenamiento de objetos compatible con S3 para selfies, documentos y
  comprobantes, con URLs firmadas como hoy.
- **API**: versión explícita `/api/v1` para todo lo nuevo, documentación OpenAPI generada y
  límites de uso por token.
- **App móvil**: una sola app con secciones según el rol (colaborador, conductor, despachador,
  boletero), que hereda el modo sin conexión de la asistencia para el manifiesto y el control de
  embarque.
- **Operación**: entornos de pruebas y producción separados, despliegue automático desde la
  rama principal, copias de seguridad diarias con restauración probada, monitoreo de errores y
  de colas.

## Seguridad

- Actualizaciones del framework y dependencias con revisión automática de avisos en cada PR.
- Segundo factor para administradores y roles con acceso a caja.
- Bitácora de auditoría extendida a toda operación de dinero (anulaciones, descuentos, cierres).
- Política de retención y borrado de datos personales (selfies, documentos, pasajeros).
- Pruebas de aislamiento por empresa y por territorio para cada modelo nuevo
  (`TenantIsolationSweepTest`, `OrgHierarchyTest` como patrón).

## Decisiones que necesitan tu respuesta

1. ¿Boletería propia o integración con el sistema de venta que ya use la empresa?
2. ¿Qué proveedor de facturación electrónica se usará?
3. ¿Los buses llevan GPS de un proveedor? Si es así, se integra en Viajes en lugar de depender
   del teléfono del conductor.
4. ¿Cuántas agencias y cuántos usuarios simultáneos se esperan el primer año? Define el tamaño
   de la infraestructura.
