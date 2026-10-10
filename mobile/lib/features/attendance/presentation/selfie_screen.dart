import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

/// Selfie tomada y el instante (UTC) en que se disparó la cámara.
class SelfieShot {
  const SelfieShot(this.file, this.takenAt);

  final XFile file;
  final DateTime takenAt;
}

/// Captura obligatoria de selfie con la cámara FRONTAL.
///
/// No hay opción de galería: la foto se toma en el momento de la marcación.
/// Devuelve un [SelfieShot] con `Navigator.pop`. [onShutter] se llama al
/// disparar (para pedir el GPS en ese mismo momento).
class SelfieScreen extends StatefulWidget {
  const SelfieScreen({super.key, this.onShutter});

  final VoidCallback? onShutter;

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
      setState(() => _error = switch (e.code) {
            'CameraAccessDenied' ||
            'CameraAccessDeniedWithoutPrompt' ||
            'CameraAccessRestricted' =>
              'Necesitamos acceso a la cámara para tu selfie de marcación. Habilítalo en Ajustes > Aplicaciones > TuneSA > Permisos.',
            _ => 'No se pudo abrir la cámara (${e.description ?? e.code}).',
          });
    }
  }

  Future<void> _capture() async {
    final controller = _controller;
    if (controller == null || _capturing) return;
    setState(() => _capturing = true);
    try {
      final takenAt = DateTime.now().toUtc();
      widget.onShutter?.call();
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
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Selfie de marcación'),
      ),
      body: SafeArea(child: _body()),
    );
  }

  Widget _body() {
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.no_photography_outlined, color: Colors.white70, size: 56),
            const SizedBox(height: 16),
            Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white)),
            const SizedBox(height: 16),
            OutlinedButton(onPressed: _initCamera, child: const Text('Reintentar')),
          ]),
        ),
      );
    }

    if (_shotBytes != null) {
      return Column(children: [
        Expanded(child: Center(child: Image.memory(_shotBytes!, fit: BoxFit.contain))),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, minimumSize: const Size.fromHeight(52)),
                onPressed: () => setState(() {
                  _shot = null;
                  _shotBytes = null;
                }),
                icon: const Icon(Icons.refresh),
                label: const Text('Repetir'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: FilledButton.icon(
                onPressed: () => Navigator.of(context).pop(SelfieShot(_shot!, _takenAt!)),
                icon: const Icon(Icons.check),
                label: const Text('Usar foto'),
              ),
            ),
          ]),
        ),
      ]);
    }

    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return const Center(child: CircularProgressIndicator(color: Colors.white));
    }

    return Column(children: [
      Expanded(
        child: Center(
          child: Stack(alignment: Alignment.center, children: [
            CameraPreview(controller),
            IgnorePointer(
              child: Container(
                width: 220,
                height: 290,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.white70, width: 3),
                  borderRadius: const BorderRadius.all(Radius.elliptical(220, 290)),
                ),
              ),
            ),
          ]),
        ),
      ),
      const Padding(
        padding: EdgeInsets.only(top: 12),
        child: Text('Centra tu rostro en el óvalo, con buena luz', style: TextStyle(color: Colors.white70)),
      ),
      Padding(
        padding: const EdgeInsets.all(20),
        child: GestureDetector(
          onTap: _capture,
          child: Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 4),
              color: _capturing ? Colors.white38 : Colors.white24,
            ),
            child: _capturing ? const Padding(padding: EdgeInsets.all(22), child: CircularProgressIndicator(color: Colors.white)) : null,
          ),
        ),
      ),
    ]);
  }
}
