<?php

namespace App\Modules\Benefits\Controllers\Web;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\Benefits\Models\BenefitCategory;
use App\Modules\Benefits\Services\BenefitService;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Audience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beneficios en el panel: catálogo (descuentos, convenios, promociones...),
 * categorías propias y a quién se le muestra cada uno en la app.
 */
class BenefitWebController extends Controller
{
    public function __construct(
        private readonly BenefitService $benefits,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        BenefitCategory::ensureDefaults();
        $status   = $request->query('status');
        $category = $request->integer('category') ?: null;
        $search   = trim((string) $request->query('search'));
        $today    = now(tenant_timezone())->toDateString();

        $benefits = Benefit::with('category')
            ->when(array_key_exists($status, Benefit::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($status === 'expired', fn ($q) => $q->whereDate('valid_until', '<', $today))
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('partner', 'like', "%{$search}%")))
            ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('is_featured')->latest('updated_at')
            ->paginate(20)->withQueryString()
            ->through(fn (Benefit $b) => [
                'id'          => $b->id,
                'tenant_id'   => $b->tenant_id,
                'title'       => $b->title,
                'partner'     => $b->partner,
                'highlight'   => $b->highlight,
                'category'    => $b->category ? ['name' => $b->category->name, 'icon' => $b->category->icon, 'color' => $b->category->color] : null,
                'image_url'   => $b->imageUrl(),
                'status'      => $b->status,
                'is_featured' => $b->is_featured,
                'expired'     => $b->isExpired(),
                'valid_until' => $b->valid_until?->format('d/m/Y'),
            ]);

        return Inertia::render('Benefits/Pages/Benefits/Index', [
            'benefits'   => $benefits,
            'categories' => BenefitCategory::orderBy('position')->get(['id', 'name']),
            'statuses'   => Benefit::STATUSES,
            'totals'     => [
                'published' => Benefit::available()->count(),
                'draft'     => Benefit::where('status', 'draft')->count(),
                'expired'   => Benefit::published()->whereDate('valid_until', '<', $today)->count(),
            ],
            'filters'    => ['status' => $status, 'category' => $category, 'search' => $search],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (! current_tenant_id()) {
            return redirect()->route('benefits.index')->with('error', 'Elige una empresa en el selector para crear un beneficio.');
        }
        BenefitCategory::ensureDefaults();

        return $this->form(new Benefit(['status' => 'draft']));
    }

    public function edit(int $id): Response
    {
        return $this->form(Benefit::with('audienceTargets')->findOrFail($id));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id(), 422, 'Elige una empresa para crear el beneficio.');
        $benefit = $this->benefits->save(new Benefit(), $this->validated($request), $request->user(), $request->file('image'));
        $this->audit->logCreated('benefit.created', $benefit, ['title' => $benefit->title]);

        return redirect()->route('benefits.index')->with('success', $this->savedMessage($benefit, 'creado'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $benefit = Benefit::findOrFail($id);
        $this->benefits->save($benefit, $this->validated($request), $request->user(), $request->file('image'), $request->boolean('remove_image'));
        $this->audit->log('benefit.updated', $benefit, ['title' => $benefit->title]);

        return redirect()->route('benefits.index')->with('success', $this->savedMessage($benefit, 'actualizado'));
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        $status  = $request->validate(['status' => ['required', Rule::in(array_keys(Benefit::STATUSES))]])['status'];
        $benefit = Benefit::withCount('audienceTargets')->findOrFail($id);

        if ($status === 'published' && ! $benefit->audience_targets_count) {
            return back()->with('error', 'Elige a quién se le muestra el beneficio antes de publicarlo.');
        }

        $this->benefits->setStatus($benefit, $status);
        $this->audit->log("benefit.{$status}", $benefit, ['title' => $benefit->title]);

        return back()->with('success', match ($status) {
            'published' => 'Beneficio publicado: ya aparece en la app de su público.',
            'archived'  => 'Beneficio archivado: ya no aparece en la app.',
            default     => 'El beneficio volvió a borrador y ya no aparece en la app.',
        });
    }

    public function destroy(int $id): RedirectResponse
    {
        $benefit = Benefit::findOrFail($id);
        $benefit->delete();
        $this->audit->log('benefit.deleted', $benefit, ['title' => $benefit->title]);

        return redirect()->route('benefits.index')->with('success', 'Beneficio eliminado.');
    }

    // ---------------------------------------------------------------- categorías

    public function categories(): Response
    {
        BenefitCategory::ensureDefaults();

        return Inertia::render('Benefits/Pages/Benefits/Categories', [
            'categories' => BenefitCategory::withCount('benefits')->orderBy('position')->get()
                ->map(fn (BenefitCategory $c) => $c->only(['id', 'name', 'icon', 'color', 'position', 'benefits_count'])),
            'icons'      => BenefitCategory::ICONS,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id(), 422, 'Elige una empresa.');
        $category = BenefitCategory::create($this->validatedCategory($request) + ['position' => (int) BenefitCategory::max('position') + 1]);
        $this->audit->logCreated('benefit_category.created', $category, ['name' => $category->name]);

        return back()->with('success', 'Categoría creada.');
    }

    public function updateCategory(Request $request, int $id): RedirectResponse
    {
        $category = BenefitCategory::findOrFail($id);
        $category->update($this->validatedCategory($request, $category->id));
        $this->audit->log('benefit_category.updated', $category, ['name' => $category->name]);

        return back()->with('success', 'Categoría actualizada.');
    }

    public function destroyCategory(int $id): RedirectResponse
    {
        $category = BenefitCategory::findOrFail($id);
        $category->delete();
        $this->audit->log('benefit_category.deleted', $category, ['name' => $category->name]);

        return back()->with('success', 'Categoría eliminada. Sus beneficios quedaron sin categoría.');
    }

    // ------------------------------------------------------------------ privado

    private function form(Benefit $benefit): Response
    {
        return Inertia::render('Benefits/Pages/Benefits/Form', [
            'benefit' => [
                'id'          => $benefit->id,
                'title'       => $benefit->title ?? '',
                'summary'     => $benefit->summary ?? '',
                'description' => $benefit->description ?? '',
                'category_id' => $benefit->category_id,
                'partner'     => $benefit->partner ?? '',
                'highlight'   => $benefit->highlight ?? '',
                'promo_code'  => $benefit->promo_code ?? '',
                'how_to_use'  => $benefit->how_to_use ?? '',
                'terms'       => $benefit->terms ?? '',
                'url'         => $benefit->url ?? '',
                'contact'     => $benefit->contact ?? '',
                'location'    => $benefit->location ?? '',
                'valid_from'  => $benefit->valid_from?->format('Y-m-d'),
                'valid_until' => $benefit->valid_until?->format('Y-m-d'),
                'is_featured' => (bool) $benefit->is_featured,
                'status'      => $benefit->status,
                'image_url'   => $benefit->imageUrl(),
                'image'       => null,
                'remove_image' => false,
                'notify'      => true,
                'targets'     => Audience::toForm($benefit->exists ? $benefit->audienceTargets : collect()),
            ],
            'categories' => BenefitCategory::orderBy('position')->get(['id', 'name']),
            'audience'   => Audience::formOptions(),
        ]);
    }

    private function validated(Request $request): array
    {
        $publishing = $request->input('status') === 'published';
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:150'],
            'summary'      => ['nullable', 'string', 'max:200'],
            'description'  => ['nullable', 'string', 'max:10000'],
            'category_id'  => ['nullable', 'integer', Rule::exists('benefit_categories', 'id')->where('tenant_id', current_tenant_id())],
            'partner'      => ['nullable', 'string', 'max:120'],
            'highlight'    => ['nullable', 'string', 'max:40'],
            'promo_code'   => ['nullable', 'string', 'max:60'],
            'how_to_use'   => ['nullable', 'string', 'max:5000'],
            'terms'        => ['nullable', 'string', 'max:5000'],
            'url'          => ['nullable', 'url:https', 'max:500'],
            'contact'      => ['nullable', 'string', 'max:150'],
            'location'     => ['nullable', 'string', 'max:200'],
            'valid_from'   => ['nullable', 'date'],
            'valid_until'  => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_featured'  => ['boolean'],
            'status'       => ['required', Rule::in(array_keys(Benefit::STATUSES))],
            'image'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['boolean'],
            'notify'       => ['boolean'],
        ] + Audience::rules($publishing), Audience::MESSAGES + [
            'url.url'                    => 'El enlace debe empezar con https://.',
            'valid_until.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la de inicio.',
            'image.max'                  => 'La imagen no puede pesar más de 4 MB.',
        ]);
        Audience::validateIds($data['targets'] ?? [], current_tenant_id());

        return $data;
    }

    private function validatedCategory(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'name'  => ['required', 'string', 'max:80', Rule::unique('benefit_categories', 'name')->where('tenant_id', current_tenant_id())->ignore($ignore)],
            'icon'  => ['required', Rule::in(array_keys(BenefitCategory::ICONS))],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['nullable', 'integer', 'between:0,999'],
        ], ['name.unique' => 'Ya existe una categoría con ese nombre.']);
    }

    private function savedMessage(Benefit $benefit, string $verb): string
    {
        return $benefit->isPublished()
            ? "Beneficio {$verb} y publicado: ya aparece en la app de su público."
            : "Beneficio {$verb} como " . mb_strtolower(Benefit::STATUSES[$benefit->status]) . '.';
    }
}
