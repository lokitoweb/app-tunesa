import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/pdf_bytes_view.dart';
import '../domain/models.dart';
import 'company_document_providers.dart';

/// Lee el PDF dentro de la app. "Descargar" abre el menú del teléfono para
/// guardarlo en Archivos/Drive o enviarlo. Si el documento lo pide, abajo
/// aparece "Leí y estoy conforme".
class CompanyDocumentViewerScreen extends ConsumerStatefulWidget {
  const CompanyDocumentViewerScreen({super.key, required this.id});

  final int id;

  @override
  ConsumerState<CompanyDocumentViewerScreen> createState() => _CompanyDocumentViewerScreenState();
}

class _CompanyDocumentViewerScreenState extends ConsumerState<CompanyDocumentViewerScreen> {
  bool _sending = false;
  bool _acknowledgedNow = false;

  @override
  void initState() {
    super.initState();
    // Abrir el PDF lo marca como leído en el servidor: se refresca la lista una vez
    ref.listenManual(companyDocumentPdfProvider(widget.id), (prev, next) {
      if (next.hasValue && !(prev?.hasValue ?? false)) ref.invalidate(documentLibraryProvider);
    });
  }

  Future<void> _download(CompanyDocument doc, Uint8List bytes) async {
    final box = context.findRenderObject() as RenderBox?;
    await SharePlus.instance.share(ShareParams(
      files: [XFile.fromData(bytes, mimeType: 'application/pdf', name: doc.fileName)],
      fileNameOverrides: [doc.fileName.toLowerCase().endsWith('.pdf') ? doc.fileName : '${doc.fileName}.pdf'],
      subject: doc.title,
      sharePositionOrigin: box == null ? null : box.localToGlobal(Offset.zero) & box.size,
    ));
  }

  Future<void> _acknowledge(CompanyDocument doc) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Leído y conforme'),
        content: Text('Confirmas que leíste "${doc.title}" y estás conforme con su contenido. Queda registrada la fecha y hora.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Confirmar')),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _sending = true);
    try {
      await ref.read(companyDocumentRepositoryProvider).acknowledge(doc.id);
      ref.invalidate(documentLibraryProvider);
      if (!mounted) return;
      setState(() => _acknowledgedNow = true);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Listo: confirmaste que leíste el documento.')));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final doc = ref.watch(companyDocumentProvider(widget.id));
    final pdf = ref.watch(companyDocumentPdfProvider(widget.id));
    final d = doc.value;
    final bytes = pdf.value;
    final pending = d != null && d.pendingAcknowledgement && !_acknowledgedNow;

    return Scaffold(
      appBar: AppBar(
        title: Text(d?.title ?? 'Documento', maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (d != null && bytes != null)
            IconButton(tooltip: 'Descargar o compartir', icon: const Icon(Icons.download_rounded), onPressed: () => _download(d, bytes)),
        ],
      ),
      body: pdf.when(
        loading: () => const LoadingView(message: 'Abriendo documento…'),
        error: (e, _) => ErrorView(
          error: e,
          onRetry: () {
            ref.invalidate(companyDocumentPdfProvider(widget.id));
            ref.invalidate(companyDocumentProvider(widget.id));
          },
        ),
        data: (b) => PdfBytesView(bytes: b),
      ),
      bottomNavigationBar: d == null || !d.requiresAcknowledgement || bytes == null
          ? null
          : SafeArea(
              minimum: const EdgeInsets.fromLTRB(16, 8, 16, 12),
              child: pending
                  ? FilledButton.icon(
                      key: const Key('acknowledge-document'),
                      onPressed: _sending ? null : () => _acknowledge(d),
                      icon: _sending
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.task_alt_rounded),
                      label: const Text('Leí y estoy conforme'),
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                    )
                  : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(Icons.verified_rounded, color: Colors.green.shade700),
                      const SizedBox(width: 8),
                      Text(
                        d.acknowledgedAt != null ? 'Conforme desde el ${Fmt.date(d.acknowledgedAt)}' : 'Confirmaste este documento',
                        style: TextStyle(color: Colors.green.shade700, fontWeight: FontWeight.w700),
                      ),
                    ]),
            ),
    );
  }
}
