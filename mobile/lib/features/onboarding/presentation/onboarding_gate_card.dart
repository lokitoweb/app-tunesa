import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../domain/models.dart';
import 'onboarding_providers.dart';

/// Reemplaza los botones de marcar en Inicio mientras la incorporación no
/// esté aprobada: dice en qué va y lleva al asistente.
class OnboardingGateCard extends ConsumerWidget {
  const OnboardingGateCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final summary = ref.watch(onboardingProvider).value;
    final status = summary?.status ?? OnboardingStatus.pending;

    final (title, subtitle, icon) = switch (status) {
      OnboardingStatus.submitted => ('Incorporación en revisión', 'Podrás marcar cuando RRHH la apruebe.', Icons.hourglass_top_rounded),
      OnboardingStatus.rejected => ('RRHH observó tu incorporación', summary?.rejectionReason ?? 'Corrige lo indicado y vuelve a enviarla.', Icons.report_gmailerrorred_rounded),
      _ => (
          'Completa tu incorporación',
          summary == null ? 'Antes de marcar, completa tus datos y espera la aprobación de RRHH.' : '${summary.doneCount} de ${summary.visibleStages.length} etapas completas. Luego podrás marcar.',
          Icons.assignment_ind_rounded,
        ),
    };

    return Card(
      key: const Key('onboarding-gate'),
      color: status == OnboardingStatus.rejected ? scheme.errorContainer : scheme.secondaryContainer,
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        leading: Icon(icon, size: 32),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: () async {
          await context.push('/onboarding');
          if (context.mounted) ref.invalidate(onboardingProvider);
        },
      ),
    );
  }
}
