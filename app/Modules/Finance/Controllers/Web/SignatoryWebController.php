<?php

namespace App\Modules\Finance\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\SafeUpload;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\PrivateFile;
use App\Modules\Finance\Models\Signatory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apoderados y gerentes con firma escaneada: la firma se estampa en la OC/OS que aprueban y
 * en la OP que autorizan. "Autoriza pagos" los hace la persona designada por la empresa.
 * Un usuario = un apoderado (se vincula a la cuenta que ya existe, sin duplicar personas).
 */
class SignatoryWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('Finance/Pages/Signatories/Index', [
            'signatories' => Signatory::with('user:id,first_name,last_name,email,document_number')->orderByDesc('is_active')->get()
                ->map(fn (Signatory $s) => $s->only(['id', 'user_id', 'position', 'can_authorize', 'is_active']) + [
                    'name' => $s->user?->full_name, 'email' => $s->user?->email, 'dni' => $s->user?->document_number,
                    'has_signature' => (bool) $s->signature_path,
                ]),
            'users' => User::where('status', 'active')->whereNotIn('id', Signatory::pluck('user_id'))
                ->orderBy('first_name')->limit(1000)->get(['id', 'first_name', 'last_name', 'document_number'])
                ->map(fn (User $u) => ['id' => $u->id, 'label' => trim("{$u->full_name} · {$u->document_number}", ' ·')]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa.');
        $data = $this->validated($request, true);
        $data['signature_path'] = $this->storeSignature($request);
        unset($data['signature']);
        $signatory = Signatory::create($data);
        $this->audit->log('signatory.created', $signatory, ['user_id' => $signatory->user_id, 'can_authorize' => $signatory->can_authorize]);

        return back()->with('success', 'Apoderado registrado.');
    }

    /** POST: puede traer la firma nueva */
    public function update(Request $request, int $id): RedirectResponse
    {
        $signatory = Signatory::findOrFail($id);
        $data = $this->validated($request, false);
        unset($data['signature'], $data['user_id']);
        if ($request->hasFile('signature')) {
            if ($signatory->signature_path) {
                Storage::disk(Signatory::DISK)->delete($signatory->signature_path);
            }
            $data['signature_path'] = $this->storeSignature($request);
        }
        $signatory->update($data);
        $this->audit->log('signatory.updated', $signatory, ['can_authorize' => $signatory->can_authorize, 'is_active' => $signatory->is_active]);

        return back()->with('success', 'Apoderado actualizado.');
    }

    public function signature(int $id)
    {
        $signatory = Signatory::findOrFail($id);

        return PrivateFile::response(Signatory::DISK, $signatory->signature_path);
    }

    private function storeSignature(Request $request): ?string
    {
        return $request->file('signature')?->store('finance/' . current_tenant_id_or_null() . '/signatures', Signatory::DISK);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'user_id'       => $creating
                ? ['required', Rule::exists('users', 'id')->where('tenant_id', current_tenant_id_or_null()), Rule::unique('signatories')->where('tenant_id', current_tenant_id_or_null())]
                : ['prohibited'],
            'position'      => ['required', 'string', 'max:100'],
            'can_authorize' => ['boolean'],
            'is_active'     => ['boolean'],
            'signature'     => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048', new SafeUpload(['image/png', 'image/jpeg'])],
        ], [
            'user_id.unique' => 'Esa persona ya es apoderado.',
            'signature.mimes' => 'La firma debe ser una imagen PNG o JPG.',
            'signature.max'   => 'La firma pesa más de 2 MB.',
        ], ['user_id' => 'la persona', 'position' => 'el cargo', 'signature' => 'la firma']);
    }
}
