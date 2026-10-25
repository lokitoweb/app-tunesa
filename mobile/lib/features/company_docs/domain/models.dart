import '../../../core/utils/json_num.dart';

/// Carpeta de documentos de la empresa (Políticas, Reglamentos...).
class DocumentFolder {
  const DocumentFolder({required this.id, required this.name, this.description, this.icon, this.color, this.count = 0});

  final int id;
  final String name;
  final String? description;
  final String? icon;
  final String? color;
  final int count;

  factory DocumentFolder.fromJson(Map<String, dynamic> json) => DocumentFolder(
        id: asInt(json['id'])!,
        name: json['name']?.toString() ?? '',
        description: json['description']?.toString(),
        icon: json['icon']?.toString(),
        color: json['color']?.toString(),
        count: asInt(json['count']) ?? 0,
      );
}

/// Documento de la empresa (PDF) y lo que hizo la persona con él.
class CompanyDocument {
  const CompanyDocument({
    required this.id,
    required this.title,
    required this.fileName,
    this.version,
    this.folderId,
    this.folder,
    this.fileSize = 0,
    this.publishedAt,
    this.requiresAcknowledgement = false,
    this.acknowledgeBy,
    this.read = false,
    this.acknowledgedAt,
    this.pendingAcknowledgement = false,
    this.description,
  });

  final int id;
  final String title;
  final String? version;
  final int? folderId;
  final String? folder;
  final String fileName;
  final int fileSize;
  final DateTime? publishedAt;
  final bool requiresAcknowledgement;
  final DateTime? acknowledgeBy;
  final bool read;
  final DateTime? acknowledgedAt;
  final bool pendingAcknowledgement;
  final String? description;

  factory CompanyDocument.fromJson(Map<String, dynamic> json) => CompanyDocument(
        id: asInt(json['id'])!,
        title: json['title']?.toString() ?? '',
        version: json['version']?.toString(),
        folderId: asInt(json['folder_id']),
        folder: json['folder']?.toString(),
        fileName: json['file_name']?.toString() ?? 'documento.pdf',
        fileSize: asInt(json['file_size']) ?? 0,
        publishedAt: DateTime.tryParse(json['published_at']?.toString() ?? ''),
        requiresAcknowledgement: json['requires_acknowledgement'] == true,
        acknowledgeBy: DateTime.tryParse(json['acknowledge_by']?.toString() ?? ''),
        read: json['read'] == true,
        acknowledgedAt: DateTime.tryParse(json['acknowledged_at']?.toString() ?? ''),
        pendingAcknowledgement: json['pending_acknowledgement'] == true,
        description: json['description']?.toString(),
      );

  /// "1.2 MB" / "320 KB"
  String get sizeLabel => fileSize >= 1048576 ? '${(fileSize / 1048576).toStringAsFixed(1)} MB' : '${(fileSize / 1024).ceil()} KB';
}

/// Lo que ve la persona: sus documentos y las carpetas que tienen alguno.
class DocumentLibrary {
  const DocumentLibrary({required this.documents, required this.folders, required this.pendingAcknowledgement});

  final List<CompanyDocument> documents;
  final List<DocumentFolder> folders;
  final int pendingAcknowledgement;

  List<CompanyDocument> inFolder(int folderId) => documents.where((d) => d.folderId == folderId).toList();

  List<CompanyDocument> get pending => documents.where((d) => d.pendingAcknowledgement).toList();
}
