<?php

namespace App\Modules\Attendance\Controllers\Web;

use App\Modules\Attendance\Models\TripLog;
use App\Modules\Attendance\Models\TripSheet;
use Illuminate\Support\Facades\Storage;
use App\Modules\Attendance\Services\Reports\TripSheetExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bitácora de viajes (requerimiento Tunesa): el supervisor registra cada viaje (N.º de salida automático,
 * ruta, Conductor 1, Conductor 2, Auxiliar de carga, placa y agencia) con su IDA (salida y llegada) y su
 * REGRESO (salida y llegada, que queda firmada con su usuario y la hora). Los choferes no marcan: un día
 * con viaje es día trabajado (meta del mes en Configuración › Reglas, 26 / 4) y las horas de conducción de
 * cada tramo las heredan los tres. Las hojas para firmar salen por semana (lunes a domingo, por fecha de
 * salida) o por todo el periodo, una de conducción de ida y otra de regreso.
 */
class TripLogWebController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $now = CarbonImmutable::now(tenant_timezone());
        [$data, $from, $to] = $this->filters($request);

        $people = $this->people($request);
        $ids    = $people->pluck('id')->all();

        $trips = $this->tripsQuery($request, $data, $from, $to)
            ->orderByDesc('trip_date')->orderByDesc('departure_time')
            ->paginate(25)->withQueryString();

        $settings = TenantSetting::forTenant(current_tenant_id_or_null() ? current_tenant() : null);
        $worked   = TripLog::workedDays($ids, $from, $to);
        // Horas de conducción del periodo (todos los viajes, no solo la página)
        $driving  = TripLog::drivingMinutes(TripLog::whereBetween('trip_date', [$from, $to])->ofPeople($ids)
            ->get(['id', 'trip_date', 'departure_time', 'arrival_date', 'arrival_time', 'return_departure_date', 'return_departure_time',
                'return_date', 'return_time', ...TripLog::CREW]));

        return view('attendance.trips', [
            'trips'     => $trips,
            'people'    => $people,
            'summary'   => $people->map(fn (User $u) => ['user' => $u, 'days' => $worked[$u->id] ?? 0,
                'outbound' => $driving[$u->id]['outbound'] ?? 0, 'return' => $driving[$u->id]['return'] ?? 0])
                ->sortByDesc('days')->values(),
            // Hojas para firmar: semanas de lunes a domingo del periodo (mismas que el tareo) o el periodo completo
            // N.º de salida que tendrá el próximo viaje (lo asigna el sistema al registrar)
            'nextNumber' => current_tenant_id_or_null() ? (int) TripLog::max('trip_number') + 1 : null,
            'weeks'     => (new \App\Modules\Attendance\Services\CalendarFilters(current_tenant_id_or_null(), substr($from, 0, 7), from: $from, to: $to))->weeks(),
            'workDays'  => (int) $settings->trip_work_days,
            'restDays'  => (int) $settings->trip_rest_days,
            'branches'  => Branch::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'from'      => $from,
            'to'        => $to,
            'driverId'  => $data['driver_id'] ?? null,
            'today'     => $now->toDateString(),
            'hasTenant' => (bool) current_tenant_id_or_null(),
            // Todas las hojas firmadas cuyo periodo se cruza con el filtrado, agrupadas por su periodo (semana) en orden
            'sheets'    => current_tenant_id_or_null() ? $this->sheets($request)->with('uploader:id,first_name,last_name')
                ->where('period_from', '<=', $to)->where('period_to', '>=', $from)
                ->orderBy('period_from')->orderBy('period_to')->latest()->get()
                ->groupBy(fn ($s) => $s->period_from->toDateString() . '|' . $s->period_to->toDateString()) : collect(),
        ]);
    }

    /** Hojas de conducción en Excel (ida y regreso) de la semana o del periodo elegido, para imprimir y que firmen */
    public function export(Request $request, TripSheetExporter $exporter): BinaryFileResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa en el selector superior.');
        [$data, $from, $to] = $this->filters($request);

        $trips = $this->tripsQuery($request, $data, $from, $to)->orderBy('trip_date')->orderBy('departure_time')->get();
        $path  = tempnam(sys_get_temp_dir(), 'trp');
        $exporter->xlsx($trips, current_tenant(), $from, $to, $path);

        return response()->download($path, "bitacora-viajes-{$from}_{$to}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** @return array{0: array, 1: string, 2: string} filtros validados y el periodo (por defecto el mes actual) */
    private function filters(Request $request): array
    {
        $now  = CarbonImmutable::now(tenant_timezone());
        $data = $request->validate([
            'from'      => ['nullable', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'driver_id' => ['nullable', 'integer'],
        ]);

        return [$data, $data['from'] ?? $now->startOfMonth()->toDateString(), $data['to'] ?? $now->endOfMonth()->toDateString()];
    }

    /** Viajes del periodo de las personas que quien consulta puede ver (misma lista en pantalla y en Excel) */
    private function tripsQuery(Request $request, array $data, string $from, string $to)
    {
        return TripLog::with(['driver:id,first_name,last_name', 'coDriver:id,first_name,last_name', 'helper:id,first_name,last_name',
            'signer:id,first_name,last_name', 'branch:id,name'])
            ->whereBetween('trip_date', [$from, $to])
            ->ofPeople($this->people($request)->pluck('id')->all())
            ->when($data['driver_id'] ?? null, fn ($q, $id) => $q->ofPeople([(int) $id]));
    }

    /** Sube la hoja firmada por los choferes (PDF o foto) como evidencia del periodo; queda en el disco privado */
    public function storeSheet(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa en el selector superior.');
        $data = $request->validate([
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'sheet'       => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'notes'       => ['nullable', 'string', 'max:300'],
        ], [
            'sheet.required' => 'Adjunta la hoja firmada (PDF o foto).',
            'sheet.mimes'    => 'La hoja firmada debe ser PDF, JPG, PNG o WEBP.',
            'sheet.max'      => 'La hoja firmada no puede pesar más de 10 MB.',
        ]);

        $file  = $request->file('sheet');
        $sheet = TripSheet::create([
            'tenant_id'     => current_tenant_id_or_null(),
            'period_from'   => $data['period_from'],
            'period_to'     => $data['period_to'],
            'file_path'     => $file->store('trip-sheets/' . current_tenant_id_or_null(), 'private'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime_type'     => $file->getMimeType(),
            'size_bytes'    => $file->getSize(),
            'notes'         => $data['notes'] ?? null,
            'uploaded_by'   => $request->user()->id,
        ]);
        $this->audit->log('attendance.trip_sheet_uploaded', $sheet, $sheet->only(['period_from', 'period_to', 'original_name']));

        return back()->with('success', 'Hoja firmada subida como evidencia.');
    }

    public function downloadSheet(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $sheet = $this->sheets($request)->findOrFail($id);

        return Storage::disk('private')->download($sheet->file_path, $sheet->original_name, ['Content-Type' => $sheet->mime_type]);
    }

    /** Quita la hoja de la lista; el archivo se conserva (borrado lógico) por si hay que revisarla */
    public function destroySheet(Request $request, int $id): RedirectResponse
    {
        $sheet = $this->sheets($request)->findOrFail($id);
        $this->audit->log('attendance.trip_sheet_deleted', $sheet, $sheet->only(['period_from', 'period_to', 'original_name']));
        $sheet->delete();

        return back()->with('success', 'Hoja firmada quitada de la lista.');
    }

    /** Hojas firmadas que ve quien consulta: con «ver todo» las de la empresa; si no, las que subió */
    private function sheets(Request $request)
    {
        return TripSheet::query()->when(! $request->user()->hasPermission('attendance.view_all'),
            fn ($q) => $q->where('uploaded_by', $request->user()->id));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(current_tenant_id_or_null(), 422, 'Elige una empresa en el selector superior.');
        $data = $this->validated($request);

        // En una transacción: el N.º de salida (el siguiente de la empresa) se reserva con bloqueo
        $trip = \Illuminate\Support\Facades\DB::transaction(fn () => TripLog::create($data
            + ['tenant_id' => current_tenant_id_or_null(), 'created_by' => $request->user()->id] + $this->signature($request, $data)));
        $this->audit->log('attendance.trip_created', $trip, $trip->only(['trip_number', 'trip_date', 'route', ...TripLog::CREW, 'vehicle_plate', 'departure_time']));
        if ($trip->hasReturned()) {
            $this->audit->log('attendance.trip_returned', $trip, $trip->only(['return_date', 'return_time', 'return_route']));
        }

        return back()->with('success', "Viaje N.º {$trip->trip_number} registrado" . ($trip->hasReturned() ? ' con su regreso firmado.' : '. Completa la llegada y el regreso cuando ocurran.'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $trip = $this->findVisible($request, $id);
        $data = $this->validated($request, $trip);
        $returning = ! $trip->hasReturned() && ! empty($data['return_time']);

        $this->audit->updateWithAudit('attendance.trip_updated', $trip, $data + ($returning ? $this->signature($request, $data) : []));
        if ($returning) {
            $this->audit->log('attendance.trip_returned', $trip, $trip->only(['return_date', 'return_time', 'return_route']));
        }

        return back()->with('success', $returning ? "Regreso del viaje N.º {$trip->trip_number} registrado y firmado." : "Viaje N.º {$trip->trip_number} actualizado.");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $trip = $this->findVisible($request, $id);
        $this->audit->log('attendance.trip_deleted', $trip, $trip->only(['trip_date', 'route', 'driver_id', 'vehicle_plate']));
        $trip->delete();

        return back()->with('success', 'Viaje eliminado de la bitácora.');
    }

    /**
     * IDA: salida obligatoria; su llegada, la salida y la llegada del REGRESO se completan cuando ocurren (fecha y hora
     * juntas). Las tres personas son distintas y los momentos van en orden: salida ida ≤ llegada ida ≤ salida regreso ≤ llegada regreso.
     */
    private function validated(Request $request, ?TripLog $trip = null): array
    {
        $ids = $this->people($request)->pluck('id')->all();
        $pair = fn (string $date, string $time) => [
            $date => ['nullable', "required_with:{$time}", 'date_format:Y-m-d'],
            $time => ['nullable', "required_with:{$date}", 'date_format:H:i'],
        ];

        $data = $request->validate([
            'trip_date'      => ['required', 'date_format:Y-m-d'],
            'route'          => ['required', 'string', 'max:150'],
            'driver_id'      => ['required', 'integer', Rule::in($ids)],
            'co_driver_id'   => ['nullable', 'integer', 'different:driver_id', Rule::in($ids)],
            'helper_id'      => ['nullable', 'integer', 'different:driver_id', 'different:co_driver_id', Rule::in($ids)],
            'vehicle_plate'  => ['required', 'string', 'max:15', 'regex:/^[A-Za-z0-9-]+$/'],
            'departure_time' => ['required', 'date_format:H:i'],
            'branch_id'      => ['nullable', 'integer', Rule::in(Branch::visibleTo($request->user())->pluck('id')->all())],
            ...$pair('arrival_date', 'arrival_time'),
            ...$pair('return_departure_date', 'return_departure_time'),
            ...$pair('return_date', 'return_time'),
            'return_route'   => ['nullable', 'string', 'max:150'],
            'notes'          => ['nullable', 'string', 'max:300'],
        ], [
            'driver_id.in'                   => 'Elige un conductor con cargo de «Control por viajes» de tu equipo.',
            'co_driver_id.in'                => 'Elige un conductor con cargo de «Control por viajes» de tu equipo.',
            'helper_id.in'                   => 'Elige un auxiliar con cargo de «Control por viajes» de tu equipo.',
            'co_driver_id.different'         => 'El conductor 2 no puede ser el mismo conductor 1.',
            'helper_id.different'            => 'El auxiliar de carga no puede ser uno de los conductores.',
            'vehicle_plate.regex'            => 'La placa solo lleva letras, números y guiones.',
            'arrival_date.required_with'     => 'Indica la fecha de llegada de la ida.',
            'arrival_time.required_with'     => 'Indica la hora de llegada de la ida.',
            'return_departure_date.required_with' => 'Indica la fecha de salida del regreso.',
            'return_departure_time.required_with' => 'Indica la hora de salida del regreso.',
            'return_date.required_with'      => 'Indica la fecha de llegada del regreso.',
            'return_time.required_with'      => 'Indica la hora de llegada del regreso.',
        ]);
        $data['vehicle_plate'] = strtoupper($data['vehicle_plate']);
        foreach (['co_driver_id', 'helper_id', 'arrival_date', 'arrival_time', 'return_departure_date', 'return_departure_time', 'return_date', 'return_time'] as $optional) {
            $data[$optional] = ($data[$optional] ?? null) ?: null;
        }

        // Orden de los momentos del viaje
        $steps = [
            'departure_time'        => [TripLog::moment($data['trip_date'], $data['departure_time']), 'la salida de la ida'],
            'arrival_time'          => [TripLog::moment($data['arrival_date'], $data['arrival_time']), 'la llegada de la ida'],
            'return_departure_time' => [TripLog::moment($data['return_departure_date'], $data['return_departure_time']), 'la salida del regreso'],
            'return_time'           => [TripLog::moment($data['return_date'], $data['return_time']), 'la llegada del regreso'],
        ];
        if ($data['return_time'] && ! $data['return_departure_time']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['return_departure_time' => 'Registra la salida del regreso antes de su llegada.']);
        }
        $previous = null;
        foreach ($steps as $field => [$at, $label]) {
            if (! $at) {
                continue;
            }
            if ($previous && $at->lt($previous[0])) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => ucfirst($label) . " no puede ser antes de {$previous[1]}."]);
            }
            $previous = [$at, $label];
        }

        return $data;
    }

    /** Firma de la vuelta: quién la registra y cuándo (solo si se registró la vuelta) */
    private function signature(Request $request, array $data): array
    {
        return empty($data['return_time']) ? [] : ['return_signed_by' => $request->user()->id, 'return_signed_at' => now()];
    }

    private function findVisible(Request $request, int $id): TripLog
    {
        return TripLog::ofPeople($this->people($request)->pluck('id')->all())->findOrFail($id);
    }

    private ?\Illuminate\Support\Collection $people = null;

    /** Choferes y ayudantes activos (cargo con «Control por viajes») que quien registra puede ver; una consulta por petición */
    private function people(Request $request): \Illuminate\Support\Collection
    {
        if (! current_tenant_id_or_null()) {
            return collect();
        }

        return $this->people ??= User::where('status', 'active')->tripControlled()
            ->when(! $request->user()->hasPermission('attendance.view_all'), fn ($q) => $q->visibleTo($request->user()))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code', 'position']);
    }
}
