import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'ethics_providers.dart';

/// Formulario de denuncia. Al enviarla muestra el código y el token una sola vez.
class EthicsReportScreen extends ConsumerStatefulWidget {
  const EthicsReportScreen({super.key});

  @override
  ConsumerState<EthicsReportScreen> createState() => _EthicsReportScreenState();
}

class _EthicsReportScreenState extends ConsumerState<EthicsReportScreen> {
  final _form = GlobalKey<FormState>();
  final _subject = TextEditingController();
  final _body = TextEditingController();
  final _photos = <List<int>>[];
  String? _category;
  bool _keep = true;
  bool _notify = true;
  bool _sending = false;

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _addPhoto() async {
    // imageQuality re-codifica la foto en el celular: se van los metadatos (GPS, modelo)
    final image = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 2000, imageQuality: 85);
    if (image == null) return;
    final bytes = await image.readAsBytes();
    if (bytes.length > 5 * 1024 * 1024) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('La foto supera 5 MB.')));
      return;
    }
    setState(() => _photos.add(bytes));
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _sending = true);
    final topics = ref.read(ethicsTopicsProvider);
    final channel = _notify ? topics.newChannel() : null;
    try {
      final result = await ref.read(ethicsRepositoryProvider).report(
            category: _category!,
            subject: _subject.text.trim(),
            body: _body.text.trim(),
            channel: channel,
            photos: _photos,
          );
      final saved = SavedEthicsCase(code: result.code, token: result.token, subject: _subject.text.trim(), channel: channel);
      if (_keep) await ref.read(ethicsVaultProvider).save(saved);
      if (channel != null) await topics.subscribe(channel);
      if (mounted) context.pushReplacement('/ethics/created', extra: (saved: saved, kept: _keep));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final categories = ref.watch(ethicsCategoriesProvider);
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: const Text('Nueva denuncia')),
      body: categories.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(ethicsCategoriesProvider)),
        data: (list) => Form(
          key: _form,
          child: ListView(
            padding: pagePadding(context),
            children: [
              DropdownButtonFormField<String>(
                key: const Key('ethics-category'),
                initialValue: _category,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Tipo de denuncia'),
                items: [for (final c in list) DropdownMenuItem(value: c.key, child: Text(c.label))],
                onChanged: (v) => setState(() => _category = v),
                validator: (v) => v == null ? 'Elige el tipo' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                key: const Key('ethics-subject'),
                controller: _subject,
                maxLength: 150,
                decoration: const InputDecoration(labelText: 'Asunto', hintText: 'En pocas palabras, qué pasó'),
                validator: (v) => (v ?? '').trim().length < 5 ? 'Escribe al menos 5 caracteres' : null,
              ),
              const SizedBox(height: 4),
              TextFormField(
                key: const Key('ethics-body'),
                controller: _body,
                minLines: 5,
                maxLines: 12,
                maxLength: 5000,
                decoration: const InputDecoration(
                  labelText: 'Detalle',
                  hintText: 'Qué ocurrió, cuándo, dónde y quiénes participaron. No hace falta que digas quién eres.',
                  alignLabelWithHint: true,
                ),
                validator: (v) => (v ?? '').trim().length < 20 ? 'Cuéntanos un poco más (mínimo 20 caracteres)' : null,
              ),
              const SizedBox(height: 8),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final (i, _) in _photos.indexed)
                  InputChip(label: Text('Foto ${i + 1}'), onDeleted: () => setState(() => _photos.removeAt(i))),
                if (_photos.length < 3)
                  ActionChip(avatar: const Icon(Icons.add_photo_alternate_outlined), label: const Text('Adjuntar foto'), onPressed: _addPhoto),
              ]),
              const SizedBox(height: 12),
              SwitchListTile(
                key: const Key('ethics-keep'),
                contentPadding: EdgeInsets.zero,
                value: _keep,
                onChanged: (v) => setState(() => _keep = v),
                title: const Text('Guardar en este celular'),
                subtitle: const Text('Para consultarla sin escribir el token. Queda cifrada y solo en este equipo.'),
              ),
              SwitchListTile(
                key: const Key('ethics-notify'),
                contentPadding: EdgeInsets.zero,
                value: _notify,
                onChanged: (v) => setState(() => _notify = v),
                title: const Text('Avisarme cuando haya novedades'),
                subtitle: const Text('Usamos un canal aleatorio que no está ligado a tu cuenta.'),
              ),
              const SizedBox(height: 8),
              Text('Envía solo hechos que conozcas. Las denuncias falsas a sabiendas pueden tener consecuencias.',
                  style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
              const SizedBox(height: 80),
            ],
          ),
        ),
      ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        child: SizedBox(
          height: 52,
          child: FilledButton(
            key: const Key('ethics-submit'),
            onPressed: _sending || !categories.hasValue ? null : _submit,
            child: _sending
                ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Enviar denuncia'),
          ),
        ),
      ),
    );
  }
}

/// Código y token recién generados. Es la única vez que se muestra el token.
class EthicsCreatedScreen extends StatelessWidget {
  const EthicsCreatedScreen({super.key, required this.saved, required this.kept});

  final SavedEthicsCase saved;
  final bool kept;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(title: const Text('Denuncia enviada')),
      body: ListView(
        padding: pagePadding(context),
        children: [
          Icon(Icons.verified_user_outlined, size: 72, color: Colors.green.shade600),
          const SizedBox(height: 12),
          const Text('Recibimos tu denuncia', textAlign: TextAlign.center, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Text(
            kept
                ? 'La guardamos en este celular. Anota igual el código y el token por si cambias de equipo.'
                : 'Anota el código y el token: son la única forma de seguir la denuncia y no los volveremos a mostrar.',
            textAlign: TextAlign.center,
            style: TextStyle(color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),
          _SecretRow(label: 'Código', value: saved.code, valueKey: const Key('created-code')),
          _SecretRow(label: 'Token', value: saved.token, valueKey: const Key('created-token')),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: () => context.pushReplacement('/ethics/case', extra: saved),
            child: const Text('Ver mi denuncia'),
          ),
          TextButton(onPressed: () => context.pop(), child: const Text('Listo')),
        ],
      ),
    );
  }
}

class _SecretRow extends StatelessWidget {
  const _SecretRow({required this.label, required this.value, required this.valueKey});

  final String label;
  final String value;
  final Key valueKey;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        title: Text(label),
        subtitle: SelectableText(value, key: valueKey, style: const TextStyle(fontFamily: 'monospace', fontSize: 17, fontWeight: FontWeight.w700)),
        trailing: IconButton(
          tooltip: 'Copiar',
          icon: const Icon(Icons.copy_rounded),
          onPressed: () async {
            await Clipboard.setData(ClipboardData(text: value));
            if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$label copiado')));
          },
        ),
      ),
    );
  }
}
