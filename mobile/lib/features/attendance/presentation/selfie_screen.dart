import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Selfie tomada y el instante (UTC) en que se disparó la cámara.
class SelfieShot {
  const SelfieShot(this.file, this.takenAt);

  final XFile file;
  final DateTime takenAt;
}

/// Captura obligatoria de selfie con la cámara FRONTAL, a pantalla completa.
///
/// No hay opción de galería: la foto se toma en el momento de la marcación.
/// Devuelve un [SelfieShot] con `Navigator.pop`. [onShutter] se llama al
/// disparar (para pedir el GPS en ese mismo momento).
class SelfieScreen extends StatefulWidget {
  const SelfieScreen({super.key, this.onShutter, this.actionLabel});

  final VoidCallback? onShutter;

  /// Marcación en curso (ej. "Marcar entrada"), se muestra sobre la cámara.
  final String? actionLabel;

  @override
  State<SelfieScreen> createState() => _SelfieScreenState();
}

class _SelfieScreenState extends State<SelfieScreen> with WidgetsBindingObserver {
  CameraController? _controller;
  String? _error;
  bool _capturing = false;
  XFile? _shot;
  DateTime? _takenAt;
  Uint8List? _shotBytes;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _initCamera();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller?.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;
    if (state == AppLifecycleState.inactive) {
      controller.dispose();
      _controller = null;
    } else if (state == AppLifecycleState.resumed) {
      _initCamera();
    }
  }

  Future<void> _initCamera() async {
    setState(() => _error = null);
    try {
      final cameras = await availableCameras();
      final front = cameras.where((c) => c.lensDirection == CameraLensDirection.front).firstOrNull;
      if (front == null) {
        if (!mounted) return;
        setState(() => _error = 'Este dispositivo no tiene cámara frontal. No es posible marcar sin selfie.');
        return;
      }
      final controller = CameraController(
        front,
        ResolutionPreset.medium,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() => _controller = controller);
    } on CameraException catch (e) {
      if (!mounted) return;
      setState(
        () => _error = switch (e.code) {
          'CameraAccessDenied' || 'CameraAccessDeniedWithoutPrompt' || 'CameraAccessRestricted' => 'Necesitamos acceso a la cámara para tu selfie de marcación. Habilítalo en Ajustes > Aplicaciones > TuneSA > Permisos.',
          _ => 'No se pudo abrir la cámara (${e.description ?? e.code}).',
        },
      );
    } catch (_) {
      // Plataforma sin cámara (escritorio, pruebas): mensaje claro en vez de una pantalla negra
      if (mounted) setState(() => _error = 'No se pudo abrir la cámara de este dispositivo.');
    }
  }

  Future<void> _capture() async {
    final controller = _controller;
    if (controller == null || _capturing) return;
    setState(() => _capturing = true);
    try {
      final takenAt = DateTime.now().toUtc();
      widget.onShutter?.call();
      HapticFeedback.mediumImpact();
      final file = await controller.takePicture();
      final bytes = await file.readAsBytes();
      setState(() {
        _shot = file;
        _takenAt = takenAt;
        _shotBytes = bytes;
      });
    } on CameraException catch (e) {
      setState(() => _error = 'No se pudo tomar la foto (${e.description ?? e.code}).');
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final reviewing = _shotBytes != null;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: Colors.black,
        extendBodyBehindAppBar: true,
        appBar: AppBar(
          backgroundColor: Colors.transparent,
          surfaceTintColor: Colors.transparent,
          foregroundColor: Colors.white,
          systemOverlayStyle: SystemUiOverlayStyle.light,
          centerTitle: true,
          leading: IconButton(
            tooltip: 'Cancelar',
            icon: const Icon(Icons.close_rounded),
            onPressed: () => Navigator.of(context).maybePop(),
          ),
          title: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                reviewing ? 'Revisa tu selfie' : 'Selfie de marcación',
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
              ),
              if (widget.actionLabel case final label?)
                Text(label, style: const TextStyle(fontSize: 13, color: Colors.white70)),
            ],
          ),
        ),
        body: _body(),
      ),
    );
  }

  Widget _body() {
    if (_error != null) {
      return SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 88,
                  height: 88,
                  decoration: const BoxDecoration(shape: BoxShape.circle, color: Colors.white10),
                  child: const Icon(Icons.no_photography_outlined, color: Colors.white70, size: 44),
                ),
                const SizedBox(height: 20),
                Text(
                  _error!,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 16, height: 1.4),
                ),
                const SizedBox(height: 24),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white54),
                    minimumSize: const Size(200, 48),
                  ),
                  onPressed: _initCamera,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('Reintentar'),
                ),
              ],
            ),
          ),
        ),
      );
    }

    if (_shotBytes != null) {
      return Stack(
        fit: StackFit.expand,
        children: [
          Image.memory(_shotBytes!, fit: BoxFit.cover, gaplessPlayback: true),
          const _BottomShade(),
          SafeArea(
            child: Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
                child: Row(
                  children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Colors.white,
                          side: const BorderSide(color: Colors.white70),
                          minimumSize: const Size.fromHeight(54),
                        ),
                        onPressed: () => setState(() {
                          _shot = null;
                          _shotBytes = null;
                        }),
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('Repetir'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        key: const Key('selfie-use'),
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54)),
                        onPressed: () => Navigator.of(context).pop(SelfieShot(_shot!, _takenAt!)),
                        icon: const Icon(Icons.check_rounded),
                        label: const Text('Usar foto'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      );
    }

    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return const Center(child: CircularProgressIndicator(color: Colors.white));
    }

    return Stack(
      fit: StackFit.expand,
      children: [
        _CoverPreview(controller: controller),
        const IgnorePointer(child: CustomPaint(painter: _OvalMask())),
        const _BottomShade(),
        SafeArea(
          child: Column(
            children: [
              const Spacer(),
              const Text(
                'Centra tu rostro en el óvalo, con buena luz',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w500),
              ),
              const SizedBox(height: 20),
              Semantics(
                button: true,
                label: 'Tomar selfie',
                child: GestureDetector(
                  key: const Key('selfie-shutter'),
                  onTap: _capture,
                  child: Container(
                    width: 80,
                    height: 80,
                    padding: const EdgeInsets.all(5),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 4),
                    ),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 120),
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: _capturing ? Colors.white54 : Colors.white,
                      ),
                      child: _capturing
                          ? const Padding(
                              padding: EdgeInsets.all(18),
                              child: CircularProgressIndicator(strokeWidth: 3, color: Colors.black54),
                            )
                          : null,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 28),
            ],
          ),
        ),
      ],
    );
  }
}

/// Vista previa que llena la pantalla sin deformarse (recorta los bordes).
class _CoverPreview extends StatelessWidget {
  const _CoverPreview({required this.controller});

  final CameraController controller;

  @override
  Widget build(BuildContext context) {
    final size = controller.value.previewSize;
    if (size == null) return CameraPreview(controller);
    // previewSize viene en horizontal: en vertical se invierten ancho y alto
    return ClipRect(
      child: FittedBox(
        fit: BoxFit.cover,
        child: SizedBox(width: size.height, height: size.width, child: CameraPreview(controller)),
      ),
    );
  }
}

/// Oscurece todo menos un óvalo centrado donde va el rostro.
class _OvalMask extends CustomPainter {
  const _OvalMask();

  static Rect oval(Size size) {
    final width = size.width * 0.68;
    final height = width * 1.32;
    return Rect.fromCenter(center: Offset(size.width / 2, size.height * 0.42), width: width, height: height);
  }

  @override
  void paint(Canvas canvas, Size size) {
    final face = oval(size);
    final mask = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addOval(face);
    canvas.drawPath(mask, Paint()..color = Colors.black.withValues(alpha: 0.55));
    canvas.drawOval(
      face,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3
        ..color = Colors.white.withValues(alpha: 0.9),
    );
  }

  @override
  bool shouldRepaint(covariant _OvalMask oldDelegate) => false;
}

/// Degradado inferior para que botones y textos se lean sobre cualquier fondo.
class _BottomShade extends StatelessWidget {
  const _BottomShade();

  @override
  Widget build(BuildContext context) => const IgnorePointer(
    child: DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          stops: [0, 0.18, 0.7, 1],
          colors: [Colors.black54, Colors.transparent, Colors.transparent, Colors.black87],
        ),
      ),
    ),
  );
}
