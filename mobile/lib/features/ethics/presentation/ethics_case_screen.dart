import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'ethics_providers.dart';

/// Seguimiento de una denuncia: estado, conversación y respuesta.
class EthicsCaseScreen extends ConsumerStatefulWidget {
  const EthicsCaseScreen({super.key, required this.saved});

  final SavedEthicsCase saved;

  @override
  ConsumerState<EthicsCaseScreen> createState() => _EthicsCaseScreenState();
}

class _EthicsCaseScreenState extends ConsumerState<EthicsCaseScreen> {
  final _reply = TextEditingController();
  bool _sending = false;

  ({String code, String token}) get _key => (code: widget.saved.code, token: widget.saved.token);

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _reply.text.trim();
    if (text.length < 2) return;
    setState(() => _sending = true);
    try {
      await ref.read(ethicsRepositoryProvider).send(_key.code, _key.token, text);
      _reply.clear();
      ref.invalidate(ethicsCaseProvider(_key));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(ethicsCaseProvider(_key));
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(widget.saved.code)),
      body: detail.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(ethicsCaseProvider(_key))),
        data: (c) => RefreshIndicator(
          onRefresh: () => ref.refresh(ethicsCaseProvider(_key).future),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            children: [
              Row(children: [
                Chip(
                  key: const Key('ethics-status'),
                  label: Text(c.status.label),
                  backgroundColor: c.status == EthicsStatus.needsInfo ? scheme.tertiaryContainer : null,
                ),
                const SizedBox(width: 8),
                Expanded(child: Text(c.categoryLabel, style: TextStyle(color: scheme.onSurfaceVariant))),
              ]),
              if (c.status == EthicsStatus.needsInfo)
                Card(
                  color: scheme.tertiaryContainer,
                  child: const ListTile(
                    leading: Icon(Icons.help_outline),
                    title: Text('Quien investiga necesita más información. Responde abajo.'),
                  ),
                ),
              if (c.isClosed && c.resolution != null)
                Card(
                  color: Colors.green.withValues(alpha: 0.12),
                  child: ListTile(
                    leading: const Icon(Icons.check_circle_outline),
                    title: const Text('Conclusión'),
                    subtitle: Text(c.resolution!, key: const Key('ethics-resolution')),
                  ),
                ),
              const SizedBox(height: 8),
              Text(c.subject, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              if (c.reportedOn != null)
                Text('Enviada el ${Fmt.date(c.reportedOn)}', style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
              const SizedBox(height: 8),
              Text(c.body),
              if (c.photos > 0) Text('${c.photos} foto(s) adjunta(s)', style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
              const Divider(height: 32),
              if (c.messages.isEmpty)
                Text('Aún no hay mensajes. Te avisaremos cuando quien investiga responda.', style: TextStyle(color: scheme.onSurfaceVariant)),
              for (final m in c.messages) _Bubble(message: m),
            ],
          ),
        ),
      ),
      bottomNavigationBar: detail.whenOrNull(
        data: (c) => c.isClosed
            ? null
            : SafeArea(
                minimum: const EdgeInsets.fromLTRB(12, 8, 12, 12),
                child: Padding(
                  padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
                  child: Row(children: [
                    Expanded(
                      child: TextField(
                        key: const Key('ethics-reply'),
                        controller: _reply,
                        minLines: 1,
                        maxLines: 4,
                        decoration: const InputDecoration(hintText: 'Escribe un mensaje'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filled(
                      key: const Key('ethics-send'),
                      onPressed: _sending ? null : _send,
                      icon: const Icon(Icons.send_rounded),
                    ),
                  ]),
                ),
              ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});

  final EthicsMessage message;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final mine = message.fromReporter;
    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.8),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: mine ? scheme.primaryContainer : scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(mine ? 'Tú' : 'Quien investiga', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(message.body),
          if (message.sentOn != null)
            Text(Fmt.date(message.sentOn), style: TextStyle(fontSize: 11, color: scheme.onSurfaceVariant)),
        ]),
      ),
    );
  }
}
