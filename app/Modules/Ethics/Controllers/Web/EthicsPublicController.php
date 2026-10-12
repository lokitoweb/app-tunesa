<?php

namespace App\Modules\Ethics\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Services\TenantContext;
use App\Modules\Ethics\Models\EthicsCase;
use App\Modules\Ethics\Services\EthicsService;
use App\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Canal ético en la web, sin iniciar sesión (/canal-etico/{empresa}).
 *
 * Corre sin sesión, sin cookies ni CSRF (ver Routes/web.php) y detrás de
 * StripIdentity: nada de la visita queda asociado a una persona. Todo se
 * responde en la misma página (sin redirecciones), y el código y la clave
 * viajan en el cuerpo del formulario, nunca en la URL.
 */
class EthicsPublicController extends Controller
{
    private const PHOTO_RULES = ['nullable', 'array', 'max:3'];

    public function __construct(private readonly EthicsService $ethics) {}

    public function show(string $slug): View
    {
        return $this->page($this->tenant($slug), 'home');
    }

    public function store(Request $request, string $slug): View
    {
        $tenant = $this->tenant($slug);
        $validator = Validator::make($request->all(), [
            'category' => ['required', Rule::in(array_keys(EthicsCase::CATEGORIES))],
            'subject'  => ['required', 'string', 'min:5', 'max:150'],
            'body'     => ['required', 'string', 'min:20', 'max:5000'],
            'photos'   => self::PHOTO_RULES,
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], ['category' => 'tipo de denuncia', 'subject' => 'asunto', 'body' => 'detalle']);

        if ($validator->fails()) {
            return $this->page($tenant, 'home', ['old' => $request->only('category', 'subject', 'body')], $validator->errors());
        }

        $result = $this->ethics->report($tenant, $validator->validated(), array_values(array_filter((array) $request->file('photos', []))));

        return $this->page($tenant, 'created', ['code' => $result['case']->public_code, 'token' => $result['token']]);
    }

    public function follow(Request $request, string $slug): View
    {
        $tenant = $this->tenant($slug);
        $case = $this->ethics->find($tenant, (string) $request->input('code'), (string) $request->input('token'));

        if (! $case) {
            return $this->page($tenant, 'home', ['followOld' => $request->only('code')],
                new MessageBag(['code' => 'No encontramos una denuncia con ese código y clave. Revisa que estén bien escritos.']));
        }
        if ($case->reporter_unread) {
            $case->forceFill(['reporter_unread' => false])->save();
        }

        return $this->casePage($tenant, $case, (string) $request->input('token'));
    }

    public function message(Request $request, string $slug): View
    {
        $tenant = $this->tenant($slug);
        $token = (string) $request->input('token');
        $case = $this->ethics->find($tenant, (string) $request->input('code'), $token);
        abort_unless($case, 404);

        $validator = Validator::make($request->all(), [
            'body'     => ['required', 'string', 'min:2', 'max:5000'],
            'photos'   => self::PHOTO_RULES,
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], ['body' => 'mensaje']);

        if ($validator->fails()) {
            return $this->casePage($tenant, $case, $token, $validator->errors());
        }

        try {
            $this->ethics->reporterMessage($case, $validator->validated()['body'], array_values(array_filter((array) $request->file('photos', []))));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->casePage($tenant, $case, $token, new MessageBag($e->errors()));
        }

        return $this->casePage($tenant, $case->fresh(), $token, null, 'Mensaje enviado.');
    }

    private function casePage(Tenant $tenant, EthicsCase $case, string $token, ?MessageBag $errors = null, ?string $notice = null): View
    {
        $case->loadMissing('messages.attachments', 'attachments');

        return $this->page($tenant, 'case', ['case' => $case, 'token' => $token, 'notice' => $notice], $errors);
    }

    private function page(Tenant $tenant, string $screen, array $data = [], ?MessageBag $errors = null): View
    {
        return view('ethics.public', $data + [
            'tenant'     => $tenant,
            'screen'     => $screen,
            'categories' => EthicsCase::CATEGORIES,
            'old'        => [],
            'followOld'  => [],
            'errors'     => (new ViewErrorBag())->put('default', $errors ?? new MessageBag()),
        ]);
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        abort_unless($tenant && ModuleRegistry::enabledFor($tenant, 'Ethics'), 404);
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }
}
