import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

/// "Mis documentos" (legajo): avance de los obligatorios, estado de cada
/// documento y subida por foto o PDF.
class DocumentsScreen extends ConsumerWidget {
  const DocumentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis documentos')),
      body: file.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(employeeFileProvider)),
        data: (f) {
          if (!f.config.documents) {
            return const EmptyView(icon: Icons.lock_outline, message: 'Tu empresa no habilitó esta sección.');
          }
          final types = f.catalogs.documentTypes.isEmpty
              ? const {'dni': 'Documento de identidad', 'cv': 'Currículum (CV)', 'proof_of_address': 'Recibo de servicios / domicilio'}
              : f.catalogs.documentTypes;
          // Obligatorios primero, en el orden del catálogo
          final ordered = [
            ...types.entries.where((e) => f.config.requiredDocuments.contains(e.key)),
            ...types.entries.where((e) => !f.config.requiredDocuments.contains(e.key)),
          ];

          return RefreshIndicator(
            onRefresh: () => ref.refresh(employeeFileProvider.future),
            child: ListView(
              padding: pagePadding(context),
              children: [
                _DocumentsSummary(file: f, types: types),
                const SizedBox(height: 20),
                for (final entry in ordered)
                  _DocumentCard(
                    type: entry.key,
                    label: entry.value,
                    required: f.config.requiredDocuments.contains(entry.key),
                    document: f.currentDocument(entry.key),
                    canUpload: f.config.uploadDocuments,
                    maxMb: f.config.maxUploadMb,
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Cabecera: cuántos obligatorios están listos y cómo subir.
class _DocumentsSummary extends StatelessWidget {
  const _DocumentsSummary({required this.file, required this.types});

  final EmployeeFile file;
  final Map<String, String> types;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final required = file.config.requiredDocuments.where(types.containsKey).toList();
    final ready = required.where((t) => !file.missingDocuments.contains(t)).length;
    final total = required.length;
    final complete = total == 0 || ready == total;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: (complete ? Colors.green : scheme.primary).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(complete ? Icons.verified_rounded : Icons.folder_shared_outlined,
                  color: complete ? Colors.green.shade600 : scheme.primary),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(complete ? 'Documentos al día' : 'Documentos obligatorios',
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: 2),
                Text(total == 0 ? 'Tu empresa no exige documentos.' : '$ready de $total entregados',
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
              ]),
            ),
          ]),
          if (total > 0) ...[
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: LinearProgressIndicator(value: ready / total, minHeight: 8),
            ),
          ],
          const SizedBox(height: 14),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.info_outline_rounded, size: 18, color: scheme.onSurfaceVariant),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                'Sube una foto nítida o un PDF (máx. ${file.config.maxUploadMb} MB). RRHH revisará cada documento.',
                style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant, height: 1.35),
              ),
            ),
          ]),
        ]),
      ),
    );
  }
}

class _DocumentCard extends ConsumerStatefulWidget {
  const _DocumentCard({
    required this.type,
    required this.label,
    required this.required,
    required this.document,
    required this.canUpload,
    required this.maxMb,
  });

  final String type;
  final String label;
  final bool required;
  final EmployeeDocument? document;
  final bool canUpload;
  final int maxMb;

  @override
  ConsumerState<_DocumentCard> createState() => _DocumentCardState();
}

class _DocumentCardState extends ConsumerState<_DocumentCard> {
  bool _busy = false;

  Future<void> _upload(_Source source) async {
    final picked = await _pick(source);
    if (picked == null) return;

    if (picked.bytes.length > widget.maxMb * 1024 * 1024) {
      if (mounted) showSnack(context, 'El archivo supera ${widget.maxMb} MB. Elige uno más liviano.', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final msg = await ref.read(profileRepositoryProvider).uploadDocument(type: widget.type, fileName: picked.name, bytes: picked.bytes);
      ref.invalidate(employeeFileProvider);
      if (mounted) showSnack(context, msg);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<({String name, Uint8List bytes})?> _pick(_Source source) async {
    switch (source) {
      case _Source.camera:
      case _Source.gallery:
        final image = await ImagePicker().pickImage(
          source: source == _Source.camera ? ImageSource.camera : ImageSource.gallery,
          maxWidth: 2200,
          imageQuality: 85,
        );
        if (image == null) return null;
        return (name: '${widget.type}.jpg', bytes: await image.readAsBytes());
      case _Source.file:
        final file = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png']);
        if (file == null) return null;
        return (name: file.name, bytes: await file.readAsBytes());
    }
  }

  Future<void> _chooseSource() async {
    final source = await showModalBottomSheet<_Source>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera_outlined), title: const Text('Tomar foto'), onTap: () => Navigator.pop(c, _Source.camera)),
          ListTile(leading: const Icon(Icons.photo_library_outlined), title: const Text('Elegir de la galería'), onTap: () => Navigator.pop(c, _Source.gallery)),
          ListTile(leading: const Icon(Icons.picture_as_pdf_outlined), title: const Text('Subir PDF o archivo'), onTap: () => Navigator.pop(c, _Source.file)),
        ]),
      ),
    );
    if (source != null) await _upload(source);
  }

  Future<void> _delete(EmployeeDocument doc) async {
    setState(() => _busy = true);
    try {
      await ref.read(profileRepositoryProvider).deleteDocument(doc.id);
      ref.invalidate(employeeFileProvider);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final doc = widget.document;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (color, icon, statusText) = switch (doc?.status) {
      DocumentStatus.approved => (Colors.green.shade600, Icons.verified_rounded, 'Aprobado'),
      DocumentStatus.pending => (Colors.orange.shade700, Icons.hourglass_top_rounded, 'En revisión'),
      DocumentStatus.rejected => (scheme.error, Icons.error_outline_rounded, 'Rechazado'),
      _ => (scheme.primary, Icons.upload_file_rounded, 'Sin subir'),
    };
    final canReplace = widget.canUpload;
    final uploadLabel = doc == null ? 'Subir documento' : 'Reemplazar documento';

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: color),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  // Una sola línea: el nombre no se parte ("Documento de identidad")
                  Text(widget.label,
                      maxLines: 1,
                      softWrap: false,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(height: 4),
                  Wrap(spacing: 6, runSpacing: 4, children: [
                    _Tag(text: statusText, color: color),
                    if (widget.required) _Tag(text: 'Obligatorio', color: scheme.onSurfaceVariant),
                  ]),
                ]),
              ),
              if (canReplace) _UploadIcon(tooltip: uploadLabel, busy: _busy, onPressed: _chooseSource),
            ]),
            if (doc != null) ...[
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(
                  color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(children: [
                  Icon(doc.originalName.toLowerCase().endsWith('.pdf') ? Icons.picture_as_pdf_outlined : Icons.image_outlined,
                      size: 20, color: scheme.onSurfaceVariant),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(doc.originalName, maxLines: 1, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodyMedium),
                  ),
                  const SizedBox(width: 8),
                  Text(Fmt.date(doc.uploadedAt), style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                ]),
              ),
              if (doc.status == DocumentStatus.rejected && doc.rejectionReason != null)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text('Motivo del rechazo: ${doc.rejectionReason}',
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.error, fontWeight: FontWeight.w600)),
                ),
              const SizedBox(height: 8),
              Wrap(alignment: WrapAlignment.end, spacing: 4, runSpacing: 4, children: [
                if (doc.canDelete && canReplace)
                  TextButton(onPressed: _busy ? null : () => _delete(doc), child: const Text('Retirar')),
                TextButton.icon(
                  onPressed: _busy ? null : () => context.push('/profile/documents/${doc.id}', extra: doc),
                  icon: const Icon(Icons.visibility_outlined, size: 20),
                  label: const Text('Ver'),
                ),
              ]),
            ],
          ]),
        ),
      ),
    );
  }
}

/// Subir o reemplazar: solo el ícono, sin botón ni fondo.
class _UploadIcon extends StatelessWidget {
  const _UploadIcon({required this.tooltip, required this.busy, required this.onPressed});

  final String tooltip;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => IconButton(
        key: const Key('upload-document'),
        tooltip: tooltip,
        color: Theme.of(context).colorScheme.primary,
        onPressed: busy ? null : onPressed,
        icon: busy
            ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2))
            : const Icon(Icons.file_upload_outlined, size: 26),
      );
}

/// Etiqueta corta de color ("Aprobado", "Obligatorio").
class _Tag extends StatelessWidget {
  const _Tag({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: TextStyle(color: color, fontSize: 11.5, fontWeight: FontWeight.w600)),
      );
}

enum _Source { camera, gallery, file }
