import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/auth/presentation/change_password_screen.dart';
import 'package:tunesa_mobile/features/profile/domain/models.dart';
import 'package:tunesa_mobile/features/profile/presentation/profile_screen.dart';
import 'package:tunesa_mobile/features/profile/presentation/uniforms_screen.dart';

Map<String, dynamic> _profileJson({Map<String, dynamic>? modules, Map<String, dynamic>? permissions}) => {
      'user': {'full_name': 'Ana Torres', 'email': 'ana@demo.pe', 'position': 'Cajera', 'employee_code': 'EMP-001', 'hire_date': '2024-01-15'},
      'profile': {
        'contact': {'address': null, 'emergency_contact_phone': null},
        'payment': {
          'bank_name': 'BCP',
          'bank_account_type': 'payroll',
          'bank_account_currency': 'PEN',
          'bank_account_number': '••••••8012',
          'interbank_code': null,
          'status': 'pending',
          'status_label': 'Por verificar',
        },
        'pension': {'pension_system': 'afp', 'pension_fund': 'AFP Integra', 'pension_code': '••••••EA1'},
        'employment': {'labor_regime_label': 'Régimen general', 'hire_date': '2024-01-15'},
      },
      'uniforms': {
        'sizes': {'shirt_size': 'M', 'pants_size': '32', 'shoe_size': null},
        'deliveries': [
          {'item': 'shirt', 'item_label': 'Polo / camisa', 'size': 'M', 'quantity': 2, 'delivered_at': '2026-09-01'},
        ],
      },
      'documents': {
        'items': [
          {'id': 7, 'type': 'dni', 'type_label': 'Documento de identidad', 'status': 'rejected', 'rejection_reason': 'Ilegible', 'original_name': 'dni.jpg', 'mime_type': 'image/jpeg'},
        ],
        'required': ['dni', 'cv'],
        'missing': ['dni', 'cv'],
      },
      'onboarding': {'steps': {'payment_info': true, 'documents': false, 'uniforms': false, 'contact': false}, 'progress': 25},
      'config': {
        'modules': {'payslips': true, 'documents': true, 'payment_info': true, 'uniforms': true, ...?modules},
        'permissions': {'upload_documents': true, 'edit_payment_info': true, 'edit_uniforms': true, ...?permissions},
        'required_documents': ['dni', 'cv'],
        'max_upload_mb': 5,
      },
      'catalogs': {
        'document_types': {'dni': 'Documento de identidad', 'cv': 'Currículum (CV)'},
        'shirt_sizes': ['S', 'M', 'L'],
        'pants_sizes': ['30', '32', '34'],
        'shoe_sizes': ['40', '41', '42'],
        'shoe_types': {'safety': 'Calzado de seguridad', 'formal': 'Zapatos de vestir'},
      },
    };

Future<void> _pump(WidgetTester tester, Widget child, {ThemeData? theme}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  await tester.pumpWidget(ProviderScope(
    overrides: [sharedPreferencesProvider.overrideWithValue(prefs)],
    child: MaterialApp(theme: theme ?? AppTheme.light(), home: Scaffold(body: child)),
  ));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  group('Modelos del legajo', () {
    test('EmployeeFile interpreta perfil, documentos, tallas y flags', () {
      final f = EmployeeFile.fromJson(_profileJson());

      expect(f.fullName, 'Ana Torres');
      expect(f.payment.accountNumberMasked, '••••••8012');
      expect(f.payment.isVerified, isFalse);
      expect(f.payment.isComplete, isTrue);
      expect(f.sizes?.isComplete, isFalse);
      expect(f.deliveries.single.quantity, 2);
      expect(f.currentDocument('dni')?.status, DocumentStatus.rejected);
      expect(f.currentDocument('dni')?.canDelete, isTrue);
      expect(f.missingDocuments, ['dni', 'cv']);
      expect(f.progress, 25);
      expect(f.config.maxUploadMb, 5);
    });

    test('CompanyConfig: lo no enviado queda habilitado y los flags apagados se respetan', () {
      final c = CompanyConfig.fromJson({
        'modules': {'payslips': false, 'uniforms': false},
        'permissions': {'edit_payment_info': false},
      });
      expect(c.payslips, isFalse);
      expect(c.uniforms, isFalse);
      expect(c.documents, isTrue);
      expect(c.editPaymentInfo, isFalse);
      expect(c.uploadDocuments, isTrue);
      expect(c.hasEmployeeFile, isTrue);
    });
  });

  group('Mi perfil: renderizado condicional', () {
    testWidgets('muestra todas las secciones habilitadas', (tester) async {
      await _pump(tester, ProfileBody(file: EmployeeFile.fromJson(_profileJson())));

      expect(find.byKey(const Key('section-documents')), findsOneWidget);
      expect(find.byKey(const Key('section-payment')), findsOneWidget);
      expect(find.byKey(const Key('section-uniforms')), findsOneWidget);
      expect(find.text('25%'), findsOneWidget);
      expect(find.textContaining('Falta: Documento de identidad'), findsOneWidget);
    });

    testWidgets('oculta las secciones que la empresa desactivó', (tester) async {
      final file = EmployeeFile.fromJson(_profileJson(modules: {'documents': false, 'uniforms': false}));
      await _pump(tester, ProfileBody(file: file));

      expect(find.byKey(const Key('section-documents')), findsNothing);
      expect(find.byKey(const Key('section-uniforms')), findsNothing);
      expect(find.byKey(const Key('section-payment')), findsOneWidget);
    });

    testWidgets('se ve bien en modo oscuro', (tester) async {
      await _pump(tester, ProfileBody(file: EmployeeFile.fromJson(_profileJson())), theme: AppTheme.dark());
      final context = tester.element(find.byType(ProfileBody));
      expect(Theme.of(context).brightness, Brightness.dark);
      expect(find.text('Mi Legajo'), findsOneWidget);
    });
  });

  group('Dotación', () {
    testWidgets('el botón se habilita al elegir las 3 tallas', (tester) async {
      await _pump(tester, UniformSizePicker(file: EmployeeFile.fromJson(_profileJson())));

      expect(find.text('Elige las 3 tallas'), findsOneWidget);
      await tester.tap(find.text('41'));
      await tester.pumpAndSettle();
      expect(find.text('Guardar tallas'), findsOneWidget);
    });

    testWidgets('sin permiso de edición las tallas son solo lectura', (tester) async {
      final file = EmployeeFile.fromJson(_profileJson(permissions: {'edit_uniforms': false}));
      await _pump(tester, UniformSizePicker(file: file));

      expect(find.text('Tus tallas las registra RRHH.'), findsOneWidget);
      expect(find.text('Guardar tallas'), findsNothing);
      expect(find.text('Elige las 3 tallas'), findsNothing);
    });
  });

  test('la nueva contraseña exige mayúsculas, minúsculas y números', () {
    expect(ChangePasswordScreenValidators.strong('corta'), isNotNull);
    expect(ChangePasswordScreenValidators.strong('sinnumeros'), isNotNull);
    expect(ChangePasswordScreenValidators.strong('sinmayus123'), isNotNull);
    expect(ChangePasswordScreenValidators.strong('Segura2026'), isNull);
  });
}
