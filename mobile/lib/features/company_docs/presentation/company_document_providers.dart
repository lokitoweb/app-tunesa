import 'dart:typed_data';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/company_document_repository.dart';
import '../domain/models.dart';

final companyDocumentRepositoryProvider =
    Provider<CompanyDocumentRepository>((ref) => CompanyDocumentRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de documentos de la empresa?
final companyDocumentsEnabledProvider =
    Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.companyDocuments)));

final documentLibraryProvider = FutureProvider.autoDispose<DocumentLibrary>((ref) => ref.watch(companyDocumentRepositoryProvider).library());

final companyDocumentProvider = FutureProvider.autoDispose.family<CompanyDocument, int>(
  (ref, id) => ref.watch(companyDocumentRepositoryProvider).detail(id),
);

final companyDocumentPdfProvider = FutureProvider.autoDispose.family<Uint8List, int>(
  (ref, id) => ref.watch(companyDocumentRepositoryProvider).pdf(id),
);
