import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/theme_mode_controller.dart';
import '../../../core/widgets/async_views.dart';
import '../../auth/presentation/auth_controller.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

/// "Mi perfil": avance del onboarding y acceso a Mi Legajo, Datos de Pago y
/// Previsión, y Dotación. Cada sección aparece solo si la empresa la habilitó.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mi perfil')),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(companyConfigProvider);
          return ref.refresh(employeeFileProvider.future);
        },
        child: file.when(
          loading: () => const LoadingView(),
          error: (e, _) => ListView(children: [
            const SizedBox(height: 80),
            ErrorView(error: e, onRetry: () => ref.invalidate(employeeFileProvider)),
            const _AccountSection(),
          ]),
          data: (f) => ProfileBody(file: f),
        ),
      ),
    );
  }
}

/// Contenido de "Mi perfil" (separado para poder probarlo con datos fijos).
class ProfileBody extends StatelessWidget {
  const ProfileBody({super.key, required this.file});

  final EmployeeFile file;

  @override
  Widget build(BuildContext context) {
    final c = file.config;
    final scheme = Theme.of(context).colorScheme;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
      children: [
        _Header(file: file),
        const SizedBox(height: 16),
        if (c.hasEmployeeFile) ...[
          _OnboardingCard(file: file),
          const SizedBox(height: 16),
        ],
        if (c.documents)
          _SectionTile(
            key: const Key('section-documents'),
            icon: Icons.folder_shared_outlined,
            title: 'Mi Legajo',
            subtitle: file.missingDocuments.isEmpty
                ? 'Documentos completos'
                : 'Falta: ${file.missingDocuments.map((t) => file.catalogs.documentTypes[t] ?? t).join(', ')}',
            done: file.steps['documents'] == true,
            onTap: () => context.push('/profile/documents'),
          ),
        if (c.paymentInfo)
          _SectionTile(
            key: const Key('section-payment'),
            icon: Icons.account_balance_outlined,
            title: 'Datos de Pago y Previsión',
            subtitle: file.payment.isComplete
                ? '${file.payment.bankName} ${file.payment.accountNumberMasked ?? ''} · ${file.payment.statusLabel}'
                : 'Registra tu cuenta y tu AFP / ONP',
            done: file.payment.isVerified,
            onTap: () => context.push('/profile/payment'),
          ),
        if (c.uniforms)
          _SectionTile(
            key: const Key('section-uniforms'),
            icon: Icons.checkroom_outlined,
            title: 'Dotación',
            subtitle: file.sizes?.isComplete == true
                ? 'Polo ${file.sizes!.shirt} · Pantalón ${file.sizes!.pants} · Calzado ${file.sizes!.shoes}'
                : 'Elige tus tallas de uniforme',
            done: file.sizes?.isComplete == true,
            onTap: () => context.push('/profile/uniforms'),
          ),
        _SectionTile(
          icon: Icons.contact_emergency_outlined,
          title: 'Contacto y emergencia',
          subtitle: file.emergencyContactPhone != null
              ? '${file.emergencyContactName ?? ''} · ${file.emergencyContactPhone}'
              : 'Agrega tu dirección y un contacto de emergencia',
          done: file.steps['contact'] == true,
          onTap: () => context.push('/profile/contact'),
        ),
        const SizedBox(height: 8),
        Text('Cuenta', style: Theme.of(context).textTheme.titleSmall?.copyWith(color: scheme.onSurfaceVariant)),
        const SizedBox(height: 8),
        const _AccountSection(),
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.file});

  final EmployeeFile file;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final initials = file.fullName.trim().split(RegExp(r'\s+')).take(2).map((p) => p.isEmpty ? '' : p[0]).join().toUpperCase();

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          colors: [scheme.primary, Color.lerp(scheme.primary, scheme.tertiary, 0.55)!],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Row(children: [
        CircleAvatar(
          radius: 30,
          backgroundColor: scheme.onPrimary.withValues(alpha: 0.2),
          child: Text(initials, style: TextStyle(color: scheme.onPrimary, fontSize: 22, fontWeight: FontWeight.w700)),
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(file.fullName, style: TextStyle(color: scheme.onPrimary, fontSize: 19, fontWeight: FontWeight.w700)),
            const SizedBox(height: 2),
            Text(
              [file.position, file.employeeCode].whereType<String>().join(' · '),
              style: TextStyle(color: scheme.onPrimary.withValues(alpha: 0.85)),
            ),
            if (file.hireDate != null)
              Text('Ingreso: ${file.hireDate}', style: TextStyle(color: scheme.onPrimary.withValues(alpha: 0.85), fontSize: 13)),
          ]),
        ),
      ]),
    );
  }
}

class _OnboardingCard extends StatelessWidget {
  const _OnboardingCard({required this.file});

  final EmployeeFile file;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final done = file.progress >= 100;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(done ? Icons.verified_rounded : Icons.rocket_launch_outlined, color: done ? Colors.green : scheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(done ? 'Tu ficha está completa' : 'Completa tu ficha de ingreso',
                  style: Theme.of(context).textTheme.titleMedium),
            ),
            Text('${file.progress}%', style: Theme.of(context).textTheme.titleMedium?.copyWith(color: scheme.primary)),
          ]),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(value: file.progress / 100, minHeight: 8),
          ),
        ]),
      ),
    );
  }
}

class _SectionTile extends StatelessWidget {
  const _SectionTile({super.key, required this.icon, required this.title, required this.subtitle, required this.onTap, this.done = false});

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool done;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        child: ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          leading: Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, color: scheme.onPrimaryContainer),
          ),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis),
          trailing: Icon(done ? Icons.check_circle_rounded : Icons.chevron_right_rounded,
              color: done ? Colors.green : scheme.onSurfaceVariant),
          onTap: onTap,
        ),
      ),
    );
  }
}

class _AccountSection extends ConsumerWidget {
  const _AccountSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(themeModeProvider);

    return Card(
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(children: [
            const Icon(Icons.palette_outlined),
            const SizedBox(width: 12),
            const Expanded(child: Text('Apariencia')),
            SegmentedButton<ThemeMode>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: ThemeMode.light, icon: Icon(Icons.light_mode_outlined), tooltip: 'Claro'),
                ButtonSegment(value: ThemeMode.system, icon: Icon(Icons.brightness_auto_outlined), tooltip: 'Automático'),
                ButtonSegment(value: ThemeMode.dark, icon: Icon(Icons.dark_mode_outlined), tooltip: 'Oscuro'),
              ],
              selected: {mode},
              onSelectionChanged: (s) => ref.read(themeModeProvider.notifier).set(s.first),
            ),
          ]),
        ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.password_rounded),
          title: const Text('Cambiar contraseña'),
          onTap: () => context.push('/change-password'),
        ),
        const Divider(),
        ListTile(
          leading: Icon(Icons.logout_rounded, color: Theme.of(context).colorScheme.error),
          title: Text('Cerrar sesión', style: TextStyle(color: Theme.of(context).colorScheme.error)),
          onTap: () async {
            final ok = await showDialog<bool>(
              context: context,
              builder: (c) => AlertDialog(
                title: const Text('Cerrar sesión'),
                content: const Text('¿Seguro que quieres salir?'),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
                  FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Salir')),
                ],
              ),
            );
            if (ok == true) await ref.read(authControllerProvider.notifier).logout();
          },
        ),
      ]),
    );
  }
}
