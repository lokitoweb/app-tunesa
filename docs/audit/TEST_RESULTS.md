# Resultados de pruebas

Solo se registran pruebas que se ejecutaron de verdad, con fecha y entorno.

| Fecha | Entorno | Suite | Resultado |
|---|---|---|---|
| 09/11/2026 | Local Windows, PHP 8.2.12, SQLite, `php artisan test --parallel` | Backend completo | 797 pruebas, 11 573 aserciones, 0 fallos (tras el bloque 0/1) |
| 09/11/2026 | Local Windows, PHP 8.2.12, SQLite, `php artisan test --parallel` | Backend completo | 798 pruebas, 11 595 aserciones, 0 fallos (tras recuperación con enlace) |
| 09/11/2026 | GitHub Actions (commit `a06d685`) | Backend (SQLite + MySQL), contrato app↔API, APK | Todo en verde (#278, #191, #155) |
| 09/11/2026 | Local, Flutter 3.47 | `privacy_test.dart`, `history_screen_test.dart`, `team_notifications_test.dart`, `widget_test.dart` y relacionadas | Todas en verde |

## Pruebas agregadas en esta auditoría

- `ProductionReadinessTest::test_detects_secrets_reused_from_the_public_git_history`
- `CredentialPolicyTest::test_dni_initial_password_cannot_be_skipped_and_stops_working_after_the_change`
- `AttendancePunchLogTest::test_punch_without_gps_accuracy_is_kept_but_flagged_for_review`

## Vacíos de cobertura conocidos

- Sin pruebas de iOS (ni unitarias de plataforma ni de punta a punta).
- Sin pruebas de carga recientes con volumen de producción.
- Casos límite de geocerca (borde exacto, coordenadas inválidas) y de turnos nocturnos con entrada después de medianoche.
