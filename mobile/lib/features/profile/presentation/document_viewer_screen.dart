import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdfx/pdfx.dart';

import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

final _documentBytesProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(profileRepositoryProvider).documentBytes(id),
);

/// Visor de un documento propio. Se descarga con una URL firmada que vence
/// en minutos y se muestra desde memoria (no queda en el teléfono).
class DocumentViewerScreen extends ConsumerWidget {
  const DocumentViewerScreen({super.key, required this.id, this.document});

  final int id;
  final EmployeeDocument? document;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bytes = ref.watch(_documentBytesProvider(id));

    return Scaffold(
      appBar: AppBar(title: Text(document?.typeLabel ?? 'Documento')),
      body: bytes.when(
        loading: () => const LoadingView(message: 'Abriendo documento…'),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(_documentBytesProvider(id))),
        data: (data) => _isPdf(data)
            ? _PdfBytesView(bytes: data)
            : InteractiveViewer(
                maxScale: 5,
                child: Center(child: Image.memory(data, errorBuilder: (_, _, _) => const ErrorView(error: 'No se pudo mostrar la imagen.'))),
              ),
      ),
    );
  }

  static bool _isPdf(Uint8List b) => b.length > 4 && b[0] == 0x25 && b[1] == 0x50 && b[2] == 0x44 && b[3] == 0x46;
}

class _PdfBytesView extends StatefulWidget {
  const _PdfBytesView({required this.bytes});

  final Uint8List bytes;

  @override
  State<_PdfBytesView> createState() => _PdfBytesViewState();
}

class _PdfBytesViewState extends State<_PdfBytesView> {
  late final PdfControllerPinch _controller = PdfControllerPinch(document: PdfDocument.openData(widget.bytes));

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => PdfViewPinch(controller: _controller);
}
