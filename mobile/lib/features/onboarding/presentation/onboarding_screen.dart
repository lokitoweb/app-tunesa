import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../../profile/presentation/profile_providers.dart';
import '../domain/models.dart';
import 'onboarding_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Asistente de incorporación: cada etapa lleva a la pantalla que ya existe
/// (contacto, datos de pago, tallas, documentos, capacitaciones) y, cuando
/// las obligatorias están completas, se envía a RRHH.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  bool _sending = false;

  Future<void> _open(String route) async {
    await context.push(route);
    if (!mounted) return;
    ref.invalidate(onboardingProvider);
    ref.invalidate(employeeFileProvider);
  }

  Future<void> _submit() async {
    setState(() => _sending = true);
    try {
      final (_, message) = await ref.read(onboardingRepositoryProvider).submit();
      if (!mounted) return;
      showSnack(context, message);
      ref.invalidate(onboardingProvider);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
      ref.invalidate(onboardingProvider);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final summary = ref.watch(onboardingProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Mi incorporación'),
        // Se llega aquí directo tras el primer ingreso: sin pantalla anterior
        actions: [
          if (!Navigator.of(context).canPop())
            TextButton(onPressed: () => context.go('/home'), child: const Text('Inicio')),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(onboardingProvider.future),
        child: summary.whenSkeleton(
          loading: () => const LoadingView(),
          error: (e, _) => ListView(children: [
            const SizedBox(height: 80),
            ErrorView(error: e, onRetry: () => ref.invalidate(onboardingProvider)),
          ]),
          data: (s) {
            final stages = s.visibleStages;
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: pagePadding(context),
              children: [
                _StatusHeader(summary: s),
                const SizedBox(height: 16),
                for (var i = 0; i < stages.length; i++) ...[
                  _StageCard(
                    index: i + 1,
                    stage: stages[i],
                    locked: s.status == OnboardingStatus.submitted || s.status == OnboardingStatus.approved,
                    onOpen: _open,
                  ),
                  const SizedBox(height: 12),
                ],
                if (s.status == OnboardingStatus.pending || s.status == OnboardingStatus.rejected) ...[
                  const SizedBox(height: 4),
                  FilledButton.icon(
                    key: const Key('onboarding-submit'),
                    onPressed: s.canSubmit && !_sending ? _submit : null,
                    icon: _sending
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.send_rounded),
                    label: Text(s.status == OnboardingStatus.rejected ? 'Volver a enviar a RRHH' : 'Enviar a RRHH'),
                  ),
                  if (!s.canSubmit)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        'Completa las etapas obligatorias para enviarla.',
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

class _StatusHeader extends StatelessWidget {
  const _StatusHeader({required this.summary});

  final OnboardingSummary summary;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final total = summary.visibleStages.length;

    final (icon, color, title, body) = switch (summary.status) {
      OnboardingStatus.submitted => (
          Icons.hourglass_top_rounded,
          scheme.tertiary,
          'RRHH está revisando tu incorporación',
          'Te avisaremos cuando la aprueben. Después podrás marcar tu asistencia.',
        ),
      OnboardingStatus.rejected => (
          Icons.report_gmailerrorred_rounded,
          scheme.error,
          'RRHH observó tu incorporación',
          summary.rejectionReason == null ? 'Corrige lo indicado y vuelve a enviarla.' : 'Motivo: ${summary.rejectionReason}',
        ),
      OnboardingStatus.approved => (
          Icons.verified_rounded,
          Colors.green.shade600,
          'Incorporación aprobada',
          'Ya puedes marcar tu asistencia desde Inicio.',
        ),
      OnboardingStatus.pending => (
          Icons.assignment_ind_rounded,
          scheme.primary,
          'Completa tu incorporación para empezar a marcar',
          '${summary.doneCount} de $total etapas completas. Al terminar, envíala a RRHH para su aprobación.',
        ),
    };

    return Card(
      color: color.withValues(alpha: 0.10),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, color: color, size: 32),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text(body),
              if (summary.status == OnboardingStatus.pending && total > 0) ...[
                const SizedBox(height: 12),
                LinearProgressIndicator(value: summary.doneCount / total, borderRadius: BorderRadius.circular(4)),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}

class _StageCard extends StatelessWidget {
  const _StageCard({required this.index, required this.stage, required this.locked, required this.onOpen});

  final int index;
  final OnboardingStage stage;
  final bool locked;
  final ValueChanged<String> onOpen;

  /// Pantallas donde se completa cada etapa: (texto del botón, ruta).
  List<(String, String)> get _actions => switch (stage.key) {
        'personal_data' => const [('Contacto', '/profile/contact'), ('Datos de pago', '/profile/payment')],
        'uniforms' => const [('Mis tallas', '/profile/uniforms')],
        'documents' => const [('Mis documentos', '/profile/documents')],
        'induction' => const [('Ir a la inducción', '/trainings')],
        _ => const [],
      };

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final done = stage.isDone;

    return Card(
      key: Key('onboarding-stage-${stage.key}'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            CircleAvatar(
              radius: 14,
              backgroundColor: done ? Colors.green.shade600 : scheme.surfaceContainerHighest,
              foregroundColor: done ? Colors.white : scheme.onSurfaceVariant,
              child: done ? const Icon(Icons.check_rounded, size: 18) : Text('$index', style: const TextStyle(fontWeight: FontWeight.w700)),
            ),
            const SizedBox(width: 12),
            Expanded(child: Text(stage.title, style: const TextStyle(fontWeight: FontWeight.w700))),
            if (!stage.required) Chip(label: const Text('Opcional'), visualDensity: VisualDensity.compact),
          ]),
          const SizedBox(height: 6),
          Text(stage.description, style: Theme.of(context).textTheme.bodySmall),
          if (stage.missing.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text('Falta: ${stage.missing.join(', ')}', style: TextStyle(color: scheme.error, fontSize: 13)),
          ],
          if (!locked && _actions.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 4, children: [
              for (final (label, route) in _actions)
                done
                    ? TextButton(onPressed: () => onOpen(route), child: Text(label))
                    : OutlinedButton(onPressed: () => onOpen(route), child: Text(label)),
            ]),
          ],
        ]),
      ),
    );
  }
}
