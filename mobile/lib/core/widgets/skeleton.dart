import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../theme/app_theme.dart';

/// Skeleton loaders: siluetas de tarjetas, avatares y botones con brillo
/// (shimmer) mientras la API responde, en lugar de un spinner sobre blanco.
///
/// Ligero a propósito: un solo AnimationController por pantalla en carga, un
/// ShaderMask sobre cajas planas y un RepaintBoundary para que el brillo no
/// repinte el resto del árbol. Sin paquetes de terceros. Con "reducir
/// movimiento" activo en el teléfono se muestra la silueta fija.

/// Raíz de una silueta: un solo AnimationController para todo el bloque y un
/// único "Cargando…" para el lector de pantalla. El brillo lo pinta cada
/// [ShimmerMask] (las tarjetas lo traen) solo sobre las formas, no sobre el
/// fondo de la tarjeta.
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, required this.child});

  final Widget child;

  static const period = Duration(milliseconds: 1500);

  /// Avance del brillo (0..1), o null si no hay que animar.
  static Animation<double>? of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<_ShimmerScope>()?.animation;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: Shimmer.period);
  bool _reduceMotion = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _reduceMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    if (_reduceMotion) {
      _controller.stop();
    } else if (!_controller.isAnimating) {
      _controller.repeat();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _ShimmerScope(
    animation: _reduceMotion ? null : _controller,
    // Lectores de pantalla: un solo "Cargando…" en vez de cajas vacías
    child: Semantics(
      label: 'Cargando…',
      liveRegion: true,
      child: ExcludeSemantics(child: RepaintBoundary(child: widget.child)),
    ),
  );
}

class _ShimmerScope extends InheritedWidget {
  const _ShimmerScope({required this.animation, required super.child});

  final Animation<double>? animation;

  @override
  bool updateShouldNotify(_ShimmerScope old) => old.animation != animation;
}

/// Pinta el brillo sobre las [SkeletonBox] de su [child] (ShaderMask con
/// srcATop: solo donde hay forma). Sin [Shimmer] arriba, o con "reducir
/// movimiento", deja la silueta fija.
class ShimmerMask extends StatelessWidget {
  const ShimmerMask({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final animation = Shimmer.of(context);
    if (animation == null) return child;
    final colors = SkeletonColors.of(context);
    return AnimatedBuilder(
      animation: animation,
      child: child,
      builder: (context, child) => ShaderMask(
        blendMode: BlendMode.srcATop,
        shaderCallback: (bounds) => LinearGradient(
          begin: const Alignment(-1, -0.3),
          end: const Alignment(1, 0.3),
          colors: [colors.base, colors.highlight, colors.base],
          stops: const [0.35, 0.5, 0.65],
          transform: _SlideGradient(animation.value),
        ).createShader(bounds),
        child: child,
      ),
    );
  }
}

/// Desplaza el degradado de izquierda a derecha según el avance (0..1).
class _SlideGradient extends GradientTransform {
  const _SlideGradient(this.percent);

  final double percent;

  @override
  Matrix4 transform(Rect bounds, {TextDirection? textDirection}) =>
      Matrix4.translationValues(bounds.width * (percent * 2 - 1), 0, 0);
}

/// Colores de la silueta, derivados del tema (claro u oscuro).
class SkeletonColors {
  const SkeletonColors(this.base, this.highlight);

  final Color base;
  final Color highlight;

  factory SkeletonColors.of(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final base = dark
        ? scheme.surfaceContainerHighest
        : Color.lerp(scheme.surfaceContainerHighest, Colors.white, 0.35)!;
    final highlight = dark ? Color.lerp(base, Colors.white, 0.10)! : Color.lerp(base, Colors.white, 0.75)!;
    return SkeletonColors(base, highlight);
  }
}

/// Caja plana de la silueta (texto, botón, imagen…).
class SkeletonBox extends StatelessWidget {
  const SkeletonBox({super.key, this.width, this.height = 14, this.radius = 6});

  final double? width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    height: height,
    decoration: BoxDecoration(color: SkeletonColors.of(context).base, borderRadius: BorderRadius.circular(radius)),
  );
}

/// Avatar o icono redondo.
class SkeletonCircle extends StatelessWidget {
  const SkeletonCircle({super.key, this.size = 40});

  final double size;

  @override
  Widget build(BuildContext context) => SkeletonBox(width: size, height: size, radius: size / 2);
}

/// Línea de texto que ocupa una fracción del ancho disponible.
class SkeletonLine extends StatelessWidget {
  const SkeletonLine({super.key, this.widthFactor = 1, this.height = 12});

  final double widthFactor;
  final double height;

  @override
  Widget build(BuildContext context) => FractionallySizedBox(
    alignment: AlignmentDirectional.centerStart,
    widthFactor: widthFactor,
    child: SkeletonBox(height: height),
  );
}

/// Contenedor con la forma de una Card del tema, para que el salto a la
/// tarjeta real no mueva nada.
class SkeletonCard extends StatelessWidget {
  const SkeletonCard({super.key, required this.child, this.padding = const EdgeInsets.all(16)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: dark ? theme.colorScheme.surfaceContainerHigh : Colors.white,
        borderRadius: BorderRadius.circular(AppTheme.radius),
        border: Border.all(color: theme.colorScheme.outlineVariant.withValues(alpha: 0.5)),
      ),
      child: ShimmerMask(child: child),
    );
  }
}

/// Forma del elemento a la izquierda de cada fila.
enum SkeletonLeading { none, circle, square }

/// Una fila tipo ListTile dentro de una tarjeta: icono o avatar, título,
/// subtítulo y, opcional, una etiqueta a la derecha (estado, monto…).
class SkeletonTile extends StatelessWidget {
  const SkeletonTile({
    super.key,
    this.leading = SkeletonLeading.circle,
    this.lines = 2,
    this.trailing = false,
    this.seed = 0,
  });

  final SkeletonLeading leading;
  final int lines;
  final bool trailing;

  /// Varía el largo de las líneas para que la lista no se vea clonada.
  final int seed;

  static const _widths = [0.72, 0.55, 0.64, 0.48, 0.80, 0.60];

  @override
  Widget build(BuildContext context) => SkeletonCard(
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    child: Row(
      children: [
        if (leading == SkeletonLeading.circle) const SkeletonCircle(size: 40),
        if (leading == SkeletonLeading.square) const SkeletonBox(width: 40, height: 40, radius: 8),
        if (leading != SkeletonLeading.none) const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SkeletonLine(widthFactor: _widths[seed % _widths.length], height: 14),
              for (var i = 1; i < lines; i++) ...[
                const SizedBox(height: 8),
                SkeletonLine(widthFactor: _widths[(seed + i * 2) % _widths.length] * 0.85, height: 10),
              ],
            ],
          ),
        ),
        if (trailing) ...[const SizedBox(width: 12), const SkeletonBox(width: 56, height: 22, radius: 11)],
      ],
    ),
  );
}

/// Rellena el alto disponible con tantas filas como quepan (sin desbordar).
/// Dentro de un ListView (alto sin límite) dibuja [fallbackCount] filas.
class _FillHeight extends StatelessWidget {
  const _FillHeight({required this.itemExtent, required this.fallbackCount, required this.builder});

  final double itemExtent;
  final int fallbackCount;
  final Widget Function(int count) builder;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      if (!constraints.hasBoundedHeight) return builder(fallbackCount);
      final count = (constraints.maxHeight / itemExtent).ceil().clamp(1, 12);
      return ClipRect(
        child: OverflowBox(
          alignment: Alignment.topCenter,
          minHeight: 0,
          maxHeight: double.infinity,
          child: builder(count),
        ),
      );
    },
  );
}

/// Lista de tarjetas en carga: solicitudes, boletas, asistencias, avisos…
class SkeletonList extends StatelessWidget {
  const SkeletonList({
    super.key,
    this.leading = SkeletonLeading.circle,
    this.lines = 2,
    this.trailing = false,
    this.header = false,
    this.padding,
    this.fallbackCount = 4,
  });

  final SkeletonLeading leading;
  final int lines;
  final bool trailing;

  /// Una tarjeta de resumen arriba (totales del mes, filtros).
  final bool header;
  final EdgeInsetsGeometry? padding;
  final int fallbackCount;

  @override
  Widget build(BuildContext context) => Shimmer(
    child: Padding(
      padding: padding ?? const EdgeInsets.fromLTRB(16, 16, 16, 0),
      child: _FillHeight(
        itemExtent: 58.0 + lines * 10,
        fallbackCount: fallbackCount,
        builder: (count) => Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (header) ...[const SkeletonSummary(), const SizedBox(height: 12)],
            for (var i = 0; i < count; i++) ...[
              if (i > 0) const SizedBox(height: 10),
              SkeletonTile(leading: leading, lines: lines, trailing: trailing, seed: i),
            ],
          ],
        ),
      ),
    ),
  );
}

/// Tarjeta de métricas: tres cifras con su etiqueta.
class SkeletonSummary extends StatelessWidget {
  const SkeletonSummary({super.key, this.metrics = 3});

  final int metrics;

  @override
  Widget build(BuildContext context) => SkeletonCard(
    child: Row(
      children: [
        for (var i = 0; i < metrics; i++) ...[
          if (i > 0) const SizedBox(width: 16),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SkeletonBox(width: 44, height: 22),
                SizedBox(height: 8),
                SkeletonLine(widthFactor: 0.8, height: 10),
              ],
            ),
          ),
        ],
      ],
    ),
  );
}

/// Jornada de hoy en Inicio: tarjeta del turno y los botones de marcación.
class SkeletonPunchBoard extends StatelessWidget {
  const SkeletonPunchBoard({super.key});

  @override
  Widget build(BuildContext context) => const Shimmer(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SkeletonCard(
          child: Row(
            children: [
              SkeletonBox(width: 44, height: 44, radius: 10),
              SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SkeletonLine(widthFactor: 0.45, height: 10),
                    SizedBox(height: 8),
                    SkeletonLine(widthFactor: 0.7, height: 16),
                  ],
                ),
              ),
            ],
          ),
        ),
        SizedBox(height: 12),
        SkeletonCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SkeletonLine(widthFactor: 0.5, height: 14),
              SizedBox(height: 14),
              Row(
                children: [
                  Expanded(child: SkeletonBox(height: 52, radius: AppTheme.radius)),
                  SizedBox(width: 10),
                  Expanded(child: SkeletonBox(height: 52, radius: AppTheme.radius)),
                ],
              ),
              SizedBox(height: 10),
              Row(
                children: [
                  Expanded(child: SkeletonBox(height: 52, radius: AppTheme.radius)),
                  SizedBox(width: 10),
                  Expanded(child: SkeletonBox(height: 52, radius: AppTheme.radius)),
                ],
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

/// Mi perfil: foto, nombre, cargo y las filas de datos.
class SkeletonProfile extends StatelessWidget {
  const SkeletonProfile({super.key});

  @override
  Widget build(BuildContext context) => Shimmer(
    child: Padding(
      padding: const EdgeInsets.fromLTRB(16, 24, 16, 0),
      child: _FillHeight(
        itemExtent: 64,
        fallbackCount: 4,
        builder: (count) => Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const ShimmerMask(
              child: Column(
                children: [
                  SkeletonCircle(size: 88),
                  SizedBox(height: 14),
                  SkeletonBox(width: 180, height: 18),
                  SizedBox(height: 8),
                  SkeletonBox(width: 120, height: 12),
                ],
              ),
            ),
            const SizedBox(height: 24),
            for (var i = 0; i < count; i++) ...[
              if (i > 0) const SizedBox(height: 10),
              SkeletonTile(leading: SkeletonLeading.square, seed: i + 1),
            ],
          ],
        ),
      ),
    ),
  );
}

/// Detalle de un comunicado, beneficio o caso: título, imagen y párrafos.
class SkeletonDetail extends StatelessWidget {
  const SkeletonDetail({super.key, this.image = false});

  final bool image;

  @override
  Widget build(BuildContext context) => Shimmer(
    child: Padding(
      padding: const EdgeInsets.fromLTRB(16, 20, 16, 0),
      child: _FillHeight(
        itemExtent: 22,
        fallbackCount: 6,
        builder: (count) => ShimmerMask(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Row(
                children: [
                  SkeletonBox(width: 90, height: 22, radius: 11),
                  SizedBox(width: 8),
                  SkeletonBox(width: 70, height: 22, radius: 11),
                ],
              ),
              const SizedBox(height: 14),
              const SkeletonLine(widthFactor: 0.85, height: 20),
              const SizedBox(height: 8),
              const SkeletonLine(widthFactor: 0.5, height: 12),
              const SizedBox(height: 18),
              if (image) ...[const SkeletonBox(height: 160, radius: AppTheme.radius), const SizedBox(height: 18)],
              for (var i = 0; i < count; i++) ...[
                if (i > 0) const SizedBox(height: 10),
                SkeletonLine(widthFactor: i % 4 == 3 ? 0.6 : 0.95),
              ],
            ],
          ),
        ),
      ),
    ),
  );
}

/// Visor de PDF (boletas, documentos): una hoja en blanco con renglones.
class SkeletonDocument extends StatelessWidget {
  const SkeletonDocument({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final sheet = Shimmer(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: SkeletonCard(
            padding: const EdgeInsets.all(20),
            child: _FillHeight(
              itemExtent: 22,
              fallbackCount: 10,
              builder: (count) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Row(
                    children: [SkeletonBox(width: 64, height: 28), Spacer(), SkeletonBox(width: 90, height: 12)],
                  ),
                  const SizedBox(height: 24),
                  for (var i = 0; i < count; i++) ...[
                    if (i > 0) const SizedBox(height: 10),
                    SkeletonLine(widthFactor: i % 5 == 4 ? 0.55 : 1),
                  ],
                ],
              ),
            ),
          ),
        ),
      );
      // El mensaje va fuera del brillo para que se lea
      return Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (message != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
              child: Text(message!, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodyMedium),
            ),
          if (constraints.hasBoundedHeight) Expanded(child: sheet) else sheet,
        ],
      );
    },
  );
}

/// Duración del fundido entre la silueta y el contenido real.
const skeletonFade = Duration(milliseconds: 220);

/// Fundido entre carga, error y datos. El contenido se alinea arriba, igual
/// que la silueta, para que el cambio no dé saltos. Mientras llegan datos
/// nuevos sobre datos ya pintados no hay fundido (nada parpadea).
class SkeletonSwitcher extends StatelessWidget {
  const SkeletonSwitcher({super.key, required this.state, required this.child});

  /// 'loading' | 'error' | 'data': solo un cambio de estado dispara el fundido.
  final String state;
  final Widget child;

  @override
  Widget build(BuildContext context) => AnimatedSwitcher(
    duration: skeletonFade,
    switchInCurve: Curves.easeOut,
    switchOutCurve: Curves.easeIn,
    layoutBuilder: (current, previous) => Stack(alignment: Alignment.topCenter, children: [...previous, ?current]),
    child: KeyedSubtree(key: ValueKey(state), child: child),
  );
}

extension AsyncSkeleton<T> on AsyncValue<T> {
  /// Igual que `when`, pero con fundido entre la silueta y los datos.
  Widget whenSkeleton({
    required Widget Function() loading,
    required Widget Function(Object error, StackTrace stackTrace) error,
    required Widget Function(T data) data,
  }) {
    var state = 'data';
    final child = when(
      loading: () {
        state = 'loading';
        return loading();
      },
      error: (e, s) {
        state = 'error';
        return error(e, s);
      },
      data: data,
    );
    return SkeletonSwitcher(state: state, child: child);
  }
}

/// Bloque que aparece después de cargar (una tarjeta de Inicio que llega con
/// su propia consulta): crece suave en vez de empujar el resto de golpe.
class RevealSize extends StatelessWidget {
  const RevealSize({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) =>
      AnimatedSize(duration: skeletonFade, curve: Curves.easeOut, alignment: Alignment.topCenter, child: child);
}
