import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lottie/lottie.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'birthday_providers.dart';

/// Animación de un regalo: Lottie vectorial (60 FPS, en bucle) con el emoji
/// del regalo latiendo al centro.
class GiftAnimation extends StatefulWidget {
  const GiftAnimation({super.key, required this.gift, this.size = 280, this.animate = true});

  final GiftOption gift;
  final double size;

  /// false: cuadro fijo (historial caducado o miniaturas en reposo).
  final bool animate;

  @override
  State<GiftAnimation> createState() => _GiftAnimationState();
}

class _GiftAnimationState extends State<GiftAnimation> with SingleTickerProviderStateMixin {
  late final _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  /// Con "reducir movimiento" del sistema queda quieta.
  bool get _animate => widget.animate && !MediaQuery.of(context).disableAnimations;

  void _syncPulse() => _animate ? _pulse.repeat(reverse: true) : _pulse.stop();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncPulse();
  }

  @override
  void didUpdateWidget(GiftAnimation old) {
    super.didUpdateWidget(old);
    if (old.animate != widget.animate) _syncPulse();
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final size = widget.size;
    return SizedBox.square(
      dimension: size,
      child: Stack(alignment: Alignment.center, children: [
        Lottie.asset(
          widget.gift.asset,
          width: size,
          height: size,
          animate: _animate,
          repeat: true,
          frameRate: FrameRate.max,
          fit: BoxFit.contain,
          // Si faltara el archivo, queda solo el emoji (nunca un error en pantalla)
          errorBuilder: (_, _, _) => const SizedBox.shrink(),
        ),
        ScaleTransition(
          scale: Tween(begin: 0.92, end: 1.08).animate(CurvedAnimation(parent: _pulse, curve: Curves.easeInOut)),
          child: Text(widget.gift.emoji, style: TextStyle(fontSize: size * 0.34)),
        ),
      ]),
    );
  }
}

/// Foto (o iniciales) de quien envió el regalo.
class GiftSenderAvatar extends StatelessWidget {
  const GiftSenderAvatar({super.key, required this.sender, this.radius = 22});

  final GiftSender sender;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primaryContainer,
      foregroundImage: sender.avatarUrl == null ? null : NetworkImage(sender.avatarUrl!),
      onForegroundImageError: sender.avatarUrl == null ? null : (_, _) {},
      child: Text(sender.initials, style: TextStyle(color: scheme.onPrimaryContainer, fontWeight: FontWeight.w700, fontSize: radius * 0.6)),
    );
  }
}

/// Reproduce el regalo a pantalla completa con quién lo envió. Se cierra al
/// tocar o a los 6 segundos. [headline] reemplaza "De parte de" (p. ej. al enviarlo).
Future<void> showGiftPlayer(BuildContext context, BirthdayGift gift, {String? headline}) => showGeneralDialog<void>(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'Cerrar',
      barrierColor: Colors.black,
      transitionDuration: const Duration(milliseconds: 280),
      pageBuilder: (_, _, _) => _GiftPlayer(gift: gift, headline: headline),
      transitionBuilder: (_, animation, _, child) => FadeTransition(
        opacity: animation,
        child: ScaleTransition(scale: Tween(begin: 0.9, end: 1.0).animate(CurvedAnimation(parent: animation, curve: Curves.easeOutBack)), child: child),
      ),
    );

class _GiftPlayer extends StatefulWidget {
  const _GiftPlayer({required this.gift, this.headline});

  final BirthdayGift gift;
  final String? headline;

  @override
  State<_GiftPlayer> createState() => _GiftPlayerState();
}

class _GiftPlayerState extends State<_GiftPlayer> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer(const Duration(seconds: 6), () {
      if (mounted) Navigator.of(context).maybePop();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final gift = widget.gift;
    final width = MediaQuery.sizeOf(context).width;

    return GestureDetector(
      key: const Key('gift-player'),
      behavior: HitTestBehavior.opaque,
      onTap: () => Navigator.of(context).maybePop(),
      child: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: RadialGradient(colors: [Color(0xFF1B2440), Color(0xFF07090F)], radius: 1.1),
        ),
        child: SafeArea(
          child: Material(
            type: MaterialType.transparency,
            child: Column(children: [
              const Spacer(),
              GiftAnimation(gift: gift.gift, size: (width * 0.9).clamp(240.0, 440.0)),
              const SizedBox(height: 12),
              Text(gift.gift.label,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: 0.3)),
              const Spacer(),
              Container(
                margin: const EdgeInsets.fromLTRB(24, 0, 24, 32),
                padding: const EdgeInsets.fromLTRB(10, 10, 20, 10),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(40),
                  border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  GiftSenderAvatar(sender: gift.sender, radius: 24),
                  const SizedBox(width: 12),
                  Flexible(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(widget.headline ?? 'De parte de',
                          style: TextStyle(color: Colors.white.withValues(alpha: 0.7), fontSize: 12.5, fontWeight: FontWeight.w600)),
                      Text(gift.sender.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
                    ]),
                  ),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Panel de regalos de una persona: galería para enviar (solo el día de su
/// cumpleaños y no a uno mismo) y los regalos que recibió.
Future<void> showGiftsSheet(BuildContext context, {required int userId, required String firstName}) => showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => _GiftsSheet(userId: userId, firstName: firstName),
    );

class _GiftsSheet extends ConsumerStatefulWidget {
  const _GiftsSheet({required this.userId, required this.firstName});

  final int userId;
  final String firstName;

  @override
  ConsumerState<_GiftsSheet> createState() => _GiftsSheetState();
}

class _GiftsSheetState extends ConsumerState<_GiftsSheet> {
  String? _sending;

  Future<void> _send(GiftOption option) async {
    setState(() => _sending = option.type);
    try {
      final gift = await ref.read(birthdayRepositoryProvider).sendGift(widget.userId, option.type);
      ref.invalidate(giftBoardProvider(widget.userId));
      if (!mounted) return;
      await showGiftPlayer(context, gift, headline: 'Le enviaste a ${widget.firstName}');
    } on ApiException catch (e) {
      ref.invalidate(giftBoardProvider(widget.userId)); // p. ej. caducó: se refresca el panel
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _sending = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final board = ref.watch(giftBoardProvider(widget.userId));

    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
        child: board.when(
          loading: () => const Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator())),
          error: (e, _) => Padding(
            padding: const EdgeInsets.all(24),
            child: ErrorView(error: e, onRetry: () => ref.invalidate(giftBoardProvider(widget.userId))),
          ),
          data: (b) => ListView(
            key: const Key('gifts-sheet'),
            shrinkWrap: true,
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            children: [
              Text(b.isSelf ? 'Tus regalos 🎁' : 'Regalos para ${widget.firstName} 🎁',
                  textAlign: TextAlign.center, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(
                b.canSend
                    ? 'Elige uno y envíaselo. Disponible hasta las 11:59 p. m. de hoy.'
                    : b.isSelf
                        ? (b.isToday ? 'Tus compañeros te los envían durante todo el día. Tócalos para verlos.' : 'Así quedaron los regalos de tu último cumpleaños.')
                        : 'Los regalos solo se pueden enviar el día de su cumpleaños.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
              ),
              if (b.canSend) ...[
                const SizedBox(height: 16),
                GridView.count(
                  key: const Key('gift-catalog'),
                  crossAxisCount: 3,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 10,
                  crossAxisSpacing: 10,
                  childAspectRatio: 0.82,
                  children: [
                    for (final option in b.catalog)
                      _GiftOptionTile(
                        option: option,
                        sent: b.sentByMe.contains(option.type),
                        busy: _sending == option.type,
                        onTap: _sending == null && !b.sentByMe.contains(option.type) ? () => _send(option) : null,
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 20),
              Text(b.gifts.isEmpty ? 'Aún no hay regalos' : 'Recibidos (${b.gifts.length})',
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              for (final gift in b.gifts)
                ListTile(
                  key: Key('received-gift-${gift.id}'),
                  contentPadding: EdgeInsets.zero,
                  leading: GiftSenderAvatar(sender: gift.sender),
                  title: Text('${gift.gift.emoji}  ${gift.gift.label}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('De ${gift.sender.name}'),
                  trailing: gift.playable
                      ? Icon(Icons.play_circle_fill_rounded, color: scheme.primary, size: 30)
                      : Tooltip(message: 'Caducó al terminar el día del cumpleaños', child: Icon(Icons.lock_clock_rounded, color: scheme.onSurfaceVariant)),
                  // Caducado: queda solo como historial, sin animación
                  onTap: gift.playable ? () => showGiftPlayer(context, gift) : null,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _GiftOptionTile extends StatelessWidget {
  const _GiftOptionTile({required this.option, required this.sent, required this.busy, this.onTap});

  final GiftOption option;
  final bool sent;
  final bool busy;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Material(
      key: Key('gift-option-${option.type}'),
      color: scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(6),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Expanded(
              child: FittedBox(
                child: Opacity(opacity: sent ? 0.45 : 1, child: GiftAnimation(gift: option, size: 120, animate: !sent)),
              ),
            ),
            const SizedBox(height: 4),
            if (busy)
              const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
            else
              Text(sent ? 'Enviado ✓' : option.label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  style: theme.textTheme.labelSmall?.copyWith(fontWeight: FontWeight.w700, height: 1.15)),
          ]),
        ),
      ),
    );
  }
}
