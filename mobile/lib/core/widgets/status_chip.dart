import 'package:flutter/material.dart';

/// Chip de estado reutilizado en historial y solicitudes.
class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.status, required this.label});

  final String status;
  final String label;

  @override
  Widget build(BuildContext context) {
    final color = switch (status) {
      'present' || 'approved' => Colors.green,
      'late' || 'pending' || 'incomplete' || 'half_day' => Colors.orange,
      'absent' || 'rejected' => Colors.red,
      'excused' => Colors.blue,
      _ => Colors.grey,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(12)),
      child: Text(label, style: TextStyle(color: color.shade800, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}
