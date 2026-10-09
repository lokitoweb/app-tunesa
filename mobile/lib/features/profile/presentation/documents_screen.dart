import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

/// "Mi Legajo": estado de cada documento y subida por foto o PDF.
class DocumentsScreen extends ConsumerWidget {
  const DocumentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mi Legajo')),
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

          return RefreshIndicator(
            onRefresh: () => ref.refresh(employeeFileProvider.future),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  'Sube una foto nítida o un PDF (máx. ${f.config.maxUploadMb} MB). RRHH revisará cada documento.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: 16),
                for (final entry in types.entries)
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
    final scheme = Theme.of(context).colorScheme;
    final (color, icon) = switch (doc?.status) {
      DocumentStatus.approved => (Colors.green, Icons.verified_rounded),
      DocumentStatus.pending => (Colors.orange, Icons.hourglass_top_rounded),
      DocumentStatus.rejected => (scheme.error, Icons.error_outline_rounded),
      _ => (scheme.outline, Icons.upload_file_rounded),
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(icon, color: color),
              const SizedBox(width: 10),
              Expanded(
                child: Text(widget.label + (widget.required ? ' *' : ''), style: Theme.of(context).textTheme.titleMedium),
              ),
              if (doc != null)
                Chip(
                  label: Text(doc.status.label),
                  labelStyle: TextStyle(color: color, fontWeight: FontWeight.w600),
                  side: BorderSide(color: color.withValues(alpha: 0.4)),
                  visualDensity: VisualDensity.compact,
                ),
            ]),
            if (doc != null) ...[
              const SizedBox(height: 6),
              Text('${doc.originalName} · ${Fmt.date(doc.uploadedAt)}',
                  style: Theme.of(context).textTheme.bodySmall),
              if (doc.status == DocumentStatus.rejected && doc.rejectionReason != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('Motivo: ${doc.rejectionReason}', style: TextStyle(color: scheme.error)),
                ),
            ],
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (doc != null)
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => context.push('/profile/documents/${doc.id}', extra: doc),
                  icon: const Icon(Icons.visibility_outlined),
                  label: const Text('Ver'),
                ),
              if (widget.canUpload && doc?.status != DocumentStatus.approved)
                FilledButton.tonalIcon(
                  onPressed: _busy ? null : _chooseSource,
                  icon: _busy
                      ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.upload_rounded),
                  label: Text(doc == null ? 'Subir' : 'Reemplazar'),
                ),
              if (widget.canUpload && doc?.status == DocumentStatus.approved)
                TextButton.icon(
                  onPressed: _busy ? null : _chooseSource,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('Actualizar'),
                ),
              if (doc != null && doc.canDelete && widget.canUpload)
                TextButton(onPressed: _busy ? null : () => _delete(doc), child: const Text('Retirar')),
            ]),
          ]),
        ),
      ),
    );
  }
}

enum _Source { camera, gallery, file }
