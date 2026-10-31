import 'dart:async';
import 'dart:math';
import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';

import '../../onboarding/presentation/onboarding_providers.dart';
import '../../../core/config/app_config.dart';
import '../../../core/config/app_config_controller.dart';
import '../../../core/location/location_service.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../data/attempt_reporter.dart';
import '../data/offline_sync_engine.dart';
import '../domain/models.dart';
import '../domain/punch.dart';
import 'attendance_providers.dart';
import 'offline_sync.dart';
import 'punch_disclosure.dart';
import 'selfie_screen.dart';

/// Flujo de marcación directo: GPS → geocerca → cámara → envío automático.
///
/// Al abrir la pantalla se lee el GPS y se valida la geocerca. Si la persona
/// está en su agencia (o la empresa no exige geocerca estricta) la cámara
/// frontal se abre sola; al aceptar la foto la marcación se envía sin más
/// pasos. Fuera del perímetro no se abre la cámara y se explica por qué.
/// Sin selfie (empresa que no la usa) queda un único botón de confirmar.
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

  /// Nombre de la marcación; en horario partido la pausa es fin e inicio de tramo.
  String get _label => widget.action.labelFor(split: ref.read(todayStatusProvider).value?.isSplit == true);

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

  /// La cámara ya se abrió sola una vez (no se reabre al cancelarla).
  bool _autoOpened = false;
  bool _cameraOpen = false;

  /// Captura lista para enviar: se conserva entre reintentos (mismo client_uuid).
  PunchCapture? _capture;

  bool _sending = false;
  ApiException? _sendError;

  /// Salida antes de hora: la persona ya confirmó que quiere salir igual.
  bool _earlyConfirmed = false;
  bool _earlyAsked = false;

  MobileConfig get _mobile => ref.read(appConfigProvider).config.mobile;

  /// Aviso de jornada incompleta (solo al marcar salida)
  String? get _earlyWarning => widget.action == ClockAction.clockOut
      ? ref.read(todayStatusProvider).value?.clockOutWarning(DateTime.now())
      : null;

  /// Confirma una salida antes de hora. Cancelar vuelve a Inicio sin marcar.
  Future<bool> _confirmEarlyExit() async {
    final warning = _earlyWarning;
    if (warning == null || _earlyConfirmed) return true;
    _earlyAsked = true;
    final ok = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (c) => AlertDialog(
        icon: Icon(Icons.warning_amber_rounded, color: Theme.of(c).colorScheme.error),
        title: const Text('¿Salir antes de hora?'),
        content: Text('$warning\n\nTu supervisor y RR. HH. verán la salida anticipada.'),
        actions: [
          TextButton(key: const Key('early-exit-cancel'), onPressed: () => Navigator.pop(c, false), child: const Text('No, seguir trabajando')),
          FilledButton(key: const Key('early-exit-confirm'), onPressed: () => Navigator.pop(c, true), child: const Text('Marcar salida igual')),
        ],
      ),
    );
    if (!mounted) return false;
    if (ok == true) {
      setState(() => _earlyConfirmed = true);
      return true;
    }
    final navigator = Navigator.of(context);
    if (navigator.canPop()) {
      navigator.pop();
    } else {
      GoRouter.maybeOf(context)?.go('/home');
    }
    return false;
  }

  bool get _needsSelfie => widget.action.requiresSelfie && _mobile.selfieRequired;

  /// Descansos: selfie opcional (si la empresa usa selfie).
  bool get _optionalSelfie => !widget.action.requiresSelfie && _mobile.selfieRequired;

  @override
  void initState() {
    super.initState();
    if (ref.read(sharedPreferencesProvider).getBool(PunchDisclosure.prefsKey) == true) {
      _locate();
    } else {
      WidgetsBinding.instance.addPostFrameCallback((_) => _discloseThenLocate());
    }
  }

  /// Primera marcación: aviso de ubicación y cámara antes de pedir los permisos.
  Future<void> _discloseThenLocate() async {
    if (!mounted) return;
    final ok = await PunchDisclosure.ensureAccepted(context, ref.read(sharedPreferencesProvider), selfie: _mobile.selfieRequired);
    if (!mounted) return;
    if (ok) return _locate();
    final navigator = Navigator.of(context);
    if (navigator.canPop()) {
      navigator.pop();
    } else {
      GoRouter.maybeOf(context)?.go('/home');
    }
  }

  /// Lectura verificada que nunca lanza (se pide al disparar y se espera después).
  Future<(LocationFix?, LocationException?)> _safeFix() async {
    try {
      final fix = await ref
          .read(locationServiceProvider)
          .getVerifiedFix(
            maxAccuracyMeters: effectiveMaxAccuracy(_mobile.maxGpsAccuracyMeters, ref.read(myBranchesProvider).value ?? const []),
            mockPolicy: _mobile.mockLocationPolicy,
            sites: [
              for (final b in ref.read(myBranchesProvider).value ?? const <BranchGeofence>[]) (lat: b.lat, lng: b.lng, radius: b.radius),
            ],
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

  /// Con geocerca estricta: [fix] cae fuera del radio de la sede más cercana.
  /// Sin sedes cargadas no se bloquea (el servidor valida al final).
  bool _isOutside(LocationFix fix) => _outsideAttempt(fix) != null;

  AppAttempt? _outsideAttempt(LocationFix fix, {DateTime? at}) {
    if (!_mobile.geofenceStrictMode) return null;
    final near = nearestBranch(ref.read(myBranchesProvider).value ?? const [], fix.position);
    if (near == null || near.inside) return null;
    return AppAttempt.outside(widget.action, fix, branch: near.branch, distance: near.distance, at: at);
  }

  /// La app no dejó marcar (GPS falso, impreciso o fuera del radio): se avisa
  /// al servidor para que RRHH lo vea. No espera la respuesta.
  void _reportBlock({LocationFix? fix, LocationException? error, DateTime? at}) {
    final attempt = error != null
        ? AppAttempt.fromLocationError(widget.action, error, at: at)
        : (fix == null ? null : _outsideAttempt(fix, at: at));
    if (attempt != null) unawaited(ref.read(attemptReporterProvider).report(attempt));
  }

  /// Abre la cámara. [auto]: apertura automática al entrar; si se cancela y
  /// la selfie es obligatoria, se cancela la marcación (vuelve al inicio).
  Future<void> _takeSelfie({bool auto = false}) async {
    Future<(LocationFix?, LocationException?)>? shotFix;
    setState(() => _cameraOpen = true);
    final shot = await Navigator.of(context).push<SelfieShot>(
      MaterialPageRoute(
        builder: (_) => SelfieScreen(actionLabel: _label, onShutter: () => shotFix = _safeFix()),
        fullscreenDialog: true,
      ),
    );
    if (!mounted) return;
    setState(() => _cameraOpen = false);
    if (shot == null) {
      if (auto && _needsSelfie && _selfie == null) Navigator.of(context).pop();
      return;
    }
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
    if (detect) {
      final detected = await face;
      if (!mounted || _selfie != shot.file) return;
      setState(() {
        _faceDetected = detected;
        _detectingFace = false;
      });
    }

    _reportBlock(fix: fix, error: error, at: shot.takenAt);

    // Todo en orden: se envía sin pedir otra confirmación (la foto aceptada lo es)
    if (fix == null || _isOutside(fix) || (detect && _faceDetected == false)) return;
    await _send();
  }

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
      if (fresh == null) {
        if (error != null) _reportBlock(error: error, at: now);
        return null;
      }
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

    if (!await _confirmEarlyExit()) return;
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
      if (!OfflineSyncEngine.isRetryable(e)) {
        _capture = null;
        // El día cambió en el servidor (le quitaron la programación, ya marcó en otro equipo…):
        // Inicio vuelve a pedir el estado para no seguir mostrando botones que ya no aplican
        ref.invalidate(todayStatusProvider);
      }
      // Incorporación sin aprobar: Inicio cambia los botones por el asistente
      if (e.code == 'ONBOARDING_PENDING') ref.invalidate(onboardingProvider);
      if (mounted) setState(() => _sendError = e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  /// "Volver a verificar": con selfie, el GPS va con la foto (se repite) y la
  /// cámara vuelve a abrirse sola al confirmar que está en la agencia.
  void _relocate() {
    setState(() {
      _sendError = null;
      _capture = null;
      _autoOpened = false;
      if (_selfie != null) _clearShot();
    });
    ref.invalidate(myBranchesProvider);
    _locate();
  }

  @override
  Widget build(BuildContext context) {
    final mobile = ref.watch(appConfigProvider.select((s) => s.config.mobile));
    final sync = ref.watch(offlineSyncProvider);
    final drift = ref.watch(todayStatusProvider).value?.driftBeyond(mobile.maxTimeDriftSeconds);
    final branches = ref.watch(myBranchesProvider);
    final needsSelfie = widget.action.requiresSelfie && mobile.selfieRequired;
    final optionalSelfie = !widget.action.requiresSelfie && mobile.selfieRequired;
    final usesSelfie = needsSelfie || optionalSelfie;
    final hasShot = _selfie != null;
    final fix = _shotFix ?? _preview;
    final near = fix == null ? null : nearestBranch(branches.value ?? const [], fix.position);
    final outside = mobile.geofenceStrictMode && near != null && !near.inside;
    final mocked = fix?.isMocked ?? false;
    final earlyWarning = widget.action == ClockAction.clockOut
        ? ref.watch(todayStatusProvider).value?.clockOutWarning(DateTime.now())
        : null;
    final earlyPending = earlyWarning != null && !_earlyConfirmed;

    // Salida antes de hora: primero el aviso (una vez), luego la cámara
    if (earlyPending && !_earlyAsked && _preview != null && !_cameraOpen) {
      _earlyAsked = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _confirmEarlyExit();
      });
    }

    // En la agencia: la cámara se abre sola (una vez) sin pasos intermedios
    final cameraReady =
        !earlyPending &&
        usesSelfie &&
        !_autoOpened &&
        !_cameraOpen &&
        !hasShot &&
        _preview != null &&
        !_locating &&
        !branches.isLoading &&
        !outside &&
        _sendError == null;
    if (cameraReady) {
      _autoOpened = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _takeSelfie(auto: true);
      });
    }

    final status = _status(
      needsSelfie: needsSelfie,
      usesSelfie: usesSelfie,
      fix: fix,
      near: near,
      outside: outside,
      branchesLoading: branches.isLoading,
    );

    return PopScope(
      canPop: !_sending,
      child: Scaffold(
        appBar: AppBar(title: Text(_label)),
        body: SafeArea(
          child: Column(
            children: [
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(20, 24, 20, 16),
                  children: [
                    _StatusHero(status: status, photo: _selfieBytes),
                    if (earlyWarning != null) ...[
                      const SizedBox(height: 16),
                      Card(
                        key: const Key('early-exit-warning'),
                        color: Theme.of(context).colorScheme.errorContainer,
                        child: ListTile(
                          leading: Icon(Icons.timelapse_rounded, color: Theme.of(context).colorScheme.onErrorContainer),
                          title: Text('Jornada incompleta',
                              style: TextStyle(fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.onErrorContainer)),
                          subtitle: Text(earlyWarning, style: TextStyle(color: Theme.of(context).colorScheme.onErrorContainer)),
                        ),
                      ),
                    ],
                    if (fix != null) ...[
                      const SizedBox(height: 24),
                      _LocationCard(
                        fix: fix,
                        near: near,
                        strict: mobile.geofenceStrictMode,
                        branchesLoading: branches.isLoading,
                        branchesFailed: branches.hasError,
                        takenWithShot: _shotFix != null,
                      ),
                    ],
                    if (mocked) ...[
                      const SizedBox(height: 12),
                      const _NoticeCard(icon: Icons.report_gmailerrorred_outlined, text: mockFlagWarning),
                    ],
                    if (drift != null) ...[
                      const SizedBox(height: 12),
                      _NoticeCard(
                        icon: Icons.schedule,
                        text:
                            'La hora de tu teléfono difiere ${(drift / 60).ceil()} min de la hora oficial. '
                            'Activa "Fecha y hora automáticas" en Ajustes.',
                      ),
                    ],
                    if (sync.pending > 0 || sync.lastSyncAt != null) ...[
                      const SizedBox(height: 12),
                      _PendingCard(count: sync.pending, lastSyncAt: sync.lastSyncAt, syncing: sync.syncing),
                    ],
                    if (sync.rejected > 0) ...[const SizedBox(height: 12), _RejectedCard(count: sync.rejected)],
                  ],
                ),
              ),
              if (status.primary != null || status.secondary != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (status.primary case final a?)
                        FilledButton.icon(
                          key: const Key('clock-primary'),
                          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                          onPressed: a.onPressed,
                          icon: Icon(a.icon),
                          label: Text(a.label),
                        ),
                      if (status.secondary case final a?) ...[
                        const SizedBox(height: 8),
                        TextButton.icon(
                          key: const Key('clock-secondary'),
                          style: TextButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                          onPressed: a.onPressed,
                          icon: Icon(a.icon),
                          label: Text(a.label),
                        ),
                      ],
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  /// Qué ve la persona en cada momento del flujo (un solo estado a la vez).
  _Status _status({
    required bool needsSelfie,
    required bool usesSelfie,
    required LocationFix? fix,
    required ({BranchGeofence branch, double distance, bool inside})? near,
    required bool outside,
    required bool branchesLoading,
  }) {
    final hasShot = _selfie != null;
    final label = _label.toLowerCase();
    final retake = _Action('Repetir selfie', Icons.camera_front_outlined, _takeSelfie);

    if (_sending) {
      return const _Status(
        _Tone.busy,
        Icons.cloud_upload_outlined,
        'Registrando tu marcación…',
        'No cierres la app, solo toma unos segundos.',
      );
    }

    final sendError = _sendError;
    if (sendError != null) {
      final gpsIssue = const {
        'GEOFENCE_VIOLATION',
        'MOCK_LOCATION_DETECTED',
        'GPS_ACCURACY_TOO_LOW',
      }.contains(sendError.code);
      final faceIssue = sendError.code == 'FACE_NOT_DETECTED';
      final message = faceIssue
          ? '${sendError.message}\nRepite la selfie con tu rostro centrado y buena luz.'
          : sendError.message;
      return _Status(
        _Tone.error,
        gpsIssue ? Icons.wrong_location_outlined : Icons.error_outline_rounded,
        'No se pudo registrar',
        message,
        primary: gpsIssue
            ? _Action('Volver a verificar', Icons.my_location_rounded, _relocate)
            : faceIssue || (_capture == null && hasShot)
            ? retake
            : _Action('Reintentar', Icons.refresh_rounded, _send),
      );
    }

    if (fix == null && _locating) {
      return _Status(
        _Tone.busy,
        Icons.my_location_rounded,
        'Verificando tu ubicación',
        usesSelfie
            ? 'Confirmamos que estás en tu agencia y abrimos la cámara.'
            : 'Confirmamos que estás en tu agencia.',
      );
    }

    final locationError = _locationError;
    if (locationError != null && (hasShot ? _shotFix == null : _preview == null)) {
      return _Status(
        _Tone.error,
        Icons.location_off_outlined,
        'No pudimos ubicarte',
        locationError.message,
        primary: hasShot ? retake : _Action('Reintentar', Icons.refresh_rounded, _relocate),
        secondary: locationError.canOpenSettings
            ? _Action(
                'Abrir ajustes',
                Icons.settings_outlined,
                () => ref.read(locationServiceProvider).openSettings(locationError.failure),
              )
            : null,
      );
    }

    if (outside) {
      return _Status(
        _Tone.error,
        Icons.wrong_location_outlined,
        'Estás fuera del perímetro',
        'Acércate a ${near!.branch.name} para marcar. Estás a ${formatDistance(near.distance)} '
            'y el radio permitido es de ${formatDistance(near.branch.radius)}.',
        primary: _Action('Volver a verificar', Icons.my_location_rounded, _relocate),
      );
    }

    if (!hasShot && branchesLoading) {
      return const _Status(_Tone.busy, Icons.storefront_outlined, 'Verificando tu agencia', 'Un momento…');
    }

    if (hasShot && (_shotLocating || _detectingFace)) {
      return const _Status(_Tone.busy, Icons.face_retouching_natural, 'Validando tu selfie', 'Un momento…');
    }

    if (hasShot && _mobile.faceDetectionRequired && _faceDetected == false) {
      return _Status(
        _Tone.error,
        Icons.face_retouching_off,
        'No detectamos tu rostro',
        'Repite la selfie con tu cara centrada dentro del óvalo y buena luz.',
        primary: retake,
      );
    }

    if (usesSelfie && !hasShot) {
      if (_cameraOpen || !_autoOpened) {
        return const _Status(
          _Tone.busy,
          Icons.photo_camera_front_outlined,
          'Abriendo la cámara',
          'Prepárate para tu selfie.',
        );
      }
      if (needsSelfie) {
        return _Status(
          _Tone.info,
          Icons.photo_camera_front_outlined,
          'Tómate la selfie',
          'Tu marcación se registra con una foto tomada en este momento.',
          primary: _Action('Abrir cámara', Icons.photo_camera_front_outlined, _takeSelfie),
        );
      }
      return _Status(
        _Tone.info,
        Icons.fingerprint,
        _label,
        'La selfie es opcional en esta marcación.',
        primary: _Action('Marcar sin selfie', Icons.fingerprint, _send),
        secondary: _Action('Tomar selfie', Icons.photo_camera_front_outlined, _takeSelfie),
      );
    }

    // Listo para enviar: sin selfie, o con la foto tomada (p. ej. tras un aviso de conexión)
    return _Status(
      hasShot ? _Tone.success : _Tone.info,
      Icons.fingerprint,
      hasShot ? 'Selfie lista' : _label,
      near != null && near.inside ? 'Estás en ${near.branch.name}.' : 'Confirma para registrar tu marcación.',
      primary: _Action('Confirmar $label', Icons.check_rounded, _send),
      secondary: hasShot ? retake : null,
    );
  }
}

enum _Tone { busy, info, success, error }

class _Action {
  const _Action(this.label, this.icon, this.onPressed);

  final String label;
  final IconData icon;
  final VoidCallback onPressed;
}

class _Status {
  const _Status(this.tone, this.icon, this.title, this.message, {this.primary, this.secondary});

  final _Tone tone;
  final IconData icon;
  final String title;
  final String message;
  final _Action? primary;
  final _Action? secondary;
}

/// Estado actual en grande: ícono (o la selfie) con el color del estado,
/// título y explicación.
class _StatusHero extends StatelessWidget {
  const _StatusHero({required this.status, this.photo});

  final _Status status;
  final Uint8List? photo;

  static const _size = 120.0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final color = switch (status.tone) {
      _Tone.busy || _Tone.info => scheme.primary,
      _Tone.success => Colors.green.shade600,
      _Tone.error => scheme.error,
    };
    final busy = status.tone == _Tone.busy;
    final image = photo;

    return Column(
      children: [
        SizedBox.square(
          dimension: _size + 16,
          child: Stack(
            alignment: Alignment.center,
            children: [
              if (busy)
                SizedBox.square(
                  dimension: _size + 12,
                  child: CircularProgressIndicator(strokeWidth: 3, color: color, strokeCap: StrokeCap.round),
                ),
              Container(
                width: _size,
                height: _size,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: color.withValues(alpha: 0.1),
                  border: busy ? null : Border.all(color: color, width: 3),
                ),
                clipBehavior: Clip.antiAlias,
                child: image != null
                    ? Image.memory(image, fit: BoxFit.cover, gaplessPlayback: true)
                    : Icon(status.icon, color: color, size: 52),
              ),
              if (image != null && !busy)
                Positioned(
                  right: 8,
                  bottom: 8,
                  child: CircleAvatar(
                    radius: 16,
                    backgroundColor: color,
                    child: Icon(
                      status.tone == _Tone.error ? Icons.priority_high_rounded : Icons.check_rounded,
                      size: 18,
                      color: Colors.white,
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        Text(
          status.title,
          key: const Key('clock-title'),
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineSmall?.copyWith(
            fontWeight: FontWeight.w700,
            color: status.tone == _Tone.error ? scheme.error : scheme.onSurface,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          status.message,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyLarge?.copyWith(color: scheme.onSurfaceVariant, height: 1.35),
        ),
      ],
    );
  }
}

/// Dónde está la persona: sede más cercana, dirección y precisión del GPS.
class _LocationCard extends StatelessWidget {
  const _LocationCard({
    required this.fix,
    required this.near,
    required this.strict,
    required this.branchesLoading,
    required this.branchesFailed,
    required this.takenWithShot,
  });

  final LocationFix fix;
  final ({BranchGeofence branch, double distance, bool inside})? near;
  final bool strict;
  final bool branchesLoading;
  final bool branchesFailed;
  final bool takenWithShot;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final near = this.near;
    final (IconData icon, Color color, String text) = switch (near) {
      _ when branchesLoading => (Icons.storefront_outlined, scheme.onSurfaceVariant, 'Verificando sucursales…'),
      _ when branchesFailed => (
        Icons.storefront_outlined,
        scheme.onSurfaceVariant,
        'No se pudo verificar la sucursal; el servidor la validará.',
      ),
      null => (
        Icons.storefront_outlined,
        scheme.onSurfaceVariant,
        'No tienes sucursales asignadas. Consulta con RRHH.',
      ),
      (inside: true, :final branch, distance: _) => (
        Icons.check_circle_rounded,
        Colors.green.shade600,
        'Estás en ${branch.name}',
      ),
      // Con geocerca estricta el título de arriba ya dice la sede y la distancia
      (inside: false, branch: _, distance: _) when strict => (
        Icons.my_location_rounded,
        scheme.onSurfaceVariant,
        'Tu ubicación actual',
      ),
      (inside: false, :final branch, :final distance) => (
        Icons.near_me_disabled_rounded,
        scheme.tertiary,
        '${branch.name} está a ${formatDistance(distance)} de ti',
      ),
    };

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, color: color, size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    text,
                    style: theme.textTheme.titleSmall?.copyWith(color: color, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            _AddressLine(lat: fix.latitude, lng: fix.longitude),
            const SizedBox(height: 6),
            Row(
              children: [
                Icon(Icons.gps_fixed_rounded, size: 18, color: scheme.onSurfaceVariant),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    takenWithShot
                        ? 'Precisión ±${fix.accuracy.round()} m · tomada con tu selfie'
                        : 'Precisión del GPS: ±${fix.accuracy.round()} m',
                    style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
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
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Icon(Icons.place_outlined, size: 18),
        const SizedBox(width: 6),
        Expanded(child: Text(_address ?? 'Buscando dirección…')),
      ],
    );
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

/// Sede asignada más cercana a [position] y si la persona está dentro de su radio.
({BranchGeofence branch, double distance, bool inside})? nearestBranch(
  List<BranchGeofence> branches,
  Position position,
) {
  ({BranchGeofence branch, double distance, bool inside})? best;
  for (final b in branches) {
    final d = LocationService.haversineMeters(position.latitude, position.longitude, b.lat, b.lng);
    if (best == null || d < best.distance) best = (branch: b, distance: d, inside: b.admits(d, position.accuracy));
  }
  return best;
}

/// Precisión máxima para la verificación en el teléfono: la más permisiva
/// entre la empresa y las sedes asignadas (la marcación puede caer en
/// cualquiera); el servidor aplica la exacta de la sede.
int? effectiveMaxAccuracy(int? company, List<BranchGeofence> branches) {
  final values = [?company, for (final b in branches) ?b.maxAccuracy];
  return values.isEmpty ? null : values.reduce(max);
}

/// 85 → "85 m" · 1530 → "1,5 km" · 493532 → "494 km".
String formatDistance(double meters) {
  if (meters < 1000) return '${meters.round()} m';
  final km = meters / 1000;
  return km < 10 ? '${km.toStringAsFixed(1).replaceFirst('.', ',')} km' : '${km.round()} km';
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
    final lastLabel = last == null
        ? 'Aún no se sincroniza.'
        : 'Última sincronización: ${Fmt.date(last)} ${Fmt.time(last)}';
    final color = count > 0 ? scheme.onTertiaryContainer : scheme.onSurfaceVariant;
    return Card(
      color: count > 0 ? scheme.tertiaryContainer : null,
      child: ListTile(
        leading: Icon(count > 0 ? Icons.cloud_upload_outlined : Icons.cloud_done_outlined, color: color),
        title: Text(switch (count) {
          0 => 'Sin marcaciones pendientes',
          1 => '1 marcación pendiente de envío',
          _ => '$count marcaciones pendientes de envío',
        }, style: TextStyle(color: color)),
        subtitle: Text(
          count > 0 ? 'Guardadas sin conexión; se envían al recuperar señal.\n$lastLabel' : lastLabel,
          style: TextStyle(color: color),
        ),
        isThreeLine: count > 0,
        trailing: count == 0
            ? null
            : syncing
            ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.5))
            : IconButton(tooltip: 'Enviar ahora', icon: const Icon(Icons.sync), onPressed: () => _sync(context, ref)),
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
