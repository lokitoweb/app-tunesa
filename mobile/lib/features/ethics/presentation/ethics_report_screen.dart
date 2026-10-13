import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../../../core/widgets/picker_field.dart';
import '../domain/models.dart';
import 'ethics_providers.dart';

/// Formulario de denuncia. Al enviarla muestra el código y la clave una sola vez.
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
            identified: true,
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
              // Selector en hoja inferior: el menú desplegable ocupaba toda la
              // pantalla y se salía de los márgenes con categorías largas
              FormField<String>(
                key: const Key('ethics-category'),
                initialValue: _category,
                validator: (v) => v == null ? 'Elige el tipo' : null,
                builder: (field) {
                  final selected = list.where((c) => c.key == field.value).firstOrNull;
                  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    PickerField(
                      label: 'Tipo de denuncia',
                      icon: Icons.category_outlined,
                      value: selected?.label,
                      placeholder: 'Selecciona una opción',
                      onTap: () async {
                        final picked = await showModalBottomSheet<String>(
                          context: context,
                          showDragHandle: true,
                          isScrollControlled: true,
                          builder: (sheet) => SafeArea(
                            child: ConstrainedBox(
                              constraints: BoxConstraints(maxHeight: MediaQuery.of(sheet).size.height * 0.6),
                              child: ListView(shrinkWrap: true, children: [
                                const Padding(
                                  padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
                                  child: Text('Tipo de denuncia', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                                ),
                                for (final c in list)
                                  ListTile(
                                    key: Key('ethics-category-${c.key}'),
                                    title: Text(c.label),
                                    trailing: c.key == field.value ? const Icon(Icons.check_rounded) : null,
                                    onTap: () => Navigator.of(sheet).pop(c.key),
                                  ),
                              ]),
                            ),
                          ),
                        );
                        if (picked == null) return;
                        field.didChange(picked);
                        if (field.hasError) field.validate();
                        setState(() => _category = picked);
                      },
                    ),
                    if (field.hasError)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(12, 6, 12, 0),
                        child: Text(field.errorText!,
                            style: TextStyle(color: Theme.of(context).colorScheme.error, fontSize: 12)),
                      ),
                  ]);
                },
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
                  hintText: 'Qué ocurrió, cuándo, dónde y quiénes participaron.',
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
              Card(
                key: const Key('ethics-privacy'),
                margin: EdgeInsets.zero,
                child: ListTile(
                  leading: const Icon(Icons.lock_outline),
                  title: const Text('Nadie en la app sabrá que fuiste tú'),
                  subtitle: const Text(
                      'Tu jefe, tus compañeros y las personas denunciadas no verán tu nombre. Por temas legales, la denuncia queda registrada con tu usuario y solo el área de Recursos Humanos puede consultarlo.'),
                ),
              ),
              const SizedBox(height: 8),
              SwitchListTile(
                key: const Key('ethics-keep'),
                contentPadding: EdgeInsets.zero,
                value: _keep,
                onChanged: (v) => setState(() => _keep = v),
                title: const Text('Guardar en este celular'),
                subtitle: const Text('Para consultarla sin escribir la clave. Queda cifrada y solo en este equipo.'),
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

/// Código y clave recién generados. Es la única vez que se muestra la clave.
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
                ? 'La guardamos en este celular. Anota igual el código y la clave por si cambias de equipo.'
                : 'Anota el código y la clave: son la única forma de seguir la denuncia y no los volveremos a mostrar.',
            textAlign: TextAlign.center,
            style: TextStyle(color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),
          _SecretRow(label: 'Código', value: saved.code, valueKey: const Key('created-code')),
          _SecretRow(label: 'Clave', value: saved.token, valueKey: const Key('created-token')),
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
