<?php

namespace App\Modules\Benefits\Controllers\Api;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\Benefits\Models\BenefitCategory;
use App\Modules\Core\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Beneficios del colaborador (app móvil): solo los publicados, vigentes y
 * dirigidos a su sucursal, área, cargo o a él.
 */
class BenefitController extends Controller
{
    /** GET /api/benefits?category=3 */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $benefits = Benefit::available()->visibleToAudience($user)
            ->with('category')
            ->orderByDesc('is_featured')->latest('published_at')
            ->limit(300)
            ->get();

        $categoryIds = $benefits->pluck('category_id')->filter()->unique();

        return response()->json([
            'success' => true,
            'data'    => $benefits->map(fn (Benefit $b) => $this->summary($b))->values(),
            'meta'    => [
                // Solo las categorías que tienen algo para esta persona
                'categories' => BenefitCategory::whereIn('id', $categoryIds)->orderBy('position')->get()
                    ->map(fn (BenefitCategory $c) => [
                        'id' => $c->id, 'name' => $c->name, 'icon' => $c->icon, 'color' => $c->color,
                        'count' => $benefits->where('category_id', $c->id)->count(),
                    ])->values(),
                'total' => $benefits->count(),
            ],
        ]);
    }

    /** GET /api/benefits/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $benefit = Benefit::available()->visibleToAudience($request->user())->with('category')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $this->summary($benefit) + [
                'description' => $benefit->description,
                'how_to_use'  => $benefit->how_to_use,
                'terms'       => $benefit->terms,
                'promo_code'  => $benefit->promo_code,
                'url'         => $benefit->url,
                'contact'     => $benefit->contact,
                'location'    => $benefit->location,
                'valid_from'  => $benefit->valid_from?->toDateString(),
            ],
        ]);
    }

    private function summary(Benefit $b): array
    {
        return [
            'id'          => $b->id,
            'title'       => $b->title,
            'summary'     => $b->summary,
            'partner'     => $b->partner,
            'highlight'   => $b->highlight,
            'image_url'   => $b->imageUrl(),
            'is_featured' => $b->is_featured,
            'valid_until' => $b->valid_until?->toDateString(),
            'category'    => $b->category ? [
                'id' => $b->category->id, 'name' => $b->category->name, 'icon' => $b->category->icon, 'color' => $b->category->color,
            ] : null,
        ];
    }
}
