import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/network/api_client.dart';
import '../../../core/theme/app_theme.dart';

/// Historial cronológico de asistencias del colaborador
class AttendanceHistoryScreen extends StatefulWidget {
  const AttendanceHistoryScreen({super.key});

  @override
  State<AttendanceHistoryScreen> createState() => _AttendanceHistoryScreenState();
}

class _AttendanceHistoryScreenState extends State<AttendanceHistoryScreen> {
  bool _isLoading = true;
  List<dynamic> _history = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final response = await ApiClient().get(ApiConstants.attendanceHistory);
      if (response.data['success'] == true) {
        setState(() {
          _history = response.data['data'] ?? [];
        });
      }
    } catch (e) {
      setState(() => _error = 'No se pudo cargar el historial.');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Historial de Marcaciones'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh_rounded), onPressed: _loadHistory),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppTheme.primary))
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: AppTheme.danger)))
              : _history.isEmpty
                  ? const Center(child: Text('Sin marcaciones registradas todavía.', style: TextStyle(color: AppTheme.textSecondary)))
                  : ListView.builder(
                      padding: const EdgeInsets.all(16),
                      itemCount: _history.length,
                      itemBuilder: (context, index) {
                        final item = _history[index];
                        final dateStr = item['work_date'] ?? '';
                        final clockIn = item['clock_in_at'];
                        final clockOut = item['clock_out_at'];
                        final status = item['status'] ?? 'on_time';
                        final hours = item['worked_hours'] ?? '0.00';
                        final withinGeo = item['clock_in_within_geofence'] == true;

                        return Card(
                          margin: const EdgeInsets.only(bottom: 12),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Row(
                              children: [
                                // Thumbnail Selfie si existe
                                if (item['clock_in_photo_url'] != null)
                                  ClipRRect(
                                    borderRadius: BorderRadius.circular(10),
                                    child: Image.network(
                                      item['clock_in_photo_url'],
                                      width: 48,
                                      height: 48,
                                      fit: BoxFit.cover,
                                      errorBuilder: (_, __, ___) => _buildAvatarPlaceholder(),
                                    ),
                                  )
                                else
                                  _buildAvatarPlaceholder(),

                                const SizedBox(width: 14),

                                // Detalles de la jornada
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        dateStr,
                                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: Colors.white),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        'Entrada: ${clockIn != null ? clockIn.toString().substring(11, 16) : '--:--'}  •  Salida: ${clockOut != null ? clockOut.toString().substring(11, 16) : '--:--'}',
                                        style: const TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                                      ),
                                      const SizedBox(height: 4),
                                      Row(
                                        children: [
                                          Icon(
                                            withinGeo ? Icons.check_circle_outline : Icons.warning_amber_rounded,
                                            size: 13,
                                            color: withinGeo ? AppTheme.success : AppTheme.danger,
                                          ),
                                          const SizedBox(width: 4),
                                          Text(
                                            withinGeo ? 'En geocerca' : 'Fuera de geocerca',
                                            style: TextStyle(
                                              fontSize: 11,
                                              color: withinGeo ? AppTheme.success : AppTheme.danger,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),

                                // Horas y Badge de Estado
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    Text(
                                      '$hours hrs',
                                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: Colors.white),
                                    ),
                                    const SizedBox(height: 6),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: status == 'on_time'
                                            ? AppTheme.success.withOpacity(0.15)
                                            : AppTheme.warning.withOpacity(0.15),
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: Text(
                                        status == 'on_time' ? 'Puntual' : 'Tardanza',
                                        style: TextStyle(
                                          fontSize: 10,
                                          fontWeight: FontWeight.w700,
                                          color: status == 'on_time' ? AppTheme.success : AppTheme.warning,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
    );
  }

  Widget _buildAvatarPlaceholder() {
    return Container(
      width: 48,
      height: 48,
      decoration: BoxDecoration(
        color: AppTheme.bgElevated,
        borderRadius: BorderRadius.circular(10),
      ),
      child: const Icon(Icons.person_outline_rounded, color: AppTheme.primaryLight, size: 24),
    );
  }
}
