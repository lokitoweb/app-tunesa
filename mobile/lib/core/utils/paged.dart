import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'json_num.dart';

import '../network/api_exception.dart';

/// Página acumulada de un listado paginado del backend
/// (`{ data: [...], meta: { current_page, last_page?, total } }`).
///
/// Algunos endpoints (solicitudes, aprobaciones) no envían `last_page`: se
/// calcula con `total` y el tamaño de página del backend.
class Paged<T> {
  const Paged({
    required this.items,
    required this.page,
    required this.lastPage,
    required this.total,
    this.meta = const {},
    this.loadingMore = false,
    this.loadMoreError,
  });

  final List<T> items;
  final int page;
  final int lastPage;
  final int total;

  /// `meta` crudo de la última página (ej: `unread_count`).
  final Map<String, dynamic> meta;

  /// Estado de la carga de la página siguiente (la lista ya visible se conserva).
  final bool loadingMore;
  final Object? loadMoreError;

  bool get hasMore => page < lastPage;
  bool get isEmpty => items.isEmpty;

  factory Paged.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic> json) parse, {
    int perPage = 20,
  }) {
    final items = ((json['data'] as List?) ?? const [])
        .map((e) => parse(Map<String, dynamic>.from(e as Map)))
        .toList();
    final meta = json['meta'] is Map ? Map<String, dynamic>.from(json['meta'] as Map) : <String, dynamic>{};

    final page = asInt(meta['current_page']) ?? 1;
    final total = asInt(meta['total']) ?? items.length;
    final computedLast = total <= 0 ? 1 : ((total + perPage - 1) ~/ perPage);
    var lastPage = asInt(meta['last_page']) ?? computedLast;
    // Página vacía: no hay nada más que pedir aunque el total diga otra cosa
    if (items.isEmpty) lastPage = page;

    return Paged(items: items, page: page, lastPage: lastPage < page ? page : lastPage, total: total, meta: meta);
  }

  /// Agrega la página siguiente a la ya cargada.
  Paged<T> append(Paged<T> next) => Paged(
        items: [...items, ...next.items],
        page: next.page,
        lastPage: next.lastPage,
        total: next.total,
        meta: next.meta,
      );

  Paged<T> copyWith({
    List<T>? items,
    int? total,
    bool? loadingMore,
    Object? loadMoreError,
    bool clearError = false,
  }) =>
      Paged(
        items: items ?? this.items,
        page: page,
        lastPage: lastPage,
        total: total ?? this.total,
        meta: meta,
        loadingMore: loadingMore ?? this.loadingMore,
        loadMoreError: clearError ? null : (loadMoreError ?? this.loadMoreError),
      );
}

/// Controlador genérico de listas paginadas con scroll infinito.
///
/// `build()` trae la página 1; [loadMore] agrega la siguiente sin perder lo
/// ya mostrado. `ref.invalidate(provider)` vuelve a empezar desde la página 1.
abstract class PagedController<T> extends AsyncNotifier<Paged<T>> {
  Future<Paged<T>> fetchPage(int page);

  @override
  Future<Paged<T>> build() => fetchPage(1);

  /// Pide la página siguiente. Sin efecto si ya está cargando, si no quedan
  /// páginas o si la anterior falló (salvo que sea un reintento explícito).
  Future<void> loadMore({bool retry = false}) async {
    final current = state.value;
    if (current == null || !current.hasMore || current.loadingMore) return;
    if (current.loadMoreError != null && !retry) return;

    state = AsyncData(current.copyWith(loadingMore: true, clearError: true));
    try {
      final next = await fetchPage(current.page + 1);
      if (!ref.mounted) return;
      state = AsyncData(current.append(next));
    } on ApiException catch (e) {
      if (!ref.mounted) return;
      state = AsyncData(current.copyWith(loadingMore: false, loadMoreError: e));
    }
  }

  /// Modifica la lista ya cargada (ej: quitar una solicitud ya revisada).
  void updateItems(List<T> Function(List<T> items) update, {int totalDelta = 0}) {
    final current = state.value;
    if (current == null) return;
    final total = current.total + totalDelta;
    state = AsyncData(current.copyWith(items: update(current.items), total: total < 0 ? 0 : total));
  }
}
