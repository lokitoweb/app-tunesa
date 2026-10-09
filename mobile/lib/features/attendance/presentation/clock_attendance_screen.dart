import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/network/api_client.dart';
import '../../../core/services/location_service.dart';
import '../../../core/services/offline_sync_service.dart';
import '../../../core/theme/app_theme.dart';
import '../../auth/models/user_model.dart';
import '../models/attendance_model.dart';
import 'camera_selfie_screen.dart';

/// Pantalla Central de Marcación Satelital con Geocerca en Tiempo Real
class ClockAttendanceScreen extends StatefulWidget {
  final UserModel user;

  const ClockAttendanceScreen({super.key, required this.user});

  @override
  State<ClockAttendanceScreen> createState() => _ClockAttendanceScreenState();
}

class _ClockAttendanceScreenState extends State<ClockAttendanceScreen> {
  late BranchAssignment _selectedBranch;
  AttendanceStatusModel? _status;
  GeofenceValidationResult? _geofenceResult;

  bool _isLoadingStatus = true;
  bool _isCheckingGps = false;
  bool _isSubmitting = false;
  int _pendingOfflineCount = 0;
  Timer? _clockTimer;
  DateTime _currentTime = DateTime.now();

  @override
  void initState() {
    super.initState();
    // Seleccionar sucursal principal por defecto
    _selectedBranch = widget.user.branches.firstWhere(
      (b) => b.isPrimary,
      orElse: () => widget.user.branches.isNotEmpty
          ? widget.user.branches.first
          : BranchAssignment(
              id: 1,
              name: 'Sede Principal',
              address: 'Coordenadas Base',
              latitude: -12.046374,
              longitude: -77.042793,
              allowedRadiusMeters: 50,
              isPrimary: true,
            ),
    );

    _startClock();
    _loadTodayStatus();
    _checkGeofence();
    _checkOfflineQueue();
  }

  void _startClock() {
    _clockTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (mounted) setState(() => _currentTime = DateTime.now());
    });
  }

  @override
  void dispose() {
    _clockTimer?.cancel();
    super.dispose();
  }

  Future<void> _checkOfflineQueue() async {
    final count = await OfflineSyncService.getPendingCount();
    if (mounted) setState(() => _pendingOfflineCount = count);
  }

  Future<void> _loadTodayStatus() async {
    setState(() => _isLoadingStatus = true);
    try {
      final response = await ApiClient().get(ApiConstants.attendanceStatus);
      if (response.data['success'] == true) {
        setState(() {
          _status = AttendanceStatusModel.fromJson(response.data['attendance'] ?? {});
        });
      }
    } catch (_) {
      // Si falla la red, estado inicial por defecto
      setState(() {
        _status = AttendanceStatusModel(nextAction: 'clock_in');
      });
    } finally {
      if (mounted) setState(() => _isLoadingStatus = false);
    }
  }

  Future<void> _checkGeofence() async {
    setState(() => _isCheckingGps = true);
    try {
      final result = await LocationService.validateGeofence(
        branchLatitude: _selectedBranch.latitude,
        branchLongitude: _selectedBranch.longitude,
        allowedRadiusMeters: _selectedBranch.allowedRadiusMeters,
      );
      if (mounted) setState(() => _geofenceResult = result);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error obteniendo GPS: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isCheckingGps = false);
    }
  }

  /// Ejecuta el proceso de marcación según el estado del ciclo
  Future<void> _processPunch(String actionType) async {
    // 1. Validar GPS en el momento exacto
    await _checkGeofence();

    if (_geofenceResult == null) {
      _showSnackbar('No se pudo verificar tu ubicación GPS.', isError: true);
      return;
    }

    if (_geofenceResult!.isMocked) {
      _showSnackbar('ALERTA: Se detectó ubicación falsa. Marcación denegada.', isError: true);
      return;
    }

    // 2. Si la acción requiere Selfie obligatoria (Entrada y Salida)
    File? selfiePhoto;
    if (actionType == 'clock_in' || actionType == 'clock_out') {
      final photo = await Navigator.push<File>(
        context,
        MaterialPageRoute(builder: (_) => const CameraSelfieScreen()),
      );

      if (photo == null) {
        _showSnackbar('La fotografía selfie es obligatoria para validar tu identidad.');
        return;
      }
      selfiePhoto = photo;
    }

    setState(() => _isSubmitting = true);

    // 3. Preparar campos y endpoint
    String endpoint;
    switch (actionType) {
      case 'clock_in':
        endpoint = ApiConstants.clockIn;
        break;
      case 'break_start':
        endpoint = ApiConstants.breakStart;
        break;
      case 'break_end':
        endpoint = ApiConstants.breakEnd;
        break;
      case 'clock_out':
      default:
        endpoint = ApiConstants.clockOut;
        break;
    }

    final fields = {
      'branch_id': _selectedBranch.id,
      'latitude': _geofenceResult!.latitude,
      'longitude': _geofenceResult!.longitude,
      'accuracy_meters': _geofenceResult!.accuracyMeters,
      'distance_meters': _geofenceResult!.distanceMeters,
      'within_geofence': _geofenceResult!.isWithinGeofence,
      'client_timestamp': DateTime.now().toIso8601String(),
    };

    // 4. Envío con fallback a Cola Offline
    try {
      final response = await ApiClient().postMultipart(
        endpoint,
        fields: fields,
        file: selfiePhoto,
        fileField: 'photo',
      );

      if (response.data['success'] == true) {
        _showSnackbar('¡Marcación registrada exitosamente!', isSuccess: true);
        await _loadTodayStatus();
      } else {
        _showSnackbar(response.data['message'] ?? 'Error registrando asistencia.', isError: true);
      }
    } catch (e) {
      // Sin conexión: Encolar localmente
      await OfflineSyncService.queuePunch(
        endpoint: endpoint,
        fields: fields,
        photo: selfiePhoto,
      );
      await _checkOfflineQueue();
      _showSnackbar(
        'Sin señal. Marcación guardada en tu teléfono. Se sincronizará automáticamente.',
        isWarning: true,
      );
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  void _showSnackbar(String msg, {bool isSuccess = false, bool isError = false, bool isWarning = false}) {
    Color bg = AppTheme.bgElevated;
    if (isSuccess) bg = AppTheme.success;
    if (isError) bg = AppTheme.danger;
    if (isWarning) bg = AppTheme.warning;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg, style: const TextStyle(fontWeight: FontWeight.w600)),
        backgroundColor: bg,
        duration: const Duration(seconds: 4),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final nextAction = _status?.nextAction ?? 'clock_in';
    final isWithin = _geofenceResult?.isWithinGeofence ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Marcación Satelital'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: () {
              _loadTodayStatus();
              _checkGeofence();
            },
          ),
        ],
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              // Banner Alerta Cola Offline
              if (_pendingOfflineCount > 0)
                Container(
                  margin: const EdgeInsets.only(bottom: 16),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: AppTheme.warning.withOpacity(0.15),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppTheme.warning.withOpacity(0.4)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.cloud_off_rounded, color: AppTheme.warning, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          '$_pendingOfflineCount marcaciones pendientes de sincronizar',
                          style: const TextStyle(fontSize: 12, color: Colors.white70),
                        ),
                      ),
                      TextButton(
                        onPressed: () async {
                          final count = await OfflineSyncService.syncPendingPunches();
                          _showSnackbar('Sincronizadas $count marcaciones.', isSuccess: true);
                          await _checkOfflineQueue();
                        },
                        child: const Text('Sincronizar', style: TextStyle(color: AppTheme.warning, fontSize: 12)),
                      ),
                    ],
                  ),
                ),

              // RELOJ DIGITAL EN VIVO
              Text(
                DateFormat('EEEE, d MMMM yyyy', 'es').format(_currentTime).toUpperCase(),
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.primaryLight, letterSpacing: 0.5),
              ),
              const SizedBox(height: 6),
              Text(
                DateFormat('HH:mm:ss').format(_currentTime),
                style: const TextStyle(fontSize: 48, fontWeight: FontWeight.w800, color: Colors.white, letterSpacing: -1),
              ),
              const SizedBox(height: 20),

              // SELECTOR DE SUCURSAL / OBRA
              if (widget.user.branches.length > 1)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppTheme.bgElevated,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppTheme.bgBorder),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<BranchAssignment>(
                      value: _selectedBranch,
                      dropdownColor: AppTheme.bgElevated,
                      isExpanded: true,
                      items: widget.user.branches.map((b) {
                        return DropdownMenuItem(
                          value: b,
                          child: Text('📍 ${b.name} (${b.allowedRadiusMeters}m)'),
                        );
                      }).toList(),
                      onChanged: (val) {
                        if (val != null) {
                          setState(() => _selectedBranch = val);
                          _checkGeofence();
                        }
                      },
                    ),
                  ),
                ),
              const SizedBox(height: 16),

              // RADAR DE GEOCERCA EN TIEMPO REAL
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppTheme.bgSurface,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(
                    color: isWithin ? AppTheme.success.withOpacity(0.4) : AppTheme.danger.withOpacity(0.4),
                    width: 1.5,
                  ),
                ),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Container(
                              width: 10,
                              height: 10,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: isWithin ? AppTheme.success : AppTheme.danger,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              isWithin ? 'DENTRO DE GEOCERCA' : 'FUERA DE GEOCERCA',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                                color: isWithin ? AppTheme.success : AppTheme.danger,
                              ),
                            ),
                          ],
                        ),
                        InkWell(
                          onTap: _isCheckingGps ? null : _checkGeofence,
                          child: _isCheckingGps
                              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                              : const Row(
                                  children: [
                                    Icon(Icons.gps_fixed, size: 14, color: AppTheme.primaryLight),
                                    SizedBox(width: 4),
                                    Text('Calibrar GPS', style: TextStyle(fontSize: 11, color: AppTheme.primaryLight)),
                                  ],
                                ),
                        ),
                      ],
                    ),
                    const Divider(color: AppTheme.bgBorder, height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceAround,
                      children: [
                        Column(
                          children: [
                            const Text('Distancia a Obra', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                            const SizedBox(height: 4),
                            Text(
                              _geofenceResult != null ? '${_geofenceResult!.distanceMeters} m' : '-- m',
                              style: TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w800,
                                color: isWithin ? AppTheme.success : AppTheme.danger,
                              ),
                            ),
                          ],
                        ),
                        Container(width: 1, height: 40, color: AppTheme.bgBorder),
                        Column(
                          children: [
                            const Text('Radio Autorizado', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                            const SizedBox(height: 4),
                            Text(
                              '${_selectedBranch.allowedRadiusMeters} m',
                              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Colors.white),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 32),

              // BOTONES PRINCIPALES ADAPTADOS AL CICLO DE 4 ESTADOS
              if (_isLoadingStatus)
                const CircularProgressIndicator(color: AppTheme.primary)
              else if (nextAction == 'clock_in') ...[
                // ESTADO 1: ENTRADA (CLOCK-IN)
                _buildActionButton(
                  title: 'MARCAR ENTRADA',
                  subtitle: 'Captura selfie + GPS satelital',
                  icon: Icons.login_rounded,
                  color: AppTheme.success,
                  onPressed: () => _processPunch('clock_in'),
                ),
              ] else if (nextAction == 'break_or_clock_out') ...[
                // ESTADO 2: EN JORNADA (OPCIÓN DE BREAK O SALIDA)
                Row(
                  children: [
                    Expanded(
                      child: _buildActionButton(
                        title: 'PAUSA / BREAK',
                        subtitle: 'Iniciar refrigerio',
                        icon: Icons.coffee_rounded,
                        color: AppTheme.info,
                        onPressed: () => _processPunch('break_start'),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: _buildActionButton(
                        title: 'SALIDA FINAL',
                        subtitle: 'Terminar jornada',
                        icon: Icons.logout_rounded,
                        color: AppTheme.danger,
                        onPressed: () => _processPunch('clock_out'),
                      ),
                    ),
                  ],
                ),
              ] else if (nextAction == 'break_end') ...[
                // ESTADO 3: EN DESCANSO (FINALIZAR BREAK)
                _buildActionButton(
                  title: 'REANUDAR JORNADA',
                  subtitle: 'Finalizar descanso / break',
                  icon: Icons.play_arrow_rounded,
                  color: AppTheme.warning,
                  onPressed: () => _processPunch('break_end'),
                ),
              ] else if (nextAction == 'clock_out') ...[
                // ESTADO 4: SALIDA FINAL
                _buildActionButton(
                  title: 'MARCAR SALIDA',
                  subtitle: 'Selfie + cómputo de horas',
                  icon: Icons.logout_rounded,
                  color: AppTheme.danger,
                  onPressed: () => _processPunch('clock_out'),
                ),
              ] else ...[
                // ESTADO 5: JORNADA COMPLETADA
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: AppTheme.success.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: AppTheme.success.withOpacity(0.3)),
                  ),
                  child: Column(
                    children: [
                      const Icon(Icons.check_circle_rounded, color: AppTheme.success, size: 48),
                      const SizedBox(height: 10),
                      const Text(
                        '¡Jornada de Hoy Completada!',
                        style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: Colors.white),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Horas trabajadas: ${_status?.workedHours.toStringAsFixed(2) ?? '0.00'} hrs',
                        style: const TextStyle(fontSize: 14, color: AppTheme.textSecondary),
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildActionButton({
    required String title,
    required String subtitle,
    required IconData icon,
    required Color color,
    required VoidCallback onPressed,
  }) {
    return Container(
      width: double.infinity,
      height: 90,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: color.withOpacity(0.35),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: ElevatedButton(
        style: ElevatedButton.styleFrom(
          backgroundColor: color,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          padding: const EdgeInsets.symmetric(horizontal: 16),
        ),
        onPressed: _isSubmitting ? null : onPressed,
        child: _isSubmitting
            ? const CircularProgressIndicator(color: Colors.white)
            : Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, size: 32, color: Colors.white),
                  const SizedBox(width: 14),
                  Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: Colors.white),
                      ),
                      Text(
                        subtitle,
                        style: TextStyle(fontSize: 11, color: Colors.white.withOpacity(0.85)),
                      ),
                    ],
                  ),
                ],
              ),
      ),
    );
  }
}
