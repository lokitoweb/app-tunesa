/// Categoría de beneficios (la crea RRHH en el panel).
class BenefitCategory {
  const BenefitCategory({required this.id, required this.name, this.icon, this.color, this.count = 0});

  final int id;
  final String name;
  final String? icon;
  final String? color;
  final int count;

  factory BenefitCategory.fromJson(Map<String, dynamic> json) => BenefitCategory(
        id: (json['id'] as num).toInt(),
        name: json['name']?.toString() ?? '',
        icon: json['icon']?.toString(),
        color: json['color']?.toString(),
        count: (json['count'] as num?)?.toInt() ?? 0,
      );
}

/// Beneficio para el personal (descuento, convenio, promoción...).
class Benefit {
  const Benefit({
    required this.id,
    required this.title,
    this.summary,
    this.partner,
    this.highlight,
    this.imageUrl,
    this.isFeatured = false,
    this.validUntil,
    this.category,
    this.description,
    this.howToUse,
    this.terms,
    this.promoCode,
    this.url,
    this.contact,
    this.location,
  });

  final int id;
  final String title;
  final String? summary;
  final String? partner;

  /// "20% dscto", "2x1"...
  final String? highlight;
  final String? imageUrl;
  final bool isFeatured;
  final DateTime? validUntil;
  final BenefitCategory? category;

  // Solo en el detalle
  final String? description;
  final String? howToUse;
  final String? terms;
  final String? promoCode;
  final String? url;
  final String? contact;
  final String? location;

  factory Benefit.fromJson(Map<String, dynamic> json) {
    String? text(String key) {
      final v = json[key]?.toString().trim();
      return v == null || v.isEmpty ? null : v;
    }

    return Benefit(
      id: (json['id'] as num).toInt(),
      title: json['title']?.toString() ?? '',
      summary: text('summary'),
      partner: text('partner'),
      highlight: text('highlight'),
      imageUrl: text('image_url'),
      isFeatured: json['is_featured'] == true,
      validUntil: DateTime.tryParse(json['valid_until']?.toString() ?? ''),
      category: json['category'] is Map ? BenefitCategory.fromJson(Map<String, dynamic>.from(json['category'] as Map)) : null,
      description: text('description'),
      howToUse: text('how_to_use'),
      terms: text('terms'),
      promoCode: text('promo_code'),
      url: text('url'),
      contact: text('contact'),
      location: text('location'),
    );
  }
}

/// Catálogo de la persona: beneficios vigentes y las categorías que tienen algo.
class BenefitCatalog {
  const BenefitCatalog({required this.items, required this.categories});

  final List<Benefit> items;
  final List<BenefitCategory> categories;
}
