/// Canal ético: denuncias anónimas que se siguen con código + token.
library;

String _str(Object? v, [String fallback = '']) {
  final s = v?.toString().trim();
  return s == null || s.isEmpty ? fallback : s;
}

int _int(Object? v) => v is num ? v.toInt() : int.tryParse(v?.toString() ?? '') ?? 0;

class EthicsCategory {
  const EthicsCategory(this.key, this.label);

  final String key;
  final String label;

  factory EthicsCategory.fromJson(Map<String, dynamic> json) => EthicsCategory(_str(json['key']), _str(json['label']));
}

enum EthicsStatus {
  received('Recibida'),
  inReview('En investigación'),
  needsInfo('Necesita más información'),
  closed('Cerrada');

  const EthicsStatus(this.label);

  final String label;

  static EthicsStatus fromApi(Object? raw) => switch (raw?.toString()) {
        'in_review' => EthicsStatus.inReview,
        'needs_info' => EthicsStatus.needsInfo,
        'closed' => EthicsStatus.closed,
        _ => EthicsStatus.received,
      };
}

class EthicsMessage {
  const EthicsMessage({required this.fromReporter, required this.body, this.sentOn, this.photos = 0});

  final bool fromReporter;
  final String body;
  final DateTime? sentOn;
  final int photos;

  factory EthicsMessage.fromJson(Map<String, dynamic> json) => EthicsMessage(
        fromReporter: json['from'] == 'reporter',
        body: _str(json['body']),
        sentOn: DateTime.tryParse(_str(json['sent_on'])),
        photos: _int(json['photos']),
      );
}

/// Lo que el denunciante ve de su caso.
class EthicsCaseView {
  const EthicsCaseView({
    required this.code,
    required this.categoryLabel,
    required this.subject,
    required this.body,
    required this.status,
    this.reportedOn,
    this.resolution,
    this.photos = 0,
    this.messages = const [],
  });

  final String code;
  final String categoryLabel;
  final String subject;
  final String body;
  final EthicsStatus status;
  final DateTime? reportedOn;
  final String? resolution;
  final int photos;
  final List<EthicsMessage> messages;

  bool get isClosed => status == EthicsStatus.closed;

  factory EthicsCaseView.fromJson(Map<String, dynamic> json) => EthicsCaseView(
        code: _str(json['code']),
        categoryLabel: _str(json['category_label'], 'Denuncia'),
        subject: _str(json['subject']),
        body: _str(json['body']),
        status: EthicsStatus.fromApi(json['status']),
        reportedOn: DateTime.tryParse(_str(json['reported_on'])),
        resolution: json['resolution'] == null ? null : _str(json['resolution']),
        photos: _int(json['photos']),
        messages: ((json['messages'] as List?) ?? const [])
            .map((e) => EthicsMessage.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
      );
}

/// Denuncia guardada en este celular (almacenamiento cifrado) para no tener
/// que escribir el código y el token cada vez.
class SavedEthicsCase {
  const SavedEthicsCase({required this.code, required this.token, required this.subject, this.channel});

  final String code;
  final String token;
  final String subject;

  /// Tema de FCM aleatorio de este caso (avisos de novedades), si se activó.
  final String? channel;

  Map<String, dynamic> toJson() => {'code': code, 'token': token, 'subject': subject, 'channel': channel};

  factory SavedEthicsCase.fromJson(Map<String, dynamic> json) => SavedEthicsCase(
        code: _str(json['code']),
        token: _str(json['token']),
        subject: _str(json['subject']),
        channel: json['channel'] == null ? null : _str(json['channel']),
      );
}
