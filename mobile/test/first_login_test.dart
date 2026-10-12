import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/features/auth/domain/models.dart';
import 'package:tunesa_mobile/features/auth/presentation/change_password_screen.dart';

void main() {
  group('AppUser.initials', () {
    AppUser user(String full, String first) => AppUser.fromJson({'id': 1, 'role': 'employee', 'full_name': full, 'first_name': first});

    test('inicial del nombre y del apellido', () {
      expect(user('Rosa Quispe', 'Rosa').initials, 'RQ');
      expect(user('Rosa Elena Quispe Díaz', 'Rosa Elena').initials, 'RQ');
      expect(user('maría lópez', 'maría').initials, 'ML');
      expect(user('Cher', 'Cher').initials, 'C');
      expect(user('', '').initials, '?');
    });
  });

  testWidgets('cambio de contraseña: cada campo tiene su ojito para mostrar u ocultar', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();

    await tester.pumpWidget(ProviderScope(
      overrides: [sharedPreferencesProvider.overrideWithValue(prefs)],
      child: const MaterialApp(home: ChangePasswordScreen()),
    ));
    await tester.pump();

    EditableText field(int i) => tester.widgetList<EditableText>(find.byType(EditableText)).elementAt(i);

    for (var i = 0; i < 3; i++) {
      expect(field(i).obscureText, isTrue);
      await tester.tap(find.byKey(Key('toggle-password-$i')));
      await tester.pump();
      expect(field(i).obscureText, isFalse, reason: 'campo $i visible');
      expect(find.byTooltip('Ocultar contraseña'), findsNWidgets(i + 1));
    }

    await tester.tap(find.byKey(const Key('toggle-password-1')));
    await tester.pump();
    expect(field(1).obscureText, isTrue);
  });
}
