import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/network/api_client.dart';
import '../../../core/theme/app_theme.dart';
import '../models/payslip_model.dart';

/// Pantalla de Consulta y Descarga de Boletas de Pago en PDF
class PayslipsScreen extends StatefulWidget {
  const PayslipsScreen({super.key});

  @override
  State<PayslipsScreen> createState() => _PayslipsScreenState();
}

class _PayslipsScreenState extends State<PayslipsScreen> {
  bool _isLoading = true;
  List<PayslipModel> _payslips = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPayslips();
  }

  Future<void> _loadPayslips() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final response = await ApiClient().get(ApiConstants.payslips);
      if (response.data['success'] == true) {
        final list = response.data['data'] as List? ?? [];
        setState(() {
          _payslips = list.map((item) => PayslipModel.fromJson(item)).toList();
        });
      }
    } catch (e) {
      setState(() => _error = 'No se pudieron cargar las boletas de pago.');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _downloadPayslip(PayslipModel payslip) async {
    try {
      // Obtener URL firmada temporal de descarga
      final response = await ApiClient().get('${ApiConstants.payslipDownload}/${payslip.id}/download-url');
      if (response.data['success'] == true) {
        final urlString = response.data['download_url'];
        final uri = Uri.parse(urlString);

        if (await canLaunchUrl(uri)) {
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        } else {
          _showSnackbar('No se pudo abrir el navegador para descargar el PDF.', isError: true);
        }
      }
    } catch (e) {
      _showSnackbar('Error al obtener la boleta PDF.', isError: true);
    }
  }

  void _showSnackbar(String msg, {bool isError = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: isError ? AppTheme.danger : AppTheme.bgElevated,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mis Boletas de Pago'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh_rounded), onPressed: _loadPayslips),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppTheme.primary))
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: AppTheme.danger)))
              : _payslips.isEmpty
                  ? const Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.receipt_long_rounded, size: 54, color: AppTheme.textMuted),
                          SizedBox(height: 12),
                          Text('No tienes boletas emitidas por RRHH aún.', style: TextStyle(color: AppTheme.textSecondary)),
                        ],
                      ),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.all(16),
                      itemCount: _payslips.length,
                      itemBuilder: (context, index) {
                        final p = _payslips[index];
                        return Card(
                          margin: const EdgeInsets.only(bottom: 14),
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Text(
                                      p.title,
                                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: Colors.white),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: AppTheme.primary.withOpacity(0.15),
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: Text(
                                        '${p.periodMonth}/${p.periodYear}',
                                        style: const TextStyle(color: AppTheme.primaryLight, fontSize: 12, fontWeight: FontWeight.w700),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 12),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('Sueldo Bruto', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                                        const SizedBox(height: 2),
                                        Text('S/ ${p.grossSalary.toStringAsFixed(2)}', style: const TextStyle(fontSize: 13, color: Colors.white70)),
                                      ],
                                    ),
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text('Deducciones / AFP', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                                        const SizedBox(height: 2),
                                        Text('- S/ ${p.deductions.toStringAsFixed(2)}', style: const TextStyle(fontSize: 13, color: AppTheme.danger)),
                                      ],
                                    ),
                                    Column(
                                      crossAxisAlignment: CrossAxisAlignment.end,
                                      children: [
                                        const Text('Neto a Pagar', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                                        const SizedBox(height: 2),
                                        Text(
                                          'S/ ${p.netSalary.toStringAsFixed(2)}',
                                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppTheme.success),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                                const Divider(color: AppTheme.bgBorder, height: 24),
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Row(
                                      children: [
                                        Icon(
                                          p.isRead ? Icons.done_all_rounded : Icons.mark_email_unread_outlined,
                                          size: 16,
                                          color: p.isRead ? AppTheme.info : AppTheme.textMuted,
                                        ),
                                        const SizedBox(width: 6),
                                        Text(
                                          p.isRead ? 'Confirmada' : 'Sin abrir',
                                          style: TextStyle(fontSize: 11, color: p.isRead ? AppTheme.info : AppTheme.textMuted),
                                        ),
                                      ],
                                    ),
                                    ElevatedButton.icon(
                                      style: ElevatedButton.styleFrom(
                                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                        backgroundColor: AppTheme.primary,
                                      ),
                                      onPressed: () => _downloadPayslip(p),
                                      icon: const Icon(Icons.picture_as_pdf_rounded, size: 16),
                                      label: const Text('Descargar PDF', style: TextStyle(fontSize: 12)),
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
}
