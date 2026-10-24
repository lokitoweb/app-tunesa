import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/pdf_bytes_view.dart';
import '../../../core/widgets/skeleton.dart';
import 'payslips_screen.dart';

final _payslipPdfProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(payslipRepositoryProvider).pdf(id),
);

/// Visor protegido: el PDF se descarga con el token del colaborador y se
/// muestra desde memoria. "Descargar" abre el menú del teléfono para
/// guardarlo en Archivos/Drive o enviarlo.
class PayslipViewerScreen extends ConsumerWidget {
  const PayslipViewerScreen({super.key, required this.id, this.title});

  final int id;
  final String? title;

  String get _fileName => 'boleta_${(title ?? '$id').replaceAll(RegExp(r'[^A-Za-z0-9]+'), '_').toLowerCase()}.pdf';

  Future<void> _download(BuildContext context, Uint8List bytes) async {
    final box = context.findRenderObject() as RenderBox?;
    await SharePlus.instance.share(ShareParams(
      files: [XFile.fromData(bytes, mimeType: 'application/pdf', name: _fileName)],
      fileNameOverrides: [_fileName],
      subject: title ?? 'Boleta de pago',
      sharePositionOrigin: box == null ? null : box.localToGlobal(Offset.zero) & box.size,
    ));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pdf = ref.watch(_payslipPdfProvider(id));
    final bytes = pdf.value;

    return Scaffold(
      appBar: AppBar(
        title: Text(title ?? 'Boleta de pago'),
        actions: [
          if (bytes != null)
            IconButton(
              key: const Key('payslip-download'),
              tooltip: 'Descargar o compartir',
              icon: const Icon(Icons.download_rounded),
              onPressed: () => _download(context, bytes),
            ),
        ],
      ),
      body: pdf.whenSkeleton(
        loading: () => const LoadingView(message: 'Descargando boleta…'),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(_payslipPdfProvider(id))),
        data: (b) => PdfBytesView(bytes: b),
      ),
    );
  }
}
