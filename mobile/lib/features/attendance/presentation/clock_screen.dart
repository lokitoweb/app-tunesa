import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/location/location_service.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/widgets/async_views.dart';
import '../../auth/presentation/auth_controller.dart';
import '../domain/models.dart';
import 'attendance_providers.dart';
import 'offline_sync.dart';
import 'selfie_screen.dart';

/// Flujo de marcación: GPS estricto → validación de geocerca → selfie → envío.
///
/// Las reglas salen de la configuración de la empresa (`/v1/app/config`):
/// selfie obligatoria o no, detección de rostro, precisión GPS máxima y
/// modo sin conexión. Sin red y sin modo offline no se marca; con modo
/// offline la marcación se guarda cifrada y se envía al recuperar señal.
class ClockScreen extends ConsumerStatefulWidget {
  const ClockScreen({super.key, required this.action});

  final ClockAction action;

  @override
  ConsumerState<ClockScreen> createState() => _ClockScreenState();
}

class _ClockScreenState extends ConsumerState<ClockScreen> {
  static const _maxPositionAge = Duration(seconds: 60);

  Position? _position;
  LocationException? _locationError;
  bool _locating = false;

  XFile? _selfie;
  Uint8List? _selfieBytes;

  /// Resultado de ML Kit (solo si la empresa exige detección de rostro).
  bool? _faceDetected;
  bool _detectingFace = false;

  bool _sending = false;
  ApiException? _sendError;

  @override
  void initState() {
    super.initState();
    _locate();
  }

  Future<void> _locate() async {
    setState(() {
      _locating = true;
      _locationError = null;
    });
    try {
      final maxAccuracy = ref.read(appConfigProvider).config.mobile.maxGpsAccuracyMeters;
      final position = await ref.read(locationServiceProvider).getVerifiedPosition(maxAccuracyMeters: maxAccuracy);
      if (mounted) setState(() => _position = position);
    } on LocationException catch (e) {
      if (mounted) setState(() => _locationError = e);
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _takeSelfie() async {
    final shot = await Navigator.of(context).push<XFile>(
      MaterialPageRoute(builder: (_) => const SelfieScreen(), fullscreenDialog: true),
    );
    if (shot == null) return;
    final bytes = await shot.readAsBytes();
    final detect = ref.read(appConfigProvider).config.mobile.faceDetectionRequired;
    setState(() {
      _selfie = shot;
      _selfieBytes = bytes;
      _faceDetected = null;
      _detectingFace = detect;
    });
    if (!detect) return;
    final face = await ref.read(faceDetectionServiceProvider).hasFace(shot.path);
    if (mounted && _selfie == shot) {
      setState(() {
        _faceDetected = face;
        _detectingFace = false;
      });
    }
  }

  bool get _needsSelfie =>
      widget.action.requiresSelfie && ref.read(appConfigProvider).config.mobile.selfieRequired;

  /// Sin conexión y con modo offline: se guarda cifrada en el teléfono.
  Future<void> _saveOffline() async {
    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      final item = await ref.read(attendanceRepositoryProvider).buildPending(
            widget.action,
            _position!,
            userId: ref.read(authControllerProvider).user?.id,
            selfie: _needsSelfie ? _selfie : null,
            faceDetected: _needsSelfie ? _faceDetected : null,
          );
      await ref.read(offlineSyncProvider.notifier).enqueue(item);
      if (!mounted) return;
      showSnack(context, 'Marcación guardada sin conexión, se enviará al recuperar señal.');
      Navigator.of(context).pop(true);
    } catch (_) {
      if (mounted) showSnack(context, 'No se pudo guardar la marcación en el teléfono.', error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _send() async {
    final online = ref.read(connectivityProvider).value ?? true;
    final offlineMode = ref.read(appConfigProvider).config.mobile.offlineModeEnabled;
    if (!online && !offlineMode) {
      showSnack(context, 'Sin conexión. Conéctate a internet para marcar.', error: true);
      return;
    }

    // Una lectura vieja no sirve: se vuelve a leer el GPS antes de enviar.
    if (_position == null || DateTime.now().difference(_position!.timestamp) > _maxPositionAge) {
      await _locate();
      if (_position == null) return;
    }

    if (!online) return _saveOffline();

    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      final message = await ref.read(attendanceRepositoryProvider).clock(
            widget.action,
            _position!,
            selfie: _needsSelfie ? _selfie : null,
            faceDetected: _needsSelfie ? _faceDetected : null,
          );
      ref.invalidate(todayStatusProvider);
      if (!mounted) return;
      showSnack(context, message);
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      // La señal se cayó justo al enviar (no llegó al servidor): a la cola.
      if (e.isConnectionError && offlineMode && mounted) {
        setState(() => _sending = false);
        return _saveOffline();
      }
      if (mounted) setState(() => _sendError = e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mobile = ref.watch(appConfigProvider.select((s) => s.config.mobile));
    final pending = ref.watch(offlineSyncProvider.select((s) => s.pending));
    final needsSelfie = widget.action.requiresSelfie && mobile.selfieRequired;
    final faceBlocked = needsSelfie && mobile.faceDetectionRequired && (_detectingFace || _faceDetected == false);
    final canSend =
        _position != null && (!needsSelfie || _selfie != null) && !faceBlocked && !_sending && !_locating;

    return Scaffold(
      appBar: AppBar(title: Text(widget.action.label)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (pending > 0) ...[
            _PendingCard(count: pending),
            const SizedBox(height: 12),
          ],
          _StepCard(
            number: 1,
            title: 'Ubicación GPS',
            done: _position != null,
            child: _locationStep(),
          ),
          const SizedBox(height: 12),
          if (needsSelfie) ...[
            _StepCard(
              number: 2,
              title: 'Selfie (cámara frontal)',
              done: _selfie != null,
              child: _selfieStep(),
            ),
            const SizedBox(height: 12),
          ],
          if (_sendError != null) _SendErrorCard(error: _sendError!, onRelocate: _locate),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: canSend ? _send : null,
            icon: _sending
                ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.5))
                : const Icon(Icons.fingerprint),
            label: Text(_sending ? 'Enviando…' : 'Confirmar ${widget.action.label.toLowerCase()}'),
          ),
        ],
      ),
    );
  }

  Widget _locationStep() {
    if (_locating) {
      return const Row(children: [
        SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.5)),
        SizedBox(width: 12),
        Expanded(child: Text('Obteniendo tu ubicación precisa…')),
      ]);
    }

    final error = _locationError;
    if (error != null) {
      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(error.message, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        const SizedBox(height: 8),
        Wrap(spacing: 8, children: [
          OutlinedButton(onPressed: _locate, child: const Text('Reintentar')),
          if (error.canOpenSettings)
            TextButton(
              onPressed: () => ref.read(locationServiceProvider).openSettings(error.failure),
              child: const Text('Abrir ajustes'),
            ),
        ]),
      ]);
    }

    final position = _position;
    if (position == null) return const SizedBox.shrink();

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('Precisión: ±${position.accuracy.round()} m'),
      const SizedBox(height: 4),
      _GeofenceHint(position: position),
      TextButton.icon(onPressed: _locate, icon: const Icon(Icons.my_location, size: 18), label: const Text('Actualizar')),
    ]);
  }

  Widget _selfieStep() {
    if (_selfieBytes == null) {
      return OutlinedButton.icon(
        onPressed: _takeSelfie,
        icon: const Icon(Icons.camera_front_outlined),
        label: const Text('Tomar selfie'),
      );
    }
    final scheme = Theme.of(context).colorScheme;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: Image.memory(_selfieBytes!, width: 72, height: 96, fit: BoxFit.cover),
        ),
        const SizedBox(width: 12),
        TextButton.icon(onPressed: _takeSelfie, icon: const Icon(Icons.refresh), label: const Text('Repetir')),
      ]),
      if (_detectingFace)
        const Padding(padding: EdgeInsets.only(top: 8), child: Text('Verificando tu rostro…'))
      else if (_faceDetected == false)
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Text('No detectamos un rostro en la foto. Repítela con tu cara centrada y buena luz.',
              style: TextStyle(color: scheme.error)),
        ),
    ]);
  }
}

/// Validación previa de geocerca en el teléfono (el backend decide al final).
class _GeofenceHint extends ConsumerWidget {
  const _GeofenceHint({required this.position});

  final Position position;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final branches = ref.watch(myBranchesProvider);
    final scheme = Theme.of(context).colorScheme;

    return branches.when(
      loading: () => const Text('Verificando sucursales…'),
      error: (_, _) => const Text('No se pudo verificar la sucursal; el servidor la validará.'),
      data: (list) {
        if (list.isEmpty) return const Text('No tienes sucursales asignadas. Consulta con RRHH.');

        BranchGeofence? nearest;
        var nearestDistance = double.infinity;
        for (final b in list) {
          final d = LocationService.haversineMeters(position.latitude, position.longitude, b.lat, b.lng);
          if (d < nearestDistance) {
            nearest = b;
            nearestDistance = d;
          }
        }
        final inside = nearestDistance <= nearest!.radius;

        return Row(children: [
          Icon(inside ? Icons.check_circle : Icons.warning_amber_rounded,
              color: inside ? Colors.green : scheme.error, size: 20),
          const SizedBox(width: 6),
          Expanded(
            child: Text(inside
                ? 'Estás en ${nearest.name} (${nearestDistance.round()} m).'
                : 'Estás a ${nearestDistance.round()} m de ${nearest.name} (radio permitido ${nearest.radius.round()} m). '
                    'Acércate a la sucursal para marcar.'),
          ),
        ]);
      },
    );
  }
}

/// Marcaciones guardadas sin conexión que aún no se envían.
class _PendingCard extends ConsumerStatefulWidget {
  const _PendingCard({required this.count});

  final int count;

  @override
  ConsumerState<_PendingCard> createState() => _PendingCardState();
}

class _PendingCardState extends ConsumerState<_PendingCard> {
  bool _syncing = false;

  Future<void> _sync() async {
    setState(() => _syncing = true);
    final result = await ref.read(offlineSyncProvider.notifier).sync();
    if (!mounted) return;
    setState(() => _syncing = false);
    if (result != null && result.networkFailed) {
      showSnack(context, 'Aún sin conexión con el servidor. Se reintentará al recuperar señal.', error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final n = widget.count;
    return Card(
      color: scheme.tertiaryContainer,
      child: ListTile(
        leading: Icon(Icons.cloud_upload_outlined, color: scheme.onTertiaryContainer),
        title: Text(n == 1 ? '1 marcación pendiente de envío' : '$n marcaciones pendientes de envío',
            style: TextStyle(color: scheme.onTertiaryContainer)),
        subtitle: Text('Guardadas sin conexión; se envían al recuperar señal.',
            style: TextStyle(color: scheme.onTertiaryContainer)),
        trailing: _syncing
            ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.5))
            : IconButton(tooltip: 'Enviar ahora', icon: const Icon(Icons.sync), onPressed: _sync),
      ),
    );
  }
}

class _SendErrorCard extends StatelessWidget {
  const _SendErrorCard({required this.error, required this.onRelocate});

  final ApiException error;
  final VoidCallback onRelocate;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final gpsIssue = const {'GEOFENCE_VIOLATION', 'MOCK_LOCATION_DETECTED', 'GPS_ACCURACY_TOO_LOW'}.contains(error.code);
    final faceIssue = error.code == 'FACE_NOT_DETECTED';

    return Card(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(error.message, style: TextStyle(color: scheme.onErrorContainer)),
          if (gpsIssue)
            TextButton(onPressed: onRelocate, child: const Text('Volver a leer el GPS')),
          if (faceIssue)
            Text('Repite la selfie con tu rostro centrado y buena luz.', style: TextStyle(color: scheme.onErrorContainer)),
        ]),
      ),
    );
  }
}

class _StepCard extends StatelessWidget {
  const _StepCard({required this.number, required this.title, required this.done, required this.child});

  final int number;
  final String title;
  final bool done;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            CircleAvatar(
              radius: 14,
              backgroundColor: done ? Colors.green : scheme.primary,
              child: done
                  ? const Icon(Icons.check, size: 16, color: Colors.white)
                  : Text('$number', style: TextStyle(color: scheme.onPrimary, fontSize: 13)),
            ),
            const SizedBox(width: 10),
            Text(title, style: Theme.of(context).textTheme.titleMedium),
          ]),
          const SizedBox(height: 12),
          child,
        ]),
      ),
    );
  }
}
