import 'package:flutter/material.dart';

/// Íconos que el panel elige por nombre (bootstrap-icons) → Material en la app.
/// Mismos nombres que BenefitCategory::ICONS y CompanyDocumentFolder::ICONS.
IconData moduleIcon(String? name, {IconData fallback = Icons.folder_rounded}) => switch (name) {
      'gift' => Icons.card_giftcard_rounded,
      'tag' => Icons.local_offer_rounded,
      'percent' => Icons.percent_rounded,
      'heart-pulse' => Icons.monitor_heart_rounded,
      'cup-hot' => Icons.restaurant_rounded,
      'book' => Icons.menu_book_rounded,
      'film' => Icons.movie_rounded,
      'piggy-bank' => Icons.savings_rounded,
      'bag' => Icons.shopping_bag_rounded,
      'car-front' => Icons.directions_car_rounded,
      'airplane' => Icons.flight_rounded,
      'house' => Icons.home_rounded,
      'bicycle' => Icons.directions_bike_rounded,
      'phone' => Icons.smartphone_rounded,
      'people' => Icons.groups_rounded,
      'star' => Icons.star_rounded,
      'folder' => Icons.folder_rounded,
      'shield-check' => Icons.verified_user_rounded,
      'journal-text' => Icons.gavel_rounded,
      'file-earmark-text' => Icons.description_rounded,
      'clipboard-check' => Icons.assignment_turned_in_rounded,
      'megaphone' => Icons.campaign_rounded,
      'mortarboard' => Icons.school_rounded,
      'building' => Icons.business_rounded,
      'cash-coin' => Icons.payments_rounded,
      _ => fallback,
    };

/// Color "#RRGGBB" del panel; si no es válido, [fallback].
Color hexColor(String? hex, Color fallback) {
  final value = hex?.replaceFirst('#', '') ?? '';
  if (value.length != 6) return fallback;
  final parsed = int.tryParse(value, radix: 16);
  return parsed == null ? fallback : Color(0xFF000000 | parsed);
}
