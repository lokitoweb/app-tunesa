import 'dart:convert';
import 'dart:math';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../../../core/storage/secure_key_store.dart';
import '../domain/models.dart';

/// Denuncias guardadas en este celular, cifradas en el Keystore / Keychain.
/// No se suben a ningún lado ni se asocian a la cuenta.
class EthicsVault {
  EthicsVault(this._store);

  final SecureKeyValueStore _store;

  static const _key = 'ethics_cases_v1';

  Future<List<SavedEthicsCase>> list() async {
    final raw = await _store.read(_key);
    if (raw == null) return const [];
    try {
      return (jsonDecode(raw) as List).map((e) => SavedEthicsCase.fromJson(Map<String, dynamic>.from(e as Map))).toList();
    } catch (_) {
      return const [];
    }
  }

  Future<void> save(SavedEthicsCase item) async {
    final all = [...(await list()).where((c) => c.code != item.code), item];
    await _store.write(_key, jsonEncode(all.map((c) => c.toJson()).toList()));
  }

  Future<void> remove(String code) async {
    final all = (await list()).where((c) => c.code != code).toList();
    await _store.write(_key, jsonEncode(all.map((c) => c.toJson()).toList()));
  }
}

/// Avisos de novedades sin identificar al teléfono: la app escucha un tema de
/// FCM aleatorio por denuncia; el servidor solo conoce ese nombre.
class EthicsTopics {
  const EthicsTopics();

  /// Canal nuevo (32 caracteres aleatorios).
  String newChannel([Random? random]) {
    final r = random ?? Random.secure();
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    return List.generate(32, (_) => chars[r.nextInt(chars.length)]).join();
  }

  Future<bool> subscribe(String channel) => _run((m) => m.subscribeToTopic('ethics_$channel'));

  Future<bool> unsubscribe(String channel) => _run((m) => m.unsubscribeFromTopic('ethics_$channel'));

  Future<bool> _run(Future<void> Function(FirebaseMessaging m) action) async {
    try {
      if (Firebase.apps.isEmpty) return false;
      await action(FirebaseMessaging.instance);
      return true;
    } catch (e) {
      debugPrint('Canal ético sin push: $e');
      return false;
    }
  }
}
