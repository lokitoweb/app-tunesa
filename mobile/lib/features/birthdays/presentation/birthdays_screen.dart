import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/list_filters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'birthday_providers.dart';
import 'birthday_widgets.dart';

/// Cumpleaños: "Mi equipo" (próximos de su sede), meses y, en cada mes, hoy,
/// próximos y pasados. Al tocar a alguien se ve su ficha.
class BirthdaysScreen extends ConsumerStatefulWidget {
  const BirthdaysScreen({super.key});

  @override
  ConsumerState<BirthdaysScreen> createState() => _BirthdaysScreenState();
}

class _BirthdaysScreenState extends ConsumerState<BirthdaysScreen> {
  static const _months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

  // El mes elegido se conserva al navegar o minimizar la app (MonthFilter)
  int get _month => ref.read(monthFilterProvider('birthdays')).month;
  String _search = '';
  bool _searching = false;
  final _searchCtrl = TextEditingController();
  final _monthsCtrl = ScrollController();

  @override
  void initState() {
    super.initState();
    // Lleva el chip del mes actual a la vista
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_monthsCtrl.hasClients) {
        _monthsCtrl.jumpTo(((_month - 1) * 104.0).clamp(0, _monthsCtrl.position.maxScrollExtent));
      }
    });
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _monthsCtrl.dispose();
    super.dispose();
  }

  BirthdayQuery get _query => (month: _month, search: _search);

  @override
  Widget build(BuildContext context) {
    ref.watch(monthFilterProvider('birthdays'));
    final data = ref.watch(birthdayMonthProvider(_query));
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: _searching
            ? TextField(
                controller: _searchCtrl,
                autofocus: true,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(hintText: 'Buscar por nombre o cargo', border: InputBorder.none),
                onSubmitted: (v) => setState(() => _search = v),
              )
            : const Text('Cumpleaños'),
        actions: [
          IconButton(
            tooltip: _searching ? 'Cerrar búsqueda' : 'Buscar',
            icon: Icon(_searching ? Icons.close_rounded : Icons.search_rounded),
            onPressed: () => setState(() {
              _searching = !_searching;
              if (!_searching) {
                _searchCtrl.clear();
                _search = '';
              }
            }),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(birthdayMonthProvider(_query).future),
        child: ListView(
          padding: pagePadding(context, horizontal: 0),
          children: [
            // "Mi equipo": viene en la respuesta de cualquier mes
            if (_search.isEmpty && (data.value?.team.isNotEmpty ?? false)) ...[
              _Heading('Mi equipo'),
              SizedBox(
                height: 190,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  itemCount: data.value!.team.length,
                  separatorBuilder: (_, _) => const SizedBox(width: 12),
                  itemBuilder: (_, i) => _TeamCard(birthday: data.value!.team[i]),
                ),
              ),
            ],
            _Heading('Todos'),
            SizedBox(
              height: 44,
              child: ListView.separated(
                controller: _monthsCtrl,
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                itemCount: 12,
                separatorBuilder: (_, _) => const SizedBox(width: 8),
                itemBuilder: (_, i) => ChoiceChip(
                  label: Text(_months[i]),
                  selected: _month == i + 1,
                  showCheckmark: false,
                  labelStyle: TextStyle(fontWeight: FontWeight.w600, color: _month == i + 1 ? theme.colorScheme.onPrimary : null),
                  selectedColor: theme.colorScheme.primary,
                  shape: const StadiumBorder(),
                  onSelected: (_) => ref
                      .read(monthFilterProvider('birthdays').notifier)
                      .select(DateTime(DateTime.now().year, i + 1)),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: data.when(
                loading: () => const Padding(padding: EdgeInsets.all(48), child: LoadingView()),
                error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(birthdayMonthProvider(_query))),
                data: (m) => m.isEmpty
                    ? Padding(
                        padding: const EdgeInsets.only(top: 48),
                        child: EmptyView(
                          icon: Icons.cake_outlined,
                          message: _search.isEmpty ? 'Nadie cumple años en ${_months[_month - 1].toLowerCase()}.' : 'Sin resultados para "$_search".',
                        ),
                      )
                    : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        if (m.today.isNotEmpty) ...[
                          const _SubHeading('🎉 Hoy'),
                          for (final b in m.today) BirthdayTile(birthday: b),
                        ],
                        if (m.upcoming.isNotEmpty) ...[
                          const _SubHeading('Próximos cumpleaños'),
                          for (final b in m.upcoming) BirthdayTile(birthday: b),
                        ],
                        if (m.past.isNotEmpty) ...[
                          const _SubHeading('Cumpleaños pasados'),
                          for (final b in m.past) BirthdayTile(birthday: b, muted: true),
                        ],
                      ]),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 10),
        child: Text(text, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
      );
}

class _SubHeading extends StatelessWidget {
  const _SubHeading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, 16, 4, 10),
        child: Text(text, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
      );
}

/// Tarjeta vertical del carrusel "Mi equipo".
class _TeamCard extends StatelessWidget {
  const _TeamCard({required this.birthday});

  final Birthday birthday;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SizedBox(
      width: 150,
      child: Card(
        margin: EdgeInsets.zero,
        color: birthday.isToday ? theme.colorScheme.primaryContainer : null,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppTheme.radius),
          onTap: () => showBirthdayDetail(context, birthday),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(children: [
              BirthdayAvatar(birthday: birthday, radius: 30),
              const SizedBox(height: 8),
              Text(birthday.dateLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: 2),
              Text(birthday.name.toUpperCase(),
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w800)),
              const Spacer(),
              Text(birthday.position?.toUpperCase() ?? '',
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
            ]),
          ),
        ),
      ),
    );
  }
}
