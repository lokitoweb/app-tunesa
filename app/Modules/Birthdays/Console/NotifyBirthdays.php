<?php

namespace App\Modules\Birthdays\Console;

use App\Modules\Birthdays\Models\BirthdayGreeting as GreetingLog;
use App\Modules\Birthdays\Notifications\BirthdayGreeting;
use App\Modules\Birthdays\Notifications\ColleagueBirthday;
use App\Modules\Birthdays\Services\BirthdayService;
use App\Modules\Birthdays\Services\BirthdaySettings;
use App\Modules\Core\Models\Scopes\TenantScope;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Audience;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisos de cumpleaños (corre cada hora; cada empresa a su hora local):
 *  - a quien cumple: "¡Feliz cumpleaños, Ana!" (push + bandeja)
 *  - a sus compañeros (de su sucursal o de toda la empresa, según ajustes):
 *    "Hoy es el cumpleaños de Ana Torres"
 *
 * birthday_greetings garantiza un solo aviso por persona y año.
 */
class NotifyBirthdays extends Command
{
    protected $signature = 'birthdays:notify {--tenant= : Solo esta empresa (id)} {--force : Ignora la hora configurada}';

    protected $description = 'Envía los saludos y avisos de cumpleaños del día';

    public function handle(BirthdayService $birthdays): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->cursor();

        foreach ($tenants as $tenant) {
            if (! ModuleRegistry::enabledFor($tenant, 'Birthdays')) {
                continue;
            }
            $settings = BirthdaySettings::for($tenant);
            $now = now($tenant->timezone ?: config('app.timezone'));
            if (! $this->option('force') && $now->hour < (int) $settings['hour']) {
                continue;
            }

            foreach ($birthdays->today($tenant->id, $now) as $celebrant) {
                $this->greet($tenant, $celebrant, $settings, (int) $now->year);
            }
        }

        return self::SUCCESS;
    }

    private function greet(Tenant $tenant, User $celebrant, array $settings, int $year): void
    {
        // Reserva el saludo antes de enviar: otro servidor que corra a la vez no lo repite
        try {
            $log = GreetingLog::withoutGlobalScope(TenantScope::class)->create(['tenant_id' => $tenant->id, 'user_id' => $celebrant->id, 'year' => $year]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $sent = 0;
        $started = false;
        try {
            if ($settings['greet_celebrant']) {
                $celebrant->notify(new BirthdayGreeting($celebrant->first_name, (string) $tenant->company_name));
                $started = true;
            }

            if ($settings['notify_colleagues'] !== 'none') {
                $this->colleagues($tenant, $celebrant, $settings['notify_colleagues'])
                    ->chunkById(300, function ($users) use ($celebrant, &$sent, &$started) {
                        $started = true;
                        Notification::send($users, new ColleagueBirthday($celebrant));
                        $sent += $users->count();
                    }, 'users.id', 'id');
            }
            $log->forceFill(['colleagues_notified' => $sent])->save();
            $this->line("{$tenant->company_name}: {$celebrant->full_name} ({$sent} compañeros avisados)");
        } catch (\Throwable $e) {
            Log::warning('birthday_notify_failed', ['user_id' => $celebrant->id, 'error' => $e->getMessage()]);
            // Si no llegó a salir ningún aviso, se libera para reintentar en la próxima corrida
            $started ? $log->forceFill(['colleagues_notified' => $sent])->save() : $log->delete();
        }
    }

    private function colleagues(Tenant $tenant, User $celebrant, string $scope)
    {
        $query = User::withoutGlobalScope(TenantScope::class)
            ->select('users.*')
            ->where('users.tenant_id', $tenant->id)
            ->where('users.status', 'active')
            ->where('users.role', '!=', 'super_admin')
            ->where('users.id', '!=', $celebrant->id);

        if ($scope === 'branch') {
            $branchIds = Audience::branchIds($celebrant) ?: [0];
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('employee_branch')
                ->whereColumn('employee_branch.user_id', 'users.id')
                ->whereIn('employee_branch.branch_id', $branchIds)
                ->where(fn ($w) => $w->whereNull('employee_branch.assigned_until')->orWhere('employee_branch.assigned_until', '>=', now()->toDateString())));
        }

        return $query;
    }
}
