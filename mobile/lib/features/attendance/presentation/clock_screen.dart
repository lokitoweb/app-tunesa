import 'dart:async';
import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config.dart';
import '../../../core/config/app_config_controller.dart';
import '../../../core/location/location_service.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../data/offline_sync_engine.dart';
import '../domain/models.dart';
import '../domain/punch.dart';
import 'attendance_providers.dart';
import 'offline_sync.dart';
import 'selfie_screen.dart';

/// Flujo de marcación: GPS estricto → validación de geocerca → selfie → envío.
///
/// Las reglas salen de la configuración de la empresa (`/v1/app/config`):
/// selfie obligatoria o no, detección de rostro, precisión GPS máxima,
/// política de ubicación simulada y modo sin conexión.
///
/// Lo que se envía se toma en el momento de la marcación: al disparar la
/// selfie se pide una lectura GPS fresca y se guarda la hora UTC del teléfono
/// (sin selfie, al confirmar). Cada captura tiene un `client_uuid` que se
/// reusa en los reintentos (el backend no la duplica). Sin red y con modo
/// offline la captura va a la cola cifrada.
class ClockScreen extends ConsumerStatefulWidget {
  const ClockScreen({super.key, required this.action});

  final ClockAction action;

  @override
  ConsumerState<ClockScreen> createState() => _ClockScreenState();
}

class _ClockScreenState extends ConsumerState<ClockScreen> {
  /// Una selfie más vieja que esto ya no representa "el momento" de marcar.
  static const _maxShotAge = Duration(minutes: 5);

  /// Lectura de referencia (geocerca y dirección).
  LocationFix? _preview;
  LocationException? _locationError;
  bool _locating = false;

  XFile? _selfie;
  Uint8List? _selfieBytes;
  DateTime? _shotAt;

  /// GPS leído al disparar la selfie (el que se envía).
  LocationFix? _shotFix;
  bool _shotLocating = false;

  /// Resultado de ML Kit (solo si la empresa exige detección de rostro).
  bool? _faceDetected;
  bool _detectingFace = false;

  /// Captura lista para enviar: se conserva entre reintentos (mismo client_uuid).
  PunchCapture? _capture;

  bool _sending = false;
  ApiException? _sendError;

  MobileConfig get _mobile => ref.read(appConfigProvider).config.mobile;

  @override
  void initState() {
    super.initState();
    _locate();
  }

  /// Lectura verificada que nunca lanza (se pide al disparar y se espera después).
  Future<(LocationFix?, LocationException?)> _safeFix() async {
    try {
      final fix = await ref.read(locationServiceProvider).getVerifiedFix(
            maxAccuracyMeters: _mobile.maxGpsAccuracyMeters,
            mockPolicy: _mobile.mockLocationPolicy,
          );
      return (fix, null);
    } on LocationException catch (e) {
      return (null, e);
    }
  }

  Future<void> _locate() async {
    setState(() {
      _locating = true;
      _locationError = null;
    });
    final (fix, error) = await _safeFix();
    if (!mounted) return;
    setState(() {
      _locating = false;
      _locationError = error;
      if (fix != null) _preview = fix;
    });
  }

  Future<void> _takeSelfie() async {
    Future<(LocationFix?, LocationException?)>? shotFix;
    final shot = await Navigator.of(context).push<SelfieShot>(
      MaterialPageRoute(
        builder: (_) => SelfieScreen(onShutter: () => shotFix = _safeFix()),
        fullscreenDialog: true,
      ),
    );
    if (shot == null || !mounted) return;
    final bytes = await shot.file.readAsBytes();
    final detect = _mobile.faceDetectionRequired;
    setState(() {
      _selfie = shot.file;
      _selfieBytes = bytes;
      _shotAt = shot.takenAt;
      _shotFix = null;
      _shotLocating = true;
      _capture = null;
      _sendError = null;
      _faceDetected = null;
      _detectingFace = detect;
    });

    final face = detect ? ref.read(faceDetectionServiceProvider).hasFace(shot.file.path) : Future<bool?>.value(null);
    final (fix, error) = await (shotFix ?? _safeFix());
    if (!mounted || _selfie != shot.file) return;
    setState(() {
      _shotLocating = false;
      _shotFix = fix;
      if (fix != null) _preview = fix;
      _locationError = error;
    });
    if (!detect) return;
    final detected = await face;
    if (mounted && _selfie == shot.file) {
      setState(() {
        _faceDetected = detected;
        _detectingFace = false;
      });
    }
  }

  bool get _needsSelfie => widget.action.requiresSelfie && _mobile.selfieRequired;

  /// Descansos: selfie opcional (si la empresa usa selfie).
  bool get _optionalSelfie => !widget.action.requiresSelfie && _mobile.selfieRequired;

  /// Arma (una sola vez) lo que se envía. Con selfie usa el GPS y la hora del
  /// disparo; sin selfie lee el GPS en este momento.
  Future<PunchCapture?> _buildCapture() async {
    final existing = _capture;
    if (existing != null) return existing;

    final hasShot = _selfie != null && (_needsSelfie || _optionalSelfie);
    final LocationFix fix;
    final DateTime deviceTime;
    if (hasShot) {
      final shotFix = _shotFix;
      if (DateTime.now().toUtc().difference(_shotAt!) > _maxShotAge) {
        showSnack(context, 'La selfie tiene más de 5 minutos. Tómala de nuevo para marcar.', error: true);
        setState(_clearShot);
        return null;
      }
      if (shotFix == null) {
        showSnack(context, 'No se pudo leer el GPS al tomar la selfie. Repite la selfie.', error: true);
        return null;
      }
      fix = shotFix;
      deviceTime = _shotAt!;
    } else {
      setState(() => _locating = true);
      final (fresh, error) = await _safeFix();
      final now = DateTime.now().toUtc();
      if (!mounted) return null;
      setState(() {
        _locating = false;
        _locationError = error;
        if (fresh != null) _preview = fresh;
      });
      if (fresh == null) return null;
      fix = fresh;
      deviceTime = now;
    }

    final photo = hasShot ? await ref.read(photoCompressorProvider).compress(_selfieBytes!) : null;
    return _capture = PunchCapture(
      action: widget.action,
      latitude: fix.latitude,
      longitude: fix.longitude,
      accuracy: fix.accuracy,
      isMocked: fix.isMocked,
      deviceTime: deviceTime,
      faceDetected: hasShot ? _faceDetected : null,
      photo: photo,
    );
  }

  void _clearShot() {
    _selfie = null;
    _selfieBytes = null;
    _shotAt = null;
    _shotFix = null;
    _shotLocating = false;
    _faceDetected = null;
    _detectingFace = false;
    _capture = null;
  }

  static String _withMockWarning(String message, PunchCapture capture) =>
      capture.isMocked ? '$message\n$mockFlagWarning' : message;

  /// Sin conexión y con modo offline: se guarda cifrada en el teléfono.
  Future<void> _saveOffline(PunchCapture capture) async {
    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      await ref.read(offlineSyncProvider.notifier).enqueue(capture);
      if (!mounted) return;
      showSnack(context, _withMockWarning('Marcación guardada sin conexión, se enviará al recuperar señal.', capture));
      Navigator.of(context).pop(true);
    } catch (_) {
      if (mounted) showSnack(context, 'No se pudo guardar la marcación en el teléfono.', error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _send() async {
    final online = ref.read(connectivityProvider).value ?? true;
    final offlineMode = _mobile.offlineModeEnabled;
    if (!online && !offlineMode) {
      showSnack(context, 'Sin conexión. Conéctate a internet para marcar.', error: true);
      return;
    }

    setState(() => _sendError = null);
    final capture = await _buildCapture();
    if (capture == null || !mounted) return;

    if (!online) return _saveOffline(capture);

    setState(() => _sending = true);
    try {
      final outcome = await ref.read(attendanceRepositoryProvider).sendPunch(capture);
      ref.invalidate(todayStatusProvider);
      if (!mounted) return;
      showSnack(context, _withMockWarning(outcome.message, capture));
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      // Sin respuesta: a la cola con el mismo client_uuid (si el servidor sí
      // la recibió, la cola obtendrá `duplicate` y la dará por enviada).
      if (e.isNetwork && offlineMode && mounted) {
        setState(() => _sending = false);
        return _saveOffline(capture);
      }
      // Error de negocio: el próximo intento es una captura nueva. Con 5xx /
      // 429 el servidor pudo guardarla: se conserva el mismo client_uuid.
      if (!OfflineSyncEngine.isRetryable(e)) _capture = null;
      if (mounted) setState(() => _sendError = e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  /// "Volver a leer el GPS": con selfie, el GPS va con la foto (se repite).
  void _relocate() {
    setState(() {
      _sendError = null;
      _capture = null;
      if (_selfie != null) _clearShot();
    });
    _locate();
  }

  @override
  Widget build(BuildContext context) {
    final mobile = ref.watch(appConfigProvider.select((s) => s.config.mobile));
    final sync = ref.watch(offlineSyncProvider);
    final drift = ref.watch(todayStatusProvider).value?.driftBeyond(mobile.maxTimeDriftSeconds);
    final needsSelfie = widget.action.requiresSelfie && mobile.selfieRequired;
    final optionalSelfie = !widget.action.requiresSelfie && mobile.selfieRequired;
    final hasShot = _selfie != null;
    final faceBlocked = hasShot && mobile.faceDetectionRequired && (_detectingFace || _faceDetected == false);
    final busy = _sending || _locating || _shotLocating;
    final located = needsSelfie ? hasShot && _shotFix != null : (_preview != null || _capture != null);
    final canSend = located && !faceBlocked && !busy;
    final mocked = (_shotFix ?? _preview)?.isMocked ?? false;

    return Scaffold(
      appBar: AppBar(title: Text(widget.action.label)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (sync.pending > 0 || sync.lastSyncAt != null) ...[
            _PendingCard(count: sync.pending, lastSyncAt: sync.lastSyncAt, syncing: sync.syncing),
            const SizedBox(height: 12),
          ],
          if (sync.rejected > 0) ...[
            _RejectedCard(count: sync.rejected),
            const SizedBox(height: 12),
          ],
          if (drift != null) ...[
            _NoticeCard(
              icon: Icons.schedule,
              text: 'La hora de tu teléfono difiere ${(drift / 60).ceil()} min de la hora oficial. '
                  'Activa "Fecha y hora automáticas" en Ajustes.',
            ),
            const SizedBox(height: 12),
          ],
          _StepCard(
            number: 1,
            title: 'Ubicación GPS',
            done: (_shotFix ?? _preview) != null,
            child: _locationStep(),
          ),
          const SizedBox(height: 12),
          if (needsSelfie || optionalSelfie) ...[
            _StepCard(
              number: 2,
              title: needsSelfie ? 'Selfie (cámara frontal)' : 'Selfie (opcional)',
              done: hasShot,
              child: _selfieStep(),
            ),
            const SizedBox(height: 12),
          ],
          if (mocked) ...[
            const _NoticeCard(icon: Icons.report_gmailerrorred_outlined, text: mockFlagWarning),
            const SizedBox(height: 12),
          ],
          if (_sendError != null) _SendErrorCard(error: _sendError!, onRelocate: _relocate),
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
    final fix = _shotFix ?? _preview;
    if (_locating && fix == null) {
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
          OutlinedButton(onPressed: _locating ? null : _relocate, child: const Text('Reintentar')),
          if (error.canOpenSettings)
            TextButton(
              onPressed: () => ref.read(locationServiceProvider).openSettings(error.failure),
              child: const Text('Abrir ajustes'),
            ),
        ]),
      ]);
    }

    if (fix == null) return const SizedBox.shrink();

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('Precisión: ±${fix.accuracy.round()} m'),
      const SizedBox(height: 4),
      _AddressLine(lat: fix.latitude, lng: fix.longitude),
      const SizedBox(height: 4),
      _GeofenceHint(position: fix.position),
      if (_shotFix == null)
        TextButton.icon(
          onPressed: _locating ? null : _locate,
          icon: const Icon(Icons.my_location, size: 18),
          label: const Text('Actualizar'),
        )
      else
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text('Ubicación tomada al momento de la selfie.', style: Theme.of(context).textTheme.bodySmall),
        ),
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
      if (_shotLocating)
        const Padding(padding: EdgeInsets.only(top: 8), child: Text('Leyendo tu ubicación al momento de la foto…')),
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

/// Dirección legible de la posición (espera 600 ms entre cambios y caché por
/// ~50 m). Solo informativa: nunca bloquea la marcación.
class _AddressLine extends ConsumerStatefulWidget {
  const _AddressLine({required this.lat, required this.lng});

  final double lat;
  final double lng;

  @override
  ConsumerState<_AddressLine> createState() => _AddressLineState();
}

class _AddressLineState extends ConsumerState<_AddressLine> {
  static const _debounce = Duration(milliseconds: 600);

  Timer? _timer;
  String? _address;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _schedule();
  }

  @override
  void didUpdateWidget(covariant _AddressLine old) {
    super.didUpdateWidget(old);
    if (old.lat != widget.lat || old.lng != widget.lng) _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    final geocoder = ref.read(reverseGeocoderProvider);
    final cached = geocoder.cached(widget.lat, widget.lng);
    if (cached != null) {
      _address = cached;
      _loading = false;
      return;
    }
    _loading = _address == null;
    _timer = Timer(_debounce, () async {
      final lat = widget.lat;
      final lng = widget.lng;
      final address = await geocoder.resolve(lat, lng);
      if (!mounted || lat != widget.lat || lng != widget.lng) return;
      setState(() {
        _address = address ?? _address;
        _loading = false;
      });
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_address == null && !_loading) return const SizedBox.shrink();
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Icon(Icons.place_outlined, size: 18),
      const SizedBox(width: 6),
      Expanded(child: Text(_address ?? 'Buscando dirección…')),
    ]);
  }
}

class _NoticeCard extends StatelessWidget {
  const _NoticeCard({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.secondaryContainer,
      child: ListTile(
        leading: Icon(icon, color: scheme.onSecondaryContainer),
        title: Text(text, style: TextStyle(color: scheme.onSecondaryContainer)),
      ),
    );
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

/// Marcaciones sin conexión que aún no se envían y la última sincronización.
class _PendingCard extends ConsumerWidget {
  const _PendingCard({required this.count, this.lastSyncAt, this.syncing = false});

  final int count;
  final DateTime? lastSyncAt;
  final bool syncing;

  Future<void> _sync(BuildContext context, WidgetRef ref) async {
    final result = await ref.read(offlineSyncProvider.notifier).sync(force: true);
    if (!context.mounted) return;
    if (result != null && result.networkFailed) {
      showSnack(context, 'Aún sin conexión con el servidor. Se reintentará al recuperar señal.', error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final last = lastSyncAt;
    final lastLabel =
        last == null ? 'Aún no se sincroniza.' : 'Última sincronización: ${Fmt.date(last)} ${Fmt.time(last)}';
    final color = count > 0 ? scheme.onTertiaryContainer : scheme.onSurfaceVariant;
    return Card(
      color: count > 0 ? scheme.tertiaryContainer : null,
      child: ListTile(
        leading: Icon(count > 0 ? Icons.cloud_upload_outlined : Icons.cloud_done_outlined, color: color),
        title: Text(
          switch (count) {
            0 => 'Sin marcaciones pendientes',
            1 => '1 marcación pendiente de envío',
            _ => '$count marcaciones pendientes de envío',
          },
          style: TextStyle(color: color),
        ),
        subtitle: Text(
          count > 0 ? 'Guardadas sin conexión; se envían al recuperar señal.\n$lastLabel' : lastLabel,
          style: TextStyle(color: color),
        ),
        isThreeLine: count > 0,
        trailing: count == 0
            ? null
            : syncing
                ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.5))
                : IconButton(
                    tooltip: 'Enviar ahora',
                    icon: const Icon(Icons.sync),
                    onPressed: () => _sync(context, ref),
                  ),
      ),
    );
  }
}

/// Acceso a "Marcaciones no aceptadas".
class _RejectedCard extends StatelessWidget {
  const _RejectedCard({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.errorContainer,
      child: ListTile(
        leading: Icon(Icons.error_outline, color: scheme.onErrorContainer),
        title: Text(
          count == 1 ? '1 marcación no aceptada' : '$count marcaciones no aceptadas',
          style: TextStyle(color: scheme.onErrorContainer),
        ),
        subtitle: Text('Toca para ver el motivo.', style: TextStyle(color: scheme.onErrorContainer)),
        trailing: Icon(Icons.chevron_right, color: scheme.onErrorContainer),
        onTap: () => GoRouter.of(context).push('/attendance/rejected'),
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
