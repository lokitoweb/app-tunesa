import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'core/constants/api_constants.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/models/user_model.dart';
import 'features/auth/presentation/login_screen.dart';
import 'features/home/home_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Verificar persistencia de sesión del colaborador
  final prefs = await SharedPreferences.getInstance();
  final token = prefs.getString(ApiConstants.tokenKey);
  final userJsonStr = prefs.getString(ApiConstants.userKey);

  Widget initialScreen = const LoginScreen();

  if (token != null && token.isNotEmpty && userJsonStr != null) {
    try {
      final userMap = jsonDecode(userJsonStr);
      final user = UserModel.fromJson(userMap);
      initialScreen = HomeScreen(user: user);
    } catch (_) {
      // Si el formato local se corrompió, solicitar login de nuevo
      initialScreen = const LoginScreen();
    }
  }

  runApp(TuneSAMobileApp(initialScreen: initialScreen));
}

class TuneSAMobileApp extends StatelessWidget {
  final Widget initialScreen;

  const TuneSAMobileApp({super.key, required this.initialScreen});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'TuneSA Mobile',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      home: initialScreen,
    );
  }
}
