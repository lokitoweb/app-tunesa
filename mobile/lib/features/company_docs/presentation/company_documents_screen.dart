import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/module_icons.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'company_document_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Documentos: carpetas de la empresa, los que faltan confirmar y acceso a
/// "Mi legajo" (documentos personales).
class CompanyDocumentsScreen extends ConsumerStatefulWidget {
  const CompanyDocumentsScreen({super.key, this.initialTab = 0});

  /// 0 = Empresa, 1 = Por confirmar
  final int initialTab;

  @override
  ConsumerState<CompanyDocumentsScreen> createState() => _CompanyDocumentsScreenState();
}

class _CompanyDocumentsScreenState extends ConsumerState<CompanyDocumentsScreen> {
  late int _tab = widget.initialTab;

  @override
  Widget build(BuildContext context) {
    final library = ref.watch(documentLibraryProvider);
    final personal = ref.watch(appConfigProvider.select((s) => s.config.modules.documents));
    final pending = library.value?.pendingAcknowledgement ?? 0;

    return Scaffold(
      appBar: AppBar(title: const Text('Documentos')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: SegmentedButton<int>(
            showSelectedIcon: false,
            segments: [
              const ButtonSegment(value: 0, label: Text('Empresa'), icon: Icon(Icons.business_rounded)),
              ButtonSegment(value: 1, label: Text(pending > 0 ? 'Por confirmar ($pending)' : 'Por confirmar'), icon: const Icon(Icons.task_alt_rounded)),
            ],
            selected: {_tab},
            onSelectionChanged: (s) => setState(() => _tab = s.first),
          ),
        ),
        Expanded(
          child: library.whenSkeleton(
            loading: () => const LoadingView(),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(documentLibraryProvider)),
            data: (lib) => RefreshIndicator(
              onRefresh: () => ref.refresh(documentLibraryProvider.future),
              child: ListView(
                padding: pagePadding(context, top: 8),
                children: [
                  if (_tab == 0) ...[
                    for (final f in lib.folders) _FolderCard(folder: f),
                    if (lib.folders.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 64),
                        child: EmptyView(icon: Icons.folder_open_rounded, message: 'Aún no hay documentos de la empresa para ti.'),
                      ),
                    if (personal) ...[
                      const SizedBox(height: 8),
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.assignment_ind_outlined),
                          title: const Text('Mi legajo', style: TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: const Text('Tus documentos personales: DNI, contratos, certificados'),
                          trailing: const Icon(Icons.chevron_right_rounded),
                          onTap: () => context.push('/profile/documents'),
                        ),
                      ),
                    ],
                  ] else ...[
                    for (final d in lib.pending) CompanyDocumentTile(document: d, showFolder: true),
                    if (lib.pending.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 64),
                        child: EmptyView(icon: Icons.task_alt_rounded, message: 'Estás al día: no tienes documentos por confirmar.'),
                      ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

class _FolderCard extends StatelessWidget {
  const _FolderCard({required this.folder});

  final DocumentFolder folder;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = hexColor(folder.color, theme.colorScheme.primary);

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        onTap: () => context.push('/company-documents/folders/${folder.id}', extra: folder.name),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
          child: Row(children: [
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
              child: Icon(moduleIcon(folder.icon), color: color, size: 28),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(folder.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                Text(folder.count == 1 ? '1 documento' : '${folder.count} documentos',
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
              ]),
            ),
            const Icon(Icons.chevron_right_rounded),
          ]),
        ),
      ),
    );
  }
}

/// Documentos de una carpeta.
class FolderDocumentsScreen extends ConsumerWidget {
  const FolderDocumentsScreen({super.key, required this.folderId, this.title});

  final int folderId;
  final String? title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final library = ref.watch(documentLibraryProvider);

    return Scaffold(
      appBar: AppBar(title: Text(title ?? library.value?.folders.where((f) => f.id == folderId).firstOrNull?.name ?? 'Documentos')),
      body: library.whenSkeleton(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(documentLibraryProvider)),
        data: (lib) {
          final docs = lib.inFolder(folderId);
          return RefreshIndicator(
            onRefresh: () => ref.refresh(documentLibraryProvider.future),
            child: docs.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.folder_open_rounded, message: 'Esta carpeta está vacía.'),
                  ])
                : ListView(padding: pagePadding(context), children: [for (final d in docs) CompanyDocumentTile(document: d)]),
          );
        },
      ),
    );
  }
}

/// Fila de un documento: título, versión y estado (nuevo, por confirmar, conforme).
class CompanyDocumentTile extends StatelessWidget {
  const CompanyDocumentTile({super.key, required this.document, this.showFolder = false});

  final CompanyDocument document;
  final bool showFolder;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final d = document;
    final (label, color) = d.pendingAcknowledgement
        ? ('Por confirmar', scheme.error)
        : d.acknowledgedAt != null
            ? ('Leído y conforme', Colors.green.shade700)
            : !d.read
                ? ('Nuevo', scheme.primary)
                : ('Leído', scheme.onSurfaceVariant);

    final details = [
      if (showFolder && d.folder != null) d.folder!,
      if (d.version != null) d.version!,
      d.sizeLabel,
      if (d.pendingAcknowledgement && d.acknowledgeBy != null) 'Confirmar hasta el ${Fmt.date(d.acknowledgeBy)}',
    ].join(' · ');

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        onTap: () => context.push('/company-documents/${d.id}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Icon(Icons.picture_as_pdf_rounded, size: 36, color: Colors.red.shade400),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(d.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                const SizedBox(height: 2),
                Text(details, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(20)),
                  child: Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
                ),
              ]),
            ),
            const Icon(Icons.chevron_right_rounded),
          ]),
        ),
      ),
    );
  }
}
