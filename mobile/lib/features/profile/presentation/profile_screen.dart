import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/config/version_gate.dart';
import '../../../core/theme/app_theme.dart';
import '../../app_update/presentation/update_screen.dart';
import '../../../core/theme/theme_mode_controller.dart';
import '../../../core/widgets/brand_hero.dart';
import '../../../core/widgets/async_views.dart';
import 'profile_avatar.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../attendance/presentation/offline_sync.dart';
import '../domain/models.dart';
import 'profile_providers.dart';
import '../../../core/widgets/page_padding.dart';
import '../../ethics/presentation/ethics_providers.dart';
import '../../training/presentation/training_providers.dart';
import '../../benefits/presentation/benefit_providers.dart';
import '../../birthdays/presentation/birthday_providers.dart';
import '../../company_docs/presentation/company_document_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// "Mi perfil": foto, avance de la ficha y acceso a Mis documentos,
/// Información de pagos, Uniforme y tallas y Datos de contacto. Cada sección
/// aparece solo si la empresa la habilitó.
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
        child: file.whenSkeleton(
          loading: () => const SkeletonProfile(),
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

    return ListView(
      padding: pagePadding(context, top: 12),
      children: [
        _Header(file: file),
        const SizedBox(height: 16),
        if (c.hasEmployeeFile) ...[
          _OnboardingCard(file: file),
          const SizedBox(height: 20),
        ],
        const _GroupTitle('Mi ficha'),
        if (c.documents)
          _SectionTile(
            key: const Key('section-documents'),
            icon: Icons.folder_shared_outlined,
            title: 'Mis documentos',
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
            title: 'Información de pagos',
            subtitle: file.payment.isComplete
                ? '${file.payment.bankName} ${file.payment.accountNumberMasked ?? ''} · ${file.payment.statusLabel}'
                : 'Tu cuenta para el sueldo y tu AFP / ONP',
            done: file.payment.isVerified,
            onTap: () => context.push('/profile/payment'),
          ),
        if (c.uniforms)
          _SectionTile(
            key: const Key('section-uniforms'),
            icon: Icons.checkroom_outlined,
            title: 'Uniforme y tallas',
            subtitle: file.sizes?.isComplete == true
                ? 'Polo ${file.sizes!.shirt} · Pantalón ${file.sizes!.pants} · Calzado ${file.sizes!.shoes}'
                : 'Elige tus tallas de uniforme',
            done: file.sizes?.isComplete == true,
            onTap: () => context.push('/profile/uniforms'),
          ),
        _SectionTile(
          icon: Icons.contact_emergency_outlined,
          title: 'Datos de contacto',
          subtitle: file.emergencyContactPhone != null
              ? 'Emergencia: ${file.emergencyContactName ?? ''} · ${file.emergencyContactPhone}'
              : 'Tu dirección y un contacto de emergencia',
          done: file.steps['contact'] == true,
          onTap: () => context.push('/profile/contact'),
        ),
        _SectionTile(
          key: const Key('section-overtime'),
          icon: Icons.more_time_rounded,
          title: 'Mis horas extra',
          subtitle: 'Horas extra del mes y su aprobación',
          onTap: () => context.push('/overtime'),
        ),
        const _TrainingsTile(),
        const _CommunityTiles(),
        const _EthicsTile(),
        const SizedBox(height: 10),
        const _GroupTitle('Cuenta y ajustes'),
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
    final initials = file.fullName.trim().split(RegExp(r'\s+')).take(2).map((p) => p.isEmpty ? '' : p[0]).join().toUpperCase();

    // Mismos colores que el saludo de Inicio: azul de la marca y acento verde
    return BrandHero(
      child: Row(children: [
        Container(
          padding: const EdgeInsets.all(2.5),
          decoration: BoxDecoration(shape: BoxShape.circle, color: BrandHero.ringColor(context)),
          child: ProfileAvatar(initials: initials),
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(file.fullName, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            Text(
              [file.position, file.employeeCode].whereType<String>().join(' · '),
              style: TextStyle(color: Colors.white.withValues(alpha: 0.82)),
            ),
            if (file.hireDate != null)
              Text('Ingreso: ${file.hireDate}', style: TextStyle(color: Colors.white.withValues(alpha: 0.82), fontSize: 13)),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(6)),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.photo_camera_outlined, size: 13, color: AppTheme.success.withValues(alpha: 0.95)),
                const SizedBox(width: 6),
                const Flexible(child: Text('Toca tu foto para cambiarla', style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600))),
              ]),
            ),
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

/// Acceso a "Capacitaciones" (si la empresa usa el módulo).
class _TrainingsTile extends ConsumerWidget {
  const _TrainingsTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(trainingsEnabledProvider)) return const SizedBox.shrink();
    final list = ref.watch(trainingsProvider).value;
    final pending = list?.where((t) => !t.isCompleted).length ?? 0;
    return _SectionTile(
      key: const Key('section-trainings'),
      icon: Icons.school_outlined,
      title: 'Capacitaciones',
      subtitle: list == null
          ? 'Cursos asignados y tus constancias'
          : pending > 0
              ? '$pending por completar'
              : list.isEmpty
                  ? 'Sin cursos asignados'
                  : 'Todas completadas',
      done: list != null && list.isNotEmpty && pending == 0,
      onTap: () => context.push('/trainings'),
    );
  }
}

/// Documentos de la empresa, beneficios y cumpleaños (según los módulos de la empresa).
class _CommunityTiles extends ConsumerWidget {
  const _CommunityTiles();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final docs = ref.watch(companyDocumentsEnabledProvider);
    final pending = docs ? (ref.watch(documentLibraryProvider).value?.pendingAcknowledgement ?? 0) : 0;

    return Column(children: [
      if (docs)
        _SectionTile(
          key: const Key('section-company-documents'),
          icon: Icons.folder_open_outlined,
          title: 'Documentos de la empresa',
          subtitle: pending > 0 ? '$pending por leer y confirmar' : 'Políticas, reglamentos y manuales',
          onTap: () => context.push('/company-documents'),
        ),
      if (ref.watch(benefitsEnabledProvider))
        _SectionTile(
          key: const Key('section-benefits'),
          icon: Icons.card_giftcard_outlined,
          title: 'Beneficios',
          subtitle: 'Descuentos, convenios y promociones',
          onTap: () => context.push('/benefits'),
        ),
      if (ref.watch(birthdaysEnabledProvider))
        _SectionTile(
          key: const Key('section-birthdays'),
          icon: Icons.cake_outlined,
          title: 'Cumpleaños',
          subtitle: 'Celebra a tus compañeros',
          onTap: () => context.push('/birthdays'),
        ),
    ]);
  }
}

class _EthicsTile extends ConsumerWidget {
  const _EthicsTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(ethicsEnabledProvider)) return const SizedBox.shrink();
    return _SectionTile(
      key: const Key('section-ethics'),
      icon: Icons.shield_outlined,
      title: 'Canal ético',
      subtitle: 'Denuncia de forma anónima acoso, fraude u otras faltas',
      onTap: () => context.push('/ethics'),
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
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 12, 14),
            child: Row(children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: scheme.primary.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: scheme.primary),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(height: 3),
                  Text(subtitle,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant, height: 1.3)),
                ]),
              ),
              const SizedBox(width: 8),
              if (done) const Icon(Icons.check_circle_rounded, color: Colors.green, size: 22),
              Icon(Icons.chevron_right_rounded, color: scheme.onSurfaceVariant),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Título de un grupo de tarjetas ("Mi ficha", "Cuenta y ajustes").
class _GroupTitle extends StatelessWidget {
  const _GroupTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 8),
        child: Text(
          text.toUpperCase(),
          style: Theme.of(context).textTheme.labelMedium?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.8,
              ),
        ),
      );
}

class _AccountSection extends ConsumerWidget {
  const _AccountSection();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mode = ref.watch(themeModeProvider);
    final auth = ref.watch(authControllerProvider);
    final company = auth.user?.companyName ?? auth.branding?.companyName;
    // La empresa puede apagarlo en el panel (Configuración > App)
    final canChangePassword = ref.watch(appConfigProvider.select((s) => s.config.mobile.selfPasswordChange));

    return Card(
      child: Column(children: [
        if (company != null && company.isNotEmpty) ...[
          ListTile(
            leading: const Icon(Icons.apartment_rounded),
            title: const Text('Empresa'),
            subtitle: Text(company),
          ),
          const Divider(),
        ],
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(children: [
            const Icon(Icons.palette_outlined),
            const SizedBox(width: 12),
            const Expanded(child: Text('Apariencia', maxLines: 1, overflow: TextOverflow.ellipsis)),
            SegmentedButton<ThemeMode>(
              showSelectedIcon: false,
              style: const ButtonStyle(visualDensity: VisualDensity.compact, tapTargetSize: MaterialTapTargetSize.shrinkWrap),
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
        if (canChangePassword) ...[
          ListTile(
            key: const Key('account-password'),
            leading: const Icon(Icons.lock_reset_rounded),
            title: const Text('Cambiar contraseña'),
            subtitle: const Text('Actualiza tu clave de acceso'),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: () => context.push('/profile/password'),
          ),
          const Divider(),
        ],
        ListTile(
          key: const Key('account-devices'),
          leading: const Icon(Icons.devices_rounded),
          title: const Text('Mis dispositivos'),
          subtitle: const Text('Dónde está abierta tu cuenta'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.push('/profile/devices'),
        ),
        const Divider(),
        ListTile(
          key: const Key('account-notifications'),
          leading: const Icon(Icons.notifications_active_outlined),
          title: const Text('Notificaciones'),
          subtitle: const Text('Elige qué avisos te llegan'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.push('/profile/notifications'),
        ),
        const Divider(),
        const _AppVersionTile(),
        const Divider(),
        ListTile(
          key: const Key('account-privacy'),
          leading: const Icon(Icons.privacy_tip_outlined),
          title: const Text('Privacidad y datos'),
          subtitle: const Text('Política y eliminación de tu cuenta'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.push('/profile/privacy'),
        ),
        const Divider(),
        ListTile(
          leading: Icon(Icons.logout_rounded, color: Theme.of(context).colorScheme.error),
          title: Text(
            'Cerrar sesión',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(color: Theme.of(context).colorScheme.error),
          ),
          onTap: () async {
            // Antes de salir se intenta enviar lo marcado sin conexión; lo que quede
            // sigue guardado en el teléfono y se envía cuando este colaborador vuelva a entrar.
            final sync = ref.read(offlineSyncProvider.notifier);
            await sync.sync(force: true);
            if (!context.mounted) return;
            final ok = await confirmLogout(context, pending: ref.read(offlineSyncProvider).pending);
            if (ok == true) await ref.read(authControllerProvider.notifier).logout();
          },
        ),
      ]),
    );
  }
}

/// Versión instalada de la app. Si el panel publicó una más nueva
/// (`latest_version`), lo avisa y abre el enlace de descarga configurado.
class _AppVersionTile extends ConsumerWidget {
  const _AppVersionTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final version = ref.watch(appVersionProvider);
    final mobile = ref.watch(appConfigProvider.select((s) => s.config.mobile));
    final outdated = ref.watch(updateLevelProvider) != UpdateLevel.none;
    final latest = mobile.latestVersion;
    final url = mobile.updateUrl;

    return ListTile(
      key: const Key('account-version'),
      leading: const Icon(Icons.info_outline_rounded),
      title: const Text('Versión de la app'),
      subtitle: Text(outdated && latest != null ? '$version · disponible $latest' : version),
      trailing: outdated && url != null
          ? TextButton(onPressed: () => openUpdateLink(context, url), child: const Text('Actualizar'))
          : null,
    );
  }
}

/// Confirmación de cierre de sesión. Devuelve true si el usuario confirma.
/// Con [pending] > 0 avisa que hay marcaciones sin enviar.
Future<bool?> confirmLogout(BuildContext context, {int pending = 0}) {
  final scheme = Theme.of(context).colorScheme;
  return showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(
      icon: CircleAvatar(
        radius: 26,
        backgroundColor: scheme.errorContainer,
        child: Icon(Icons.logout_rounded, color: scheme.onErrorContainer),
      ),
      title: const Text('¿Cerrar sesión?'),
      content: Text(
        pending > 0
            ? 'Tienes ${pending == 1 ? '1 marcación' : '$pending marcaciones'} sin enviar por falta de conexión. '
                'Quedan guardadas en este teléfono y se enviarán cuando vuelvas a ingresar con tu cuenta.\n\n'
                'Tendrás que ingresar tu correo y contraseña para volver a entrar.'
            : 'Tendrás que ingresar tu correo y contraseña para volver a entrar.',
        textAlign: TextAlign.center,
      ),
      actionsPadding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      actions: [
        Row(children: [
          Expanded(
            child: OutlinedButton(
              style: OutlinedButton.styleFrom(
                minimumSize: const Size(0, 50),
                padding: const EdgeInsets.symmetric(horizontal: 12),
              ),
              onPressed: () => Navigator.pop(c, false),
              child: const _OneLine('Cancelar'),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton(
              style: FilledButton.styleFrom(
                backgroundColor: scheme.error,
                foregroundColor: scheme.onError,
                minimumSize: const Size(0, 50),
                padding: const EdgeInsets.symmetric(horizontal: 12),
              ),
              onPressed: () => Navigator.pop(c, true),
              child: const _OneLine('Cerrar sesión'),
            ),
          ),
        ]),
      ],
    ),
  );
}

/// Texto de botón en una sola línea: si no cabe (pantalla angosta o letra
/// grande del sistema) se achica en vez de partirse en dos.
class _OneLine extends StatelessWidget {
  const _OneLine(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => FittedBox(
        fit: BoxFit.scaleDown,
        child: Text(text, maxLines: 1, softWrap: false),
      );
}
