import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/brand_hero.dart';
import '../../announcements/presentation/announcement_home_card.dart';
import '../../announcements/presentation/announcement_providers.dart';
import '../../attendance/domain/models.dart';
import '../../attendance/presentation/attendance_providers.dart';
import '../../attendance/presentation/offline_sync.dart';
import '../../attendance/presentation/punch_board.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../notifications/presentation/notifications_providers.dart';
import '../../onboarding/presentation/onboarding_gate_card.dart';
import '../../onboarding/presentation/onboarding_providers.dart';
import '../../notifications/presentation/notifications_screen.dart';
import '../../team/presentation/team_providers.dart';
import '../../../core/widgets/page_padding.dart';
import '../../training/presentation/training_home_card.dart';
import '../../training/presentation/training_providers.dart';
import '../../../core/providers.dart';
import '../../birthdays/domain/models.dart';
import '../../birthdays/presentation/birthday_celebration.dart';
import '../../birthdays/presentation/birthday_home_card.dart';
import '../../birthdays/presentation/birthday_providers.dart';
import '../../company_docs/presentation/company_document_providers.dart';
import 'quick_actions.dart';
import '../../../core/widgets/skeleton.dart';

/// Inicio del colaborador: turno de hoy, estado de la jornada y botones de marcación.
/// Para supervisores / RRHH agrega la sección "Mi equipo".
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  late final AppLifecycleListener _lifecycle;

  @override
  void initState() {
    super.initState();
    // Al volver a la app se actualizan los avisos (sin servicio en segundo plano)
    _lifecycle = AppLifecycleListener(onResume: _refreshBadges);
    // El día de su cumpleaños, el saludo de la empresa (una vez ese día)
    ref.listenManual(upcomingBirthdaysProvider, (_, next) {
      final greeting = next.value?.greeting;
      if (greeting != null) _celebrate(greeting);
    }, fireImmediately: true);
  }

  void _celebrate(BirthdayGreeting greeting) {
    final userId = ref.read(authControllerProvider).user?.id;
    if (userId == null) return;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) BirthdayCelebration.maybeShow(context, ref.read(sharedPreferencesProvider), userId, greeting);
    });
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  void _refreshBadges() {
    ref.invalidate(unreadCountProvider);
    ref.invalidate(pendingApprovalsCountProvider);
    ref.invalidate(trainingsProvider);
    ref.invalidate(onboardingProvider);
    ref.invalidate(upcomingBirthdaysProvider);
    ref.invalidate(documentLibraryProvider);
    ref.invalidate(announcementFeedProvider);
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final today = ref.watch(todayStatusProvider);
    final user = auth.user;
    // Incorporación sin aprobar: en lugar de los botones de marcar, el asistente
    final canPunch = ref.watch(canPunchProvider);

    return Scaffold(
      // La empresa y la sede se ven en "Mi perfil": aquí solo el título y los avisos
      appBar: AppBar(title: const Text('Inicio'), actions: const [NotificationBell(), SizedBox(width: 4)]),
      body: RefreshIndicator(
        onRefresh: () async {
          _refreshBadges();
          // Envía marcaciones guardadas sin conexión antes de recargar el día
          await ref.read(offlineSyncProvider.notifier).sync(force: true);
          return ref.refresh(todayStatusProvider.future);
        },
        child: ListView(
          padding: pagePadding(context),
          children: [
            HomeGreeting(name: auth.user?.firstName ?? '', initials: auth.user?.initials ?? '?', avatarUrl: auth.user?.avatarUrl),
            const SizedBox(height: 16),
            // Las tarjetas que llegan después crecen suave, sin empujar de golpe
            const RevealSize(child: AnnouncementHomeCard()),
            const RevealSize(child: TrainingHomeCard()),
            const RevealSize(child: _PendingDocumentsCard()),
            if (user != null && user.hasTeamArea) ...[
              _TeamSection(canApprove: user.canApproveRequests, canViewTeam: user.canViewTeam),
              const SizedBox(height: 16),
            ],
            today.whenSkeleton(
              loading: () => const SkeletonPunchBoard(),
              error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(todayStatusProvider)),
              data: (status) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _ShiftCard(schedule: status.schedule),
                  const SizedBox(height: 12),
                  if (canPunch)
                    PunchBoard(status: status, onPunch: (action) => context.push('/clock/${action.apiName}'))
                  else
                    const OnboardingGateCard(),
                ],
              ),
            ),
            const SizedBox(height: 16),
            const RevealSize(child: BirthdayHomeCard()),
            const QuickActions(),
          ],
        ),
      ),
    );
  }
}

/// Saludo de Inicio: foto, nombre y fecha sobre el azul de la marca.
class HomeGreeting extends StatelessWidget {
  const HomeGreeting({super.key, required this.name, required this.initials, this.avatarUrl});

  final String name;
  final String initials;

  /// Foto de perfil; sin foto se muestran las iniciales en el avatar.
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final hour = DateTime.now().hour;
    final greeting = hour < 12 ? 'Buenos días' : (hour < 19 ? 'Buenas tardes' : 'Buenas noches');

    const accent = AppTheme.success;

    return BrandHero(
      child: Row(children: [
        Container(
          padding: const EdgeInsets.all(2.5),
          decoration: const BoxDecoration(shape: BoxShape.circle, color: Colors.white),
          child: CircleAvatar(
            key: const Key('greeting-avatar'),
            radius: 28,
            backgroundColor: scheme.primaryContainer,
            foregroundImage: avatarUrl == null ? null : NetworkImage(avatarUrl!),
            // Si la foto no carga, quedan las iniciales debajo
            onForegroundImageError: avatarUrl == null ? null : (_, _) {},
            child: Text(initials, style: TextStyle(color: scheme.onPrimaryContainer, fontSize: 20, fontWeight: FontWeight.w700)),
          ),
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            // Siempre en una línea: con nombres largos el texto se reduce
            FittedBox(
              key: const Key('greeting-text'),
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text('$greeting, $name',
                  maxLines: 1, softWrap: false, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
            ),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.16), borderRadius: BorderRadius.circular(6)),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.calendar_today_rounded, size: 13, color: accent.withValues(alpha: 0.95)),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(Fmt.dayLong(DateTime.now()),
                      overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600)),
                ),
              ]),
            ),
          ]),
        ),
      ]),
    );
  }
}

/// Accesos del supervisor / RRHH: bandeja de aprobaciones y equipo en vivo.
class _TeamSection extends ConsumerWidget {
  const _TeamSection({required this.canApprove, required this.canViewTeam});

  final bool canApprove;
  final bool canViewTeam;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = canApprove ? (ref.watch(pendingApprovalsCountProvider).value ?? 0) : 0;

    return Card(
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Text('Mi equipo', style: Theme.of(context).textTheme.titleMedium),
        ),
        if (canApprove)
          ListTile(
            leading: const Icon(Icons.fact_check_outlined),
            title: const Text('Aprobaciones'),
            subtitle: Text(pending > 0 ? '$pending solicitud(es) por revisar' : 'Sin solicitudes pendientes'),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              if (pending > 0) Badge(label: Text(pending > 99 ? '99+' : '$pending')),
              const Icon(Icons.chevron_right_rounded),
            ]),
            onTap: () async {
              await context.push('/approvals');
              if (context.mounted) ref.invalidate(pendingApprovalsCountProvider);
            },
          ),
        if (canViewTeam)
          ListTile(
            leading: const Icon(Icons.groups_outlined),
            title: const Text('Equipo en vivo'),
            subtitle: const Text('Quién marcó, tardanzas y fuera de geocerca'),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: () => context.push('/team'),
          ),
      ]),
    );
  }
}

/// Aviso de documentos de la empresa por leer y confirmar (RIT, políticas...).
class _PendingDocumentsCard extends ConsumerWidget {
  const _PendingDocumentsCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(companyDocumentsEnabledProvider)) return const SizedBox.shrink();
    final pending = ref.watch(documentLibraryProvider).value?.pendingAcknowledgement ?? 0;
    if (pending == 0) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        child: ListTile(
          key: const Key('home-pending-documents'),
          leading: CircleAvatar(backgroundColor: scheme.error.withValues(alpha: 0.12), foregroundColor: scheme.error, child: const Icon(Icons.task_alt_rounded)),
          title: Text(pending == 1 ? 'Tienes 1 documento por confirmar' : 'Tienes $pending documentos por confirmar',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: const Text('Léelos y marca "Leído y conforme".'),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push('/company-documents?tab=pending'),
        ),
      ),
    );
  }
}

class _ShiftCard extends StatelessWidget {
  const _ShiftCard({required this.schedule});

  final DaySchedule? schedule;

  @override
  Widget build(BuildContext context) {
    final s = schedule;
    final works = s != null && s.isWorkday;

    return Card(
      child: ListTile(
        leading: Icon(works ? Icons.schedule : Icons.weekend_outlined, size: 32),
        // Turno y horario siempre en una línea: con nombres largos se achica
        title: FittedBox(
          key: const Key('shift-title'),
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            works ? '${s.shiftName ?? 'Turno'} · ${Fmt.clock(s.start)} – ${Fmt.clock(s.end)}' : 'Hoy no tienes turno programado',
            maxLines: 1,
            softWrap: false,
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
          ),
        ),
        subtitle: Text(works ? (s.branch ?? 'Sucursal asignada') : 'Día libre según tu horario.'),
      ),
    );
  }
}

