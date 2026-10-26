# Banderas por empresa · QA Ronda 2

Los cambios que alteran el comportamiento existente quedan detrás de una bandera por empresa. Todas vienen **apagadas**: con la bandera apagada, el sistema hace exactamente lo de antes.

- **Dónde se guardan:** `tenants.settings` (JSON), en `flags.<nombre>`. El mismo mecanismo que `disabled_modules` y los cumpleaños, sin migración.
- **Quién las cambia:** solo el comando `attendance:flag`. Cada cambio queda en la bitácora (`tenant.flag_changed`) con el valor anterior, el nuevo, el motivo y el usuario del sistema que corrió el comando.

| Bandera | Qué cambia al encenderla | Estado |
|---|---|---|
| `schedule_v2` | `ScheduleResolver` en modo v2. Todas las pantallas, reportes y procesos eligen la asignación que **trabaja** ese día (empate: vigencia más reciente, luego id). Un día sin turno ya no se mide contra otro horario: sin tardanza, sin descanso del turno, y la marcación queda "fuera de programación" (`OUTSIDE_SCHEDULE`). | Lista para piloto |
| `hours_v2` | Fórmula única de horas trabajadas (RP-01): el reporte descuenta el descanso igual que la jornada guardada (el mayor entre lo marcado y el fijo del turno, o el de la empresa, si la jornada pasa de 5 h). Si la empresa paga el refrigerio (`lunch_paid`), no se descuenta nada. La regla está escrita en el pie del reporte. | Lista. Valores de hoy; RR. HH. confirma si se descuenta el break (pregunta 3) |

## Activar, desactivar y consultar

En el servidor, dentro de la carpeta del proyecto:

```
php artisan attendance:flag <id o slug> schedule_v2                                   # ver estado
php artisan attendance:flag <id o slug> schedule_v2 on  --reason="Piloto Ag. Trujillo"
php artisan attendance:flag <id o slug> schedule_v2 off --reason="Vuelta atrás: <motivo>"
```

- **Efecto:** inmediato para marcaciones, reportes, el mapa en vivo, la app y los procesos programados. El calendario de un mes ya cerrado de esa empresa también cambia al instante, porque la bandera forma parte de la clave de su caché. La vista global del Super Admin (todas las empresas) puede tardar hasta 10 minutos.
- **Lo ya guardado no cambia:** apagar la bandera no recalcula jornadas que ya se guardaron (tardanza, horas). Solo afecta lo que se calcula o guarda desde ese momento.

## Qué demuestra que "apagada = igual que antes"

- `tests/Feature/ScheduleResolutionCharacterizationTest.php` fija el comportamiento anterior de las ocho copias de "turno del día". Pasa sin cambios en sus expectativas con el resolver en modo legacy.
- `tests/Feature/ScheduleResolutionV2Test.php` corre el mismo escenario con la bandera encendida. Las pruebas que no sobrescribe pasan igual en los dos modos; las sobrescritas muestran cada cambio.

## Plan de retiro

Condición: conformidad de QA (sección 7 del informe) **y** dos semanas con `schedule_v2` encendida en producción sin incidencias.

En un solo commit:
1. Borrar `LEGACY_FIRST_WORKING`, `LEGACY_LATEST` y la rama legacy de `ScheduleResolver::resolve()`, y el argumento de estrategia en los llamadores.
2. Borrar `ScheduleResolutionCharacterizationTest` y convertir `ScheduleResolutionV2Test` en la prueba de referencia.
3. Quitar `schedule_v2` de `AttendanceFlag::FLAGS` y de la clave de caché del calendario.
4. La clave `flags.schedule_v2` que quede en `tenants.settings` no molesta. Se puede limpiar con el mismo comando antes del commit.
