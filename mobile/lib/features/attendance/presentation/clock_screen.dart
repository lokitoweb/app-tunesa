import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../../core/location/location_service.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'attendance_providers.dart';
import 'selfie_screen.dart';

/// Flujo de marcación: GPS estricto → validación de geocerca → selfie → envío.
///
/// Sin red no se marca: no existe cola offline a propósito (una marcación
/// diferida no se puede verificar contra la hora y ubicación reales).
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
      final position = await ref.read(locationServiceProvider).getVerifiedPosition();
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
    setState(() {
      _selfie = shot;
      _selfieBytes = bytes;
    });
  }

  Future<void> _send() async {
    final online = ref.read(connectivityProvider).value ?? true;
    if (!online) {
      showSnack(context, 'Sin conexión. Conéctate a internet para marcar.', error: true);
      return;
    }

    // Una lectura vieja no sirve: se vuelve a leer el GPS antes de enviar.
    if (_position == null || DateTime.now().difference(_position!.timestamp) > _maxPositionAge) {
      await _locate();
      if (_position == null) return;
    }

    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      final message = await ref.read(attendanceRepositoryProvider).clock(widget.action, _position!, selfie: _selfie);
      ref.invalidate(todayStatusProvider);
      if (!mounted) return;
      showSnack(context, message);
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _sendError = e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final needsSelfie = widget.action.requiresSelfie;
    final canSend = _position != null && (!needsSelfie || _selfie != null) && !_sending && !_locating;

    return Scaffold(
      appBar: AppBar(title: Text(widget.action.label)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
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
    return Row(children: [
      ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Image.memory(_selfieBytes!, width: 72, height: 96, fit: BoxFit.cover),
      ),
      const SizedBox(width: 12),
      TextButton.icon(onPressed: _takeSelfie, icon: const Icon(Icons.refresh), label: const Text('Repetir')),
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

class _SendErrorCard extends StatelessWidget {
  const _SendErrorCard({required this.error, required this.onRelocate});

  final ApiException error;
  final VoidCallback onRelocate;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final gpsIssue = const {'GEOFENCE_VIOLATION', 'MOCK_LOCATION_DETECTED', 'GPS_ACCURACY_TOO_LOW'}.contains(error.code);

    return Card(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(error.message, style: TextStyle(color: scheme.onErrorContainer)),
          if (gpsIssue)
            TextButton(onPressed: onRelocate, child: const Text('Volver a leer el GPS')),
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
