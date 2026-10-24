import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdfx/pdfx.dart';

import 'async_views.dart';
import 'skeleton.dart';

/// PDF en memoria con zoom (no se escribe en el almacenamiento del teléfono).
class PdfBytesView extends StatefulWidget {
  const PdfBytesView({super.key, required this.bytes});

  final Uint8List bytes;

  @override
  State<PdfBytesView> createState() => _PdfBytesViewState();
}

class _PdfBytesViewState extends State<PdfBytesView> {
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
          errorBuilder: (_, _) => const ErrorView(error: 'No se pudo abrir el archivo: parece dañado. Pide a RR. HH. que lo vuelva a publicar.'),
        ),
      );
}
