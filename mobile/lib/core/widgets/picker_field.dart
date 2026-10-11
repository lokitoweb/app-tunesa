import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Campo que abre un selector (fechas, hora, archivo): se ve igual que un
/// campo de texto, con su etiqueta arriba y el valor elegido en una línea.
class PickerField extends StatelessWidget {
  const PickerField({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
    this.value,
    this.placeholder = '',
  });

  final String label;
  final IconData icon;
  final VoidCallback onTap;
  final String? value;
  final String placeholder;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppTheme.radius),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon),
          suffixIcon: const Icon(Icons.chevron_right_rounded),
        ),
        child: Text(
          value ?? placeholder,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(color: value == null ? scheme.onSurfaceVariant : scheme.onSurface),
        ),
      ),
    );
  }
}
