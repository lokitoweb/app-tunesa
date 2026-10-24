// Pruebas de punta a punta de la app contra el backend real (sin emulador).
//
// Usan los mismos repositorios y modelos de la app, así cualquier cambio de
// contrato en la API (ruta, campo, formato) rompe aquí antes que en el teléfono.
//
// Requiere el backend sembrado (php artisan migrate:fresh --seed) y corriendo:
//   flutter test test/e2e --dart-define=E2E=true --dart-define=API_BASE_URL=http://127.0.0.1:8000
// Sin E2E=true se saltan (flutter test normal no necesita el backend).
// En CI lo corre el workflow "App ↔ API: pruebas de punta a punta".
library;

import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_endpoints.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/features/account/data/account_repository.dart';
import 'package:tunesa_mobile/features/attendance/data/attendance_repository.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/domain/punch.dart';
import 'package:tunesa_mobile/features/auth/data/auth_repository.dart';
import 'package:tunesa_mobile/features/notifications/data/notification_repository.dart';
import 'package:tunesa_mobile/features/payslips/data/payslip_repository.dart';
import 'package:tunesa_mobile/features/profile/data/profile_repository.dart';
import 'package:tunesa_mobile/features/requests/data/leave_repository.dart';
import 'package:tunesa_mobile/features/requests/domain/models.dart';
import 'package:tunesa_mobile/features/team/data/team_repository.dart';
import 'package:tunesa_mobile/features/training/data/training_repository.dart';
import 'package:tunesa_mobile/features/treasury/data/treasury_repository.dart';

/// Selfie de prueba (JPEG 64×64).
final _selfie = PunchPhoto(Uint8List.fromList(base64Decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgAQABAAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A26KKK809YKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA//Z')), 'image/jpeg');

/// Sede Principal - San Isidro del DatabaseSeeder (radio 100 m).
const _lat = -12.09418;
const _lng = -77.03560;

/// Una sesión de la app (almacenamiento propio + cliente HTTP) para un usuario.
class _Session {
  _Session._(this.storage, this.api);

  final SessionStorage storage;
  final ApiClient api;

  static Future<_Session> login(String email, String password) async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    final storage = SessionStorage(await SharedPreferences.getInstance());
    final api = ApiClient(storage, appVersion: '9.9.9');
    final result = await AuthRepository(api, storage).login(email: email, password: password);
    expect(result.tenantSlug, 'empresa-demo');
    await storage.saveTenant(result.tenantSlug, const {});
    return _Session._(storage, api);
  }
}

const _e2e = bool.fromEnvironment('E2E');

void main() {
  if (!_e2e) {
    test('pruebas contra el backend (activar con --dart-define=E2E=true)', () {}, skip: 'Necesita el backend corriendo');
    return;
  }

  group('Arranque', () {
    test('configuración pública de la plataforma y de la empresa', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});
      final storage = SessionStorage(await SharedPreferences.getInstance());
      final api = ApiClient(storage);

      final platform = AppConfig.fromJson(await api.getAnonymous(ApiEndpoints.appConfig));
      expect(platform.mobile.minSupportedVersion, isNotNull);

      final tenant = AppConfig.fromJson(
        await api.getAnonymous(ApiEndpoints.appConfig, query: {'tenant_slug': 'empresa-demo'}),
      );
      expect(tenant.tenant?.slug, 'empresa-demo');
    });

    test('credenciales malas: 4xx con mensaje en español', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});
      final storage = SessionStorage(await SharedPreferences.getInstance());
      final api = ApiClient(storage);
      await expectLater(
        AuthRepository(api, storage).login(email: 'ana.torres@empresa-demo.com', password: 'mala'),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', inInclusiveRange(400, 499))),
      );
    });
  });

  group('Colaborador (Ana)', () {
    late _Session s;

    setUpAll(() async => s = await _Session.login('ana.torres@empresa-demo.com', 'Employee2024!'));

    test('perfil, sesiones y preferencias de avisos', () async {
      final me = await AuthRepository(s.api, s.storage).me();
      expect(me.email, 'ana.torres@empresa-demo.com');

      final account = AccountRepository(s.api);
      expect(await account.sessions(), isNotEmpty);
      final prefs = await account.preferences();
      expect(prefs, isNotEmpty);
      final first = prefs.first;
      final updated = await account.setPreference(first.key, !first.enabled);
      expect(updated.firstWhere((p) => p.key == first.key).enabled, !first.enabled);
      await account.setPreference(first.key, first.enabled);

      await s.api.post(ApiEndpoints.refreshFcm, data: {'fcm_token': 'e2e-token-ana'});
    });

    test('inicio: turno de hoy, sedes y marcaciones completas', () async {
      final repo = AttendanceRepository(s.api, s.storage, appVersion: '9.9.9');
      final branches = await repo.myBranches();
      expect(branches, isNotEmpty);

      var today = await repo.today();
      expect(today.actions, contains(ClockAction.clockIn));

      for (final action in [ClockAction.clockIn, ClockAction.breakStart, ClockAction.breakEnd, ClockAction.clockOut]) {
        final capture = PunchCapture(
          action: action,
          latitude: _lat,
          longitude: _lng,
          accuracy: 8,
          isMocked: false,
          deviceTime: DateTime.now(),
          photo: _selfie,
        );
        final outcome = await repo.sendPunch(capture);
        expect(outcome.duplicate, isFalse, reason: action.name);

        // Reenvío (misma captura): el servidor responde duplicado, no error
        final again = await repo.sendPunch(capture);
        expect(again.duplicate, isTrue, reason: '${action.name} duplicado');
      }

      today = await repo.today();
      expect(today.attendance, isNotNull);
      expect(today.attendance!.clockOut, isNotNull);

      final now = DateTime.now();
      final history = await repo.history(month: now.month, year: now.year);
      expect(history.items, isNotEmpty);

      await repo.submitJustification(history.items.first.id, 'Prueba de punta a punta');
    });

    test('marcación fuera de la sede se rechaza con mensaje claro', () async {
      final repo = AttendanceRepository(s.api, s.storage, appVersion: '9.9.9');
      await expectLater(
        repo.sendPunch(PunchCapture(
          action: ClockAction.clockIn,
          latitude: -12.2,
          longitude: -77.2,
          accuracy: 8,
          isMocked: false,
          deviceTime: DateTime.now(),
          photo: _selfie,
        )),
        throwsA(isA<ApiException>().having((e) => e.message, 'message', isNotEmpty)),
      );
    });

    test('solicitudes: crear, listar y cancelar', () async {
      final repo = LeaveRepository(s.api);
      final start = DateTime.now().add(const Duration(days: 30));
      await repo.create(type: LeaveType.vacation, start: start, end: start.add(const Duration(days: 1)), reason: 'E2E');
      final page = await repo.list();
      final mine = page.items.firstWhere((r) => r.reason == 'E2E' && r.isPending);
      await repo.cancel(mine.id);
      final after = await repo.list();
      expect(after.items.firstWhere((r) => r.id == mine.id).isPending, isFalse);
    });

    test('legajo: configuración, perfil, contacto, tallas y documentos', () async {
      final repo = ProfileRepository(s.api);
      final config = await repo.config();
      final file = await repo.profile();
      expect(file, isNotNull);

      await repo.updateContact({'phone': '987654321'});

      if (config.uniforms && config.editUniforms) {
        await repo.updateUniformSizes({'shirt_size': 'M', 'pants_size': '32', 'shoe_size': '40', 'shoe_type': 'safety'});
      }

      if (config.documents && config.uploadDocuments) {
        final type = config.requiredDocuments.isEmpty ? 'dni' : config.requiredDocuments.first;
        await repo.uploadDocument(type: type, fileName: 'dni.jpg', bytes: _selfie.bytes);
        final uploaded = (await repo.profile()).documents.firstWhere((d) => d.originalName == 'dni.jpg');
        expect(await repo.documentBytes(uploaded.id), isNotEmpty);
        await repo.deleteDocument(uploaded.id);
      }
    });

    test('boletas, capacitaciones y notificaciones', () async {
      final payslips = PayslipRepository(s.api);
      for (final p in (await payslips.list()).take(1)) {
        expect(await payslips.pdf(p.id), isNotEmpty);
      }

      final trainings = TrainingRepository(s.api);
      for (final t in (await trainings.list()).take(1)) {
        final detail = await trainings.detail(t.id);
        expect(detail.summary.id, t.id);
      }

      final notifications = NotificationRepository(s.api);
      final page = await notifications.list();
      if (page.items.isNotEmpty) await notifications.markRead(page.items.first.id);
      await notifications.markAllRead();
      expect(await notifications.unreadCount(), 0);
    });
  });

  group('Supervisor', () {
    late _Session supervisor;

    setUpAll(() async => supervisor = await _Session.login('supervisor@empresa-demo.com', 'Supervisor2024!'));

    test('aprueba la solicitud de su equipo y ve la asistencia en vivo', () async {
      final ana = await _Session.login('ana.torres@empresa-demo.com', 'Employee2024!');
      final start = DateTime.now().add(const Duration(days: 60));
      await LeaveRepository(ana.api)
          .create(type: LeaveType.vacation, start: start, end: start, reason: 'E2E aprobación');

      final team = TeamRepository(supervisor.api);
      final pending = await team.pendingApprovals();
      final item = pending.items.firstWhere((a) => a.request.reason == 'E2E aprobación');
      await team.approve(item.request.id, notes: 'OK');

      final overview = await team.overview();
      expect(overview.members, isNotEmpty);

      final mine = await LeaveRepository(ana.api).list();
      expect(mine.items.firstWhere((r) => r.id == item.request.id).status, 'approved');
    });
  });

  group('Firma APP (autorización de pagos)', () {
    test('la persona designada ve la OP programada y la autoriza; nadie más puede', () async {
      final manager = await _Session.login('gerencia@empresa-demo.com', 'Gerencia2024!');
      expect((await AuthRepository(manager.api, manager.storage).me()).canAuthorizePayments, isTrue);

      final treasury = TreasuryRepository(manager.api);
      final pending = await treasury.pending();
      final item = pending.firstWhere((p) => p.detail == 'Llantas para la flota');
      expect(item.order, startsWith('OC-'));
      expect(item.amount, 1250);
      expect(item.supplierName, 'Llantas del Norte S.A.C.');
      expect(item.amountLabel, 'S/ 1,250.00');

      // Contabilidad programa y paga, pero no autoriza (segregación de funciones)
      final accountant = await _Session.login('contabilidad@empresa-demo.com', 'Contabilidad2024!');
      expect((await AuthRepository(accountant.api, accountant.storage).me()).canAuthorizePayments, isFalse);
      await expectLater(TreasuryRepository(accountant.api).pending(), throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 403)));
      await expectLater(TreasuryRepository(accountant.api).authorize(item.id), throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 403)));

      await treasury.authorize(item.id);
      expect((await treasury.pending()).where((p) => p.id == item.id), isEmpty);
      // Ya autorizada: el servidor lo rechaza con un mensaje para mostrar
      await expectLater(
        treasury.authorize(item.id),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 422).having((e) => e.message, 'message', isNotEmpty)),
      );
    });
  });

  group('Privacidad (Google Play)', () {
    test('la política es pública y se puede pedir eliminar la cuenta', () async {
      SharedPreferences.setMockInitialValues({});
      FlutterSecureStorage.setMockInitialValues({});
      final storage = SessionStorage(await SharedPreferences.getInstance());
      final config = AppConfig.fromJson(await ApiClient(storage).getAnonymous(ApiEndpoints.appConfig));
      final privacy = config.legal.privacyPolicyUrl;
      expect(privacy, isNotNull);
      final page = await HttpClient().getUrl(Uri.parse(privacy!)).then((r) => r.close());
      expect(page.statusCode, 200);

      final rosa = await _Session.login('rosa.huaman@empresa-demo.com', 'Employee2024!');
      expect(await AccountRepository(rosa.api).requestDeletion(reason: 'E2E'), contains('30 días'));
    });
  });

  group('Cierre de sesión', () {
    test('logout invalida el token', () async {
      final s = await _Session.login('luis.mendoza@empresa-demo.com', 'Employee2024!');
      final token = await s.storage.readToken();
      await AuthRepository(s.api, s.storage).logout();

      FlutterSecureStorage.setMockInitialValues({'auth_token': token!});
      final stale = SessionStorage(await SharedPreferences.getInstance());
      await expectLater(
        AuthRepository(ApiClient(stale), stale).me(),
        throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 401)),
      );
    });
  });
}
