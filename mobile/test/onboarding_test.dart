import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/features/auth/domain/models.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';
import 'package:tunesa_mobile/features/onboarding/data/onboarding_repository.dart';
import 'package:tunesa_mobile/features/onboarding/domain/models.dart';
import 'package:tunesa_mobile/features/onboarding/presentation/onboarding_providers.dart';
import 'package:tunesa_mobile/features/onboarding/presentation/onboarding_screen.dart';

Map<String, dynamic> _summary({String status = 'pending', bool canSubmit = false, bool docsDone = false}) => {
      'status': status,
      'can_punch': status == 'approved',
      'can_submit': canSubmit,
      'next_stage': canSubmit ? null : 'personal_data',
      'rejection_reason': status == 'rejected' ? 'La foto del DNI está borrosa' : null,
      'stages': [
        {'key': 'personal_data', 'title': 'Datos personales', 'description': 'Dirección y cuenta.', 'required': true, 'status': canSubmit ? 'completed' : 'pending', 'missing': canSubmit ? [] : ['Dirección']},
        {'key': 'uniforms', 'title': 'Tallas de uniforme', 'description': 'Tallas.', 'required': true, 'status': 'completed', 'missing': []},
        {'key': 'documents', 'title': 'Documentos', 'description': 'Legajo.', 'required': true, 'status': docsDone || canSubmit ? 'completed' : 'pending', 'missing': docsDone || canSubmit ? [] : ['Documento de identidad']},
        {'key': 'induction', 'title': 'Inducción', 'description': 'Curso.', 'required': true, 'status': 'not_applicable', 'missing': []},
      ],
    };

class _FakeRepo extends OnboardingRepository {
  _FakeRepo(super.api, this.current);

  Map<String, dynamic> current;
  int submits = 0;

  @override
  Future<OnboardingSummary> summary() async => OnboardingSummary.fromJson(current);

  @override
  Future<(OnboardingSummary, String)> submit() async {
    submits++;
    current = _summary(status: 'submitted', canSubmit: false, docsDone: true)..['next_stage'] = null;
    return (OnboardingSummary.fromJson(current), 'Enviamos tu incorporación a RRHH.');
  }
}

Future<ApiClient> _api() async {
  SharedPreferences.setMockInitialValues({});
  return ApiClient(SessionStorage(await SharedPreferences.getInstance()));
}

/// Teléfono alto: el ListView construye todas las etapas y el botón de envío.
void _tallScreen(WidgetTester tester) {
  tester.view.physicalSize = const Size(1200, 4800);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
}

void main() {
  test('parsea el resumen y oculta las etapas que no aplican', () {
    final s = OnboardingSummary.fromJson(_summary());
    expect(s.status, OnboardingStatus.pending);
    expect(s.canPunch, isFalse);
    expect(s.visibleStages.map((e) => e.key), ['personal_data', 'uniforms', 'documents']);
    expect(s.doneCount, 1);
    expect(s.stages.first.missing, ['Dirección']);
    expect(OnboardingSummary.fromJson(_summary(status: 'approved')).canPunch, isTrue);
    expect(OnboardingStatus.fromApi(null), OnboardingStatus.approved);
  });

  test('la sesión guarda el estado de incorporación (respaldo sin conexión)', () {
    final user = AppUser.fromJson({'id': 1, 'full_name': 'Rosa Q', 'first_name': 'Rosa', 'email': 'r@x.pe', 'role': 'employee', 'onboarding_status': 'pending'});
    expect(user.onboardingStatus, 'pending');
    expect(AppUser.fromJson(user.toJson()).onboardingStatus, 'pending');
    expect(AppUser.fromJson({'id': 1, 'role': 'employee'}).onboardingStatus, 'approved');
  });

  test('los avisos de incorporación abren el asistente; el de RRHH no', () {
    expect(AppNotification.routeFor('onboarding.approved'), '/onboarding');
    expect(AppNotification.routeFor('onboarding.rejected'), '/onboarding');
    expect(AppNotification.routeFor('onboarding.submitted'), isNull);
  });

  test('un servidor sin el módulo (404) cuenta como aprobada', () async {
    final api = await _api();
    final container = ProviderContainer(overrides: [
      onboardingRepositoryProvider.overrideWithValue(_ThrowingRepo(api)),
    ]);
    addTearDown(container.dispose);
    final s = await container.read(onboardingProvider.future);
    expect(s.canPunch, isTrue);
  });

  testWidgets('el asistente muestra lo que falta y envía a RRHH cuando está completo', (tester) async {
    _tallScreen(tester);
    final repo = _FakeRepo(await _api(), _summary());
    await tester.pumpWidget(ProviderScope(
      overrides: [onboardingRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(home: OnboardingScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Completa tu incorporación para empezar a marcar'), findsOneWidget);
    expect(find.text('Falta: Dirección'), findsOneWidget);
    expect(find.byKey(const Key('onboarding-stage-induction')), findsNothing);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const Key('onboarding-submit'))).onPressed, isNull);

    repo.current = _summary(canSubmit: true);
    ProviderScope.containerOf(tester.element(find.byType(OnboardingScreen))).invalidate(onboardingProvider);
    await tester.pumpAndSettle();

    final submit = find.byKey(const Key('onboarding-submit'));
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(repo.submits, 1);
    expect(find.text('RRHH está revisando tu incorporación'), findsOneWidget);
    expect(find.byKey(const Key('onboarding-submit')), findsNothing);
  });

  testWidgets('una incorporación observada muestra el motivo y permite reenviar', (tester) async {
    _tallScreen(tester);
    final repo = _FakeRepo(await _api(), _summary(status: 'rejected', canSubmit: true));
    await tester.pumpWidget(ProviderScope(
      overrides: [onboardingRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(home: OnboardingScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Motivo: La foto del DNI está borrosa'), findsOneWidget);
    expect(find.text('Volver a enviar a RRHH'), findsOneWidget);
  });
}

class _ThrowingRepo extends OnboardingRepository {
  _ThrowingRepo(super.api);

  @override
  Future<OnboardingSummary> summary() async => throw const ApiException('No encontrado', statusCode: 404);
}
