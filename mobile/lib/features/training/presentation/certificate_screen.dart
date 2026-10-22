import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/pdf_bytes_view.dart';
import 'training_providers.dart';
import '../../../core/widgets/skeleton.dart';

final _certificateProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(trainingRepositoryProvider).certificate(id),
);

/// Constancia en PDF de una capacitación completada.
class CertificateScreen extends ConsumerWidget {
  const CertificateScreen({super.key, required this.courseId});

  final int courseId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pdf = ref.watch(_certificateProvider(courseId));
    return Scaffold(
      appBar: AppBar(title: const Text('Mi constancia')),
      body: pdf.whenSkeleton(
        loading: () => const LoadingView(message: 'Generando constancia…'),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(_certificateProvider(courseId))),
        data: (bytes) => PdfBytesView(bytes: bytes),
      ),
    );
  }
}
