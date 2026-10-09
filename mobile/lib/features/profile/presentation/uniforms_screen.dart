import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

/// "Dotación": selector de tallas (polo, pantalón, calzado) e historial de entregas.
class UniformsScreen extends ConsumerWidget {
  const UniformsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Dotación')),
      body: file.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(employeeFileProvider)),
        data: (f) => f.config.uniforms
            ? UniformSizePicker(file: f)
            : const EmptyView(icon: Icons.lock_outline, message: 'Tu empresa no habilitó esta sección.'),
      ),
    );
  }
}

class UniformSizePicker extends ConsumerStatefulWidget {
  const UniformSizePicker({super.key, required this.file});

  final EmployeeFile file;

  @override
  ConsumerState<UniformSizePicker> createState() => _UniformSizePickerState();
}

class _UniformSizePickerState extends ConsumerState<UniformSizePicker> {
  late String? _shirt = widget.file.sizes?.shirt;
  late String? _pants = widget.file.sizes?.pants;
  late String? _shoes = widget.file.sizes?.shoes;
  late String? _shoeType = widget.file.sizes?.shoeType ?? 'safety';
  bool _saving = false;

  Catalogs get _cat => widget.file.catalogs;
  bool get _canEdit => widget.file.config.editUniforms;

  List<String> _or(List<String> v, List<String> fallback) => v.isEmpty ? fallback : v;

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(profileRepositoryProvider).updateUniformSizes({
        'shirt_size': _shirt,
        'pants_size': _pants,
        'shoe_size': _shoes,
        'shoe_type': _shoeType,
      });
      ref.invalidate(employeeFileProvider);
      if (mounted) showSnack(context, 'Tallas guardadas.');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final complete = _shirt != null && _pants != null && _shoes != null;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (!_canEdit)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text('Tus tallas las registra RRHH.', style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
          ),
        _SizeGroup(
          icon: Icons.checkroom_rounded,
          title: 'Polo / camisa',
          options: _or(_cat.shirtSizes, const ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']),
          selected: _shirt,
          enabled: _canEdit,
          onSelected: (v) => setState(() => _shirt = v),
        ),
        _SizeGroup(
          icon: Icons.straighten_rounded,
          title: 'Pantalón',
          options: _or(_cat.pantsSizes, const ['28', '30', '32', '34', '36', '38', '40']),
          selected: _pants,
          enabled: _canEdit,
          onSelected: (v) => setState(() => _pants = v),
        ),
        _SizeGroup(
          icon: Icons.hiking_rounded,
          title: 'Calzado',
          options: _or(_cat.shoeSizes, const ['36', '37', '38', '39', '40', '41', '42', '43', '44']),
          selected: _shoes,
          enabled: _canEdit,
          onSelected: (v) => setState(() => _shoes = v),
          footer: SegmentedButton<String>(
            segments: (_cat.shoeTypes.isEmpty ? const {'safety': 'Seguridad', 'formal': 'Vestir', 'sneaker': 'Zapatillas'} : _cat.shoeTypes)
                .entries
                .map((e) => ButtonSegment(value: e.key, label: Text(e.value.replaceFirst('Calzado de ', ''), overflow: TextOverflow.ellipsis)))
                .toList(),
            selected: {_shoeType ?? 'safety'},
            onSelectionChanged: _canEdit ? (s) => setState(() => _shoeType = s.first) : null,
            showSelectedIcon: false,
          ),
        ),
        if (_canEdit) ...[
          const SizedBox(height: 8),
          FilledButton(
            onPressed: _saving || !complete ? null : _save,
            child: _saving
                ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                : Text(complete ? 'Guardar tallas' : 'Elige las 3 tallas'),
          ),
        ],
        const SizedBox(height: 24),
        Text('Entregas recibidas', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        if (widget.file.deliveries.isEmpty)
          const Card(child: ListTile(leading: Icon(Icons.inventory_2_outlined), title: Text('Aún no tienes entregas registradas.')))
        else
          Card(
            child: Column(children: [
              for (final d in widget.file.deliveries)
                ListTile(
                  leading: const Icon(Icons.inventory_2_outlined),
                  title: Text('${d.quantity}× ${d.itemLabel}${d.size != null ? ' · talla ${d.size}' : ''}'),
                  trailing: Text(Fmt.date(d.deliveredAt)),
                ),
            ]),
          ),
      ],
    );
  }
}

class _SizeGroup extends StatelessWidget {
  const _SizeGroup({
    required this.icon,
    required this.title,
    required this.options,
    required this.selected,
    required this.onSelected,
    required this.enabled,
    this.footer,
  });

  final IconData icon;
  final String title;
  final List<String> options;
  final String? selected;
  final ValueChanged<String> onSelected;
  final bool enabled;
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(icon, color: scheme.primary),
              const SizedBox(width: 8),
              Text(title, style: Theme.of(context).textTheme.titleMedium),
              const Spacer(),
              if (selected != null)
                Text(selected!, style: Theme.of(context).textTheme.titleLarge?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800)),
            ]),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final o in options)
                ChoiceChip(
                  label: SizedBox(width: 34, child: Text(o, textAlign: TextAlign.center)),
                  selected: selected == o,
                  onSelected: enabled ? (_) => onSelected(o) : null,
                ),
            ]),
            if (footer != null) ...[const SizedBox(height: 12), SizedBox(width: double.infinity, child: footer!)],
          ]),
        ),
      ),
    );
  }
}
