/// Incorporación del colaborador (GET /api/onboarding).
///
/// Mientras RRHH no la apruebe, el servidor rechaza toda marcación con
/// `403 ONBOARDING_PENDING`; la app oculta los botones de marcar.
enum OnboardingStatus {
  pending,
  submitted,
  approved,
  rejected;

  static OnboardingStatus fromApi(Object? value) => switch (value?.toString()) {
        'submitted' => OnboardingStatus.submitted,
        'rejected' => OnboardingStatus.rejected,
        'pending' => OnboardingStatus.pending,
        _ => OnboardingStatus.approved,
      };
}

enum StageStatus {
  completed,
  pending,
  notApplicable;

  static StageStatus fromApi(Object? value) => switch (value?.toString()) {
        'completed' => StageStatus.completed,
        'not_applicable' => StageStatus.notApplicable,
        _ => StageStatus.pending,
      };
}

class OnboardingStage {
  const OnboardingStage({
    required this.key,
    required this.title,
    required this.description,
    required this.required,
    required this.status,
    this.missing = const [],
  });

  /// personal_data, uniforms, documents, induction
  final String key;
  final String title;
  final String description;
  final bool required;
  final StageStatus status;

  /// Textos listos para mostrar ("Talla de calzado", "Documento de identidad").
  final List<String> missing;

  bool get isDone => status != StageStatus.pending;

  factory OnboardingStage.fromJson(Map<String, dynamic> json) => OnboardingStage(
        key: json['key']?.toString() ?? '',
        title: json['title']?.toString() ?? '',
        description: json['description']?.toString() ?? '',
        required: json['required'] != false,
        status: StageStatus.fromApi(json['status']),
        missing: [for (final m in (json['missing'] as List?) ?? const []) '$m'],
      );
}

class OnboardingSummary {
  const OnboardingSummary({
    required this.status,
    required this.canPunch,
    this.canSubmit = false,
    this.nextStage,
    this.rejectionReason,
    this.stages = const [],
  });

  /// Sin candado (incorporación aprobada o empresa sin el módulo).
  static const approved = OnboardingSummary(status: OnboardingStatus.approved, canPunch: true);

  final OnboardingStatus status;
  final bool canPunch;
  final bool canSubmit;
  final String? nextStage;
  final String? rejectionReason;
  final List<OnboardingStage> stages;

  /// Etapas que se muestran (las que no aplican se ocultan).
  List<OnboardingStage> get visibleStages => stages.where((s) => s.status != StageStatus.notApplicable).toList();

  int get doneCount => visibleStages.where((s) => s.isDone).length;

  factory OnboardingSummary.fromJson(Map<String, dynamic> json) => OnboardingSummary(
        status: OnboardingStatus.fromApi(json['status']),
        canPunch: json['can_punch'] == true,
        canSubmit: json['can_submit'] == true,
        nextStage: json['next_stage'] as String?,
        rejectionReason: json['rejection_reason'] as String?,
        stages: [
          for (final s in (json['stages'] as List?) ?? const [])
            if (s is Map) OnboardingStage.fromJson(Map<String, dynamic>.from(s)),
        ],
      );
}
