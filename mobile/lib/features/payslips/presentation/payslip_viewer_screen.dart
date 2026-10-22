import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdfx/pdfx.dart';

import '../../../core/widgets/async_views.dart';
import 'payslips_screen.dart';
import '../../../core/widgets/skeleton.dart';

final _payslipPdfProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(payslipRepositoryProvider).pdf(id),
);

/// Visor protegido: el PDF se descarga con el token del colaborador y se
/// muestra desde memoria, sin guardarse en el teléfono.
class PayslipViewerScreen extends ConsumerWidget {
  const PayslipViewerScreen({super.key, required this.id, this.title});

  final int id;
  final String? title;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pdf = ref.watch(_payslipPdfProvider(id));

    return Scaffold(
      appBar: AppBar(title: Text(title ?? 'Boleta de pago')),
      body: pdf.whenSkeleton(
        loading: () => const LoadingView(message: 'Descargando boleta…'),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(_payslipPdfProvider(id))),
        data: (bytes) => _PdfView(bytes: bytes),
      ),
    );
  }
}

class _PdfView extends StatefulWidget {
  const _PdfView({required this.bytes});

  final Uint8List bytes;

  @override
  State<_PdfView> createState() => _PdfViewState();
}

class _PdfViewState extends State<_PdfView> {
  late final PdfControllerPinch _controller = PdfControllerPinch(document: PdfDocument.openData(widget.bytes));

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => PdfViewPinch(
        controller: _controller,
        builders: PdfViewPinchBuilders<DefaultBuilderOptions>(
          options: const DefaultBuilderOptions(),
          documentLoaderBuilder: (_) => const SkeletonDocument(),
          pageLoaderBuilder: (_) => const SkeletonDocument(),
          errorBuilder: (_, error) => ErrorView(error: 'No se pudo abrir el PDF: $error'),
        ),
      );
}
