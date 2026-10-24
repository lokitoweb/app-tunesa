import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import '../config/app_config_controller.dart';

/// Caché de logos en disco (directorio de soporte de la app) + memoria, para
/// que la marca se vea al instante y sin red en los siguientes arranques.
class LogoCache {
  LogoCache._();

  static final Map<String, Uint8List> _memory = {};
  static final Map<String, Future<Uint8List?>> _pending = {};

  /// Bytes ya cargados en esta sesión (sin esperar).
  static Uint8List? peek(String url) => _memory[url];

  static Future<Uint8List?> load(String url) {
    final hit = _memory[url];
    if (hit != null) return SynchronousFuture(hit);
    // Bloque sin valor: `remove` devuelve este mismo Future y whenComplete lo
    // esperaría (nunca terminaría).
    return _pending[url] ??= _load(url).whenComplete(() {
      _pending.remove(url);
    });
  }

  static Future<Uint8List?> _load(String url) async {
    File? file;
    try {
      final dir = await getApplicationSupportDirectory();
      file = File('${dir.path}/brand_cache/${_fnv1a(url)}');
      if (await file.exists()) {
        final bytes = await file.readAsBytes();
        if (bytes.isNotEmpty) {
          // Se refresca en segundo plano por si el logo cambió en la misma URL.
          unawaited(_download(url, file));
          return _memory[url] = bytes;
        }
      }
    } catch (_) {
      file = null; // sin path_provider (tests / plataforma no soportada)
    }
    final bytes = await _download(url, file);
    if (bytes != null) _memory[url] = bytes;
    return bytes;
  }

  static Future<Uint8List?> _download(String url, File? file) async {
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 10);
    try {
      final request = await client.getUrl(Uri.parse(url));
      final response = await request.close().timeout(const Duration(seconds: 15));
      if (response.statusCode != 200) return null;
      final builder = BytesBuilder(copy: false);
      await for (final chunk in response.timeout(const Duration(seconds: 15))) {
        builder.add(chunk);
      }
      final bytes = builder.takeBytes();
      if (bytes.isEmpty) return null;
      if (file != null) {
        await file.parent.create(recursive: true);
        await file.writeAsBytes(bytes, flush: true);
      }
      return bytes;
    } catch (_) {
      return null;
    } finally {
      client.close(force: true);
    }
  }

  /// Hash FNV-1a de 32 bits, estable entre ejecuciones (nombre de archivo).
  static String _fnv1a(String input) {
    var hash = 0x811c9dc5;
    for (final unit in input.codeUnits) {
      hash ^= unit;
      hash = (hash * 0x01000193) & 0xFFFFFFFF;
    }
    return hash.toRadixString(16).padLeft(8, '0');
  }
}

/// Logo de la empresa: `logo_dark_url` en tema oscuro (si existe), si no
/// `logo_url`; sin logo o si falla, una marca genérica.
class BrandLogo extends ConsumerWidget {
  const BrandLogo({super.key, this.height = 72});

  final double height;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final branding = ref.watch(appConfigProvider.select((s) => s.config.branding));
    final dark = Theme.of(context).brightness == Brightness.dark;
    final url = branding.logoFor(dark: dark);

    final Widget logo = url == null
        ? _FallbackLogo(size: height)
        : _NetworkLogo(key: ValueKey(url), url: url, height: height);

    return Semantics(label: 'Logo', child: Center(child: logo));
  }
}

class _NetworkLogo extends StatefulWidget {
  const _NetworkLogo({super.key, required this.url, required this.height});

  final String url;
  final double height;

  @override
  State<_NetworkLogo> createState() => _NetworkLogoState();
}

class _NetworkLogoState extends State<_NetworkLogo> {
  late Future<Uint8List?> _bytes;

  @override
  void initState() {
    super.initState();
    _bytes = LogoCache.load(widget.url);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Uint8List?>(
      future: _bytes,
      initialData: LogoCache.peek(widget.url),
      builder: (context, snapshot) {
        final bytes = snapshot.data;
        if (bytes == null) {
          return snapshot.connectionState == ConnectionState.done
              ? _FallbackLogo(size: widget.height)
              : SizedBox(height: widget.height);
        }
        return Image.memory(
          bytes,
          height: widget.height,
          fit: BoxFit.contain,
          gaplessPlayback: true,
          errorBuilder: (_, _, _) => _FallbackLogo(size: widget.height),
        );
      },
    );
  }
}

class _FallbackLogo extends StatelessWidget {
  const _FallbackLogo({required this.size});

  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    // Marca genérica: la app no trae el logo de ninguna empresa
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(size / 3)),
      child: Icon(Icons.fingerprint_rounded, size: size * 0.66, color: scheme.onPrimaryContainer),
    );
  }
}
