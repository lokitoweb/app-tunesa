import 'dart:convert';
import 'dart:io';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';
import '../constants/api_constants.dart';
import '../network/api_client.dart';

/// Modelo de Marcación en Cola Offline
class OfflinePunchItem {
  final String id;
  final String endpoint;
  final Map<String, dynamic> fields;
  final String? localPhotoPath;
  final DateTime createdAt;

  OfflinePunchItem({
    required this.id,
    required this.endpoint,
    required this.fields,
    this.localPhotoPath,
    required this.createdAt,
  });

  Map<String, dynamic> toJson() => {
    'id': id,
    'endpoint': endpoint,
    'fields': fields,
    'localPhotoPath': localPhotoPath,
    'createdAt': createdAt.toIso8601String(),
  };

  factory OfflinePunchItem.fromJson(Map<String, dynamic> json) => OfflinePunchItem(
    id: json['id'],
    endpoint: json['endpoint'],
    fields: Map<String, dynamic>.from(json['fields']),
    localPhotoPath: json['localPhotoPath'],
    createdAt: DateTime.parse(json['createdAt']),
  );
}

/// Servicio de Cola y Sincronización en Segundo Plano para Zonas sin Cobertura
class OfflineSyncService {
  static const String _queueKey = ApiConstants.offlineQueueKey;

  /// Almacena una marcación en la cola local si falla la red
  static Future<void> queuePunch({
    required String endpoint,
    required Map<String, dynamic> fields,
    File? photo,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final List<String> currentQueue = prefs.getStringList(_queueKey) ?? [];

    final item = OfflinePunchItem(
      id: const Uuid().v4(),
      endpoint: endpoint,
      fields: fields,
      localPhotoPath: photo?.path,
      createdAt: DateTime.now(),
    );

    currentQueue.add(jsonEncode(item.toJson()));
    await prefs.setStringList(_queueKey, currentQueue);
  }

  /// Retorna la cantidad de marcaciones pendientes de sincronizar
  static Future<int> getPendingCount() async {
    final prefs = await SharedPreferences.getInstance();
    final List<String> queue = prefs.getStringList(_queueKey) ?? [];
    return queue.length;
  }

  /// Procesa e intenta sincronizar todas las marcaciones pendientes
  static Future<int> syncPendingPunches() async {
    final prefs = await SharedPreferences.getInstance();
    final List<String> queue = prefs.getStringList(_queueKey) ?? [];
    if (queue.isEmpty) return 0;

    int syncedCount = 0;
    final List<String> remainingQueue = [];

    for (final itemStr in queue) {
      try {
        final item = OfflinePunchItem.fromJson(jsonDecode(itemStr));
        File? photoFile;
        if (item.localPhotoPath != null) {
          final f = File(item.localPhotoPath!);
          if (await f.exists()) photoFile = f;
        }

        final response = await ApiClient().postMultipart(
          item.endpoint,
          fields: item.fields,
          file: photoFile,
          fileField: 'photo',
        );

        if (response.statusCode == 200 || response.statusCode == 201) {
          syncedCount++;
        } else {
          remainingQueue.add(itemStr);
        }
      } catch (e) {
        // Si aún no hay conexión o falla, se conserva en la cola
        remainingQueue.add(itemStr);
      }
    }

    await prefs.setStringList(_queueKey, remainingQueue);
    return syncedCount;
  }
}
