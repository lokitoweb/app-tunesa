import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../../auth/presentation/auth_controller.dart';

/// Foto de perfil sobre la cabecera: muestra la foto (o las iniciales) y, al
/// tocarla, deja tomar una nueva o elegirla de la galería.
class ProfileAvatar extends ConsumerStatefulWidget {
  const ProfileAvatar({super.key, required this.initials});

  final String initials;

  @override
  ConsumerState<ProfileAvatar> createState() => _ProfileAvatarState();
}

class _ProfileAvatarState extends ConsumerState<ProfileAvatar> {
  bool _busy = false;

  Future<void> _change() async {
    final hasPhoto = ref.read(authControllerProvider).user?.avatarUrl != null;
    final scheme = Theme.of(context).colorScheme;
    final source = await showModalBottomSheet<_AvatarAction>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: Text(hasPhoto ? 'Cambiar foto de perfil' : 'Subir foto de perfil',
                style: Theme.of(c).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          ),
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined),
            title: const Text('Tomar foto'),
            onTap: () => Navigator.pop(c, _AvatarAction.camera),
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined),
            title: const Text('Elegir de la galería'),
            onTap: () => Navigator.pop(c, _AvatarAction.gallery),
          ),
          if (hasPhoto)
            ListTile(
              leading: Icon(Icons.delete_outline_rounded, color: scheme.error),
              title: Text('Quitar foto', style: TextStyle(color: scheme.error)),
              onTap: () => Navigator.pop(c, _AvatarAction.remove),
            ),
          const SizedBox(height: 8),
        ]),
      ),
    );
    if (source == null || !mounted) return;
    if (source == _AvatarAction.remove) return _remove();


    // 800 px y calidad 85: pesa muy por debajo del máximo de 2 MB del servidor
    final image = await ImagePicker().pickImage(
      source: source == _AvatarAction.camera ? ImageSource.camera : ImageSource.gallery,
      maxWidth: 800,
      maxHeight: 800,
      imageQuality: 85,
      preferredCameraDevice: CameraDevice.front,
    );
    if (image == null || !mounted) return;

    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).updateAvatar(await image.readAsBytes());
      if (mounted) showSnack(context, 'Foto de perfil actualizada.');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.fieldError('avatar') ?? e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _remove() async {
    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).removeAvatar();
      if (mounted) showSnack(context, 'Foto de perfil eliminada.');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final url = ref.watch(authControllerProvider.select((s) => s.user?.avatarUrl));

    return Semantics(
      button: true,
      label: 'Cambiar foto de perfil',
      child: GestureDetector(
        onTap: _busy ? null : _change,
        child: Stack(clipBehavior: Clip.none, children: [
          CircleAvatar(
            radius: 36,
            backgroundColor: scheme.onPrimary.withValues(alpha: 0.2),
            foregroundImage: url == null ? null : NetworkImage(url),
            child: _busy
                ? SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: scheme.onPrimary))
                : Text(widget.initials,
                    style: TextStyle(color: scheme.onPrimary, fontSize: 22, fontWeight: FontWeight.w700)),
          ),
          Positioned(
            right: -2,
            bottom: -2,
            child: Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: scheme.surface,
                shape: BoxShape.circle,
                border: Border.all(color: scheme.primary.withValues(alpha: 0.25)),
              ),
              child: Icon(Icons.photo_camera_rounded, size: 16, color: scheme.primary),
            ),
          ),
        ]),
      ),
    );
  }
}

enum _AvatarAction { camera, gallery, remove }
