import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/theme/app_theme.dart';
import '../attendance/presentation/attendance_history_screen.dart';
import '../attendance/presentation/clock_attendance_screen.dart';
import '../auth/models/user_model.dart';
import '../auth/presentation/login_screen.dart';
import '../payslips/presentation/payslips_screen.dart';

/// Contenedor Principal con Navegación Inferior (Shell Scaffold)
class HomeScreen extends StatefulWidget {
  final UserModel user;

  const HomeScreen({super.key, required this.user});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;

  late final List<Widget> _screens;

  @override
  void initState() {
    super.initState();
    _screens = [
      ClockAttendanceScreen(user: widget.user),
      const AttendanceHistoryScreen(),
      const PayslipsScreen(),
      _buildProfileScreen(),
    ];
  }

  Widget _buildProfileScreen() {
    return Scaffold(
      appBar: AppBar(title: const Text('Mi Perfil')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20.0),
          child: Column(
            children: [
              Center(
                child: Column(
                  children: [
                    Container(
                      width: 80,
                      height: 80,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(
                          colors: [AppTheme.primary, AppTheme.primaryDark],
                        ),
                      ),
                      child: Center(
                        child: Text(
                          widget.user.firstName.substring(0, 1) + widget.user.lastName.substring(0, 1),
                          style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: Colors.white),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      widget.user.fullName,
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: Colors.white),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      widget.user.position ?? 'Colaborador',
                      style: const TextStyle(fontSize: 13, color: AppTheme.textSecondary),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 28),

              // Tarjeta de Sucursales Asignadas
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppTheme.bgSurface,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: AppTheme.bgBorder),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Sucursales con Geocerca Autorizada', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                    const SizedBox(height: 10),
                    ...widget.user.branches.map((b) => Padding(
                          padding: const EdgeInsets.only(bottom: 8.0),
                          child: Row(
                            children: [
                              const Icon(Icons.location_on, size: 16, color: AppTheme.primaryLight),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text('${b.name} (${b.allowedRadiusMeters}m radio)', style: const TextStyle(fontSize: 13, color: Colors.white70)),
                              ),
                              if (b.isPrimary)
                                const Text('Principal', style: TextStyle(fontSize: 11, color: AppTheme.primaryLight, fontWeight: FontWeight.w600)),
                            ],
                          ),
                        )),
                  ],
                ),
              ),
              const SizedBox(height: 24),

              // Botón de Cerrar Sesión
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppTheme.danger,
                    side: const BorderSide(color: AppTheme.danger),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: () async {
                    final prefs = await SharedPreferences.getInstance();
                    await prefs.remove(ApiConstants.tokenKey);
                    await prefs.remove(ApiConstants.userKey);

                    if (!mounted) return;
                    Navigator.pushReplacement(
                      context,
                      MaterialPageRoute(builder: (_) => const LoginScreen()),
                    );
                  },
                  icon: const Icon(Icons.logout_rounded, size: 18),
                  label: const Text('Cerrar Sesión'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _currentIndex,
        onDestinationSelected: (idx) => setState(() => _currentIndex = idx),
        backgroundColor: AppTheme.bgSurface,
        indicatorColor: AppTheme.primary.withOpacity(0.2),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.radar_rounded, color: AppTheme.textSecondary),
            selectedIcon: Icon(Icons.radar_rounded, color: AppTheme.primaryLight),
            label: 'Marcación',
          ),
          NavigationDestination(
            icon: Icon(Icons.history_rounded, color: AppTheme.textSecondary),
            selectedIcon: Icon(Icons.history_rounded, color: AppTheme.primaryLight),
            label: 'Historial',
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_rounded, color: AppTheme.textSecondary),
            selectedIcon: Icon(Icons.receipt_long_rounded, color: AppTheme.primaryLight),
            label: 'Boletas',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_rounded, color: AppTheme.textSecondary),
            selectedIcon: Icon(Icons.person_rounded, color: AppTheme.primaryLight),
            label: 'Perfil',
          ),
        ],
      ),
    );
  }
}
