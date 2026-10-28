<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\User;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\FixedCost;
use App\Modules\Finance\Models\Requisition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pago directo de Tesorería (PG-…, FrmReqProg › Registrar): lo usan el pago directo de Tesorería y
 * el envío de una cuota de costo fijo. Entra a la cola como cualquier orden: se programa, se
 * autoriza y lo paga otra persona con voucher.
 *
 * $data: supplier_id, detail, document_type, document_number?, document_date, currency, amount,
 * due_date, payment_method, detraction_reason?, is_advance?, cash_flow_concept?
 */
class DirectPayments
{
    public static function create(Tenant $tenant, User $user, array $data, ?string $invoicePath = null, ?FixedCost $fixedCost = null): Requisition
    {
        $total = round((float) $data['amount'], 2);
        $reason = $data['detraction_reason'] ?? null;
        $percent = $reason ? (int) config("finance.detraction_reasons.{$reason}.1") : null;
        $detraction = $reason ? OrderPricing::detraction($total, $percent) : null;
        $payable = round($total - (float) $detraction, 2);
        if ($payable <= 0) {
            throw ValidationException::withMessages(['amount' => 'La detracción no puede ser igual o mayor que el monto.']);
        }
        $isAdvance = (bool) ($data['is_advance'] ?? false);

        return DB::transaction(function () use ($tenant, $user, $data, $total, $reason, $percent, $detraction, $payable, $invoicePath, $fixedCost, $isAdvance) {
            if ($fixedCost) {
                // Una cuota, un solo pago: dos envíos a la vez no crean dos PG
                $locked = FixedCost::whereKey($fixedCost->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->is_active || $locked->paid_at || Requisition::where('fixed_cost_id', $fixedCost->id)->exists()) {
                    throw ValidationException::withMessages(['fixed_cost' => 'Esta cuota ya se envió a Tesorería, está pagada o anulada. Actualiza la pantalla.']);
                }
            }

            $year = (int) now()->year;
            $series = DocumentSequence::seriesFrom(null);
            [$number, $code] = DocumentSequence::next($tenant, 'PG', $series, $year);
            // Orden de pago sin número: el sistema le da su correlativo OP-… (sisgere: requerimiento_mostrar_nroODP)
            $document = filled($data['document_number'] ?? null)
                ? strtoupper($data['document_number'])
                : DocumentSequence::next($tenant, 'OP', $series, $year)[1];

            $r = Requisition::create([
                'series' => $series, 'number' => $number, 'code' => $code, 'year' => $year, 'is_direct_payment' => true,
                'requested_at' => now(), 'detail' => $data['detail'], 'area' => 'TESORERIA', 'requested_by' => $user->full_name, 'user_id' => $user->id,
                'classification' => 'GASTOS FIJOS', 'priority' => 'NORMAL', 'stage' => 'ordered', 'ordered_at' => now(),
                // Un anticipo va, por defecto, a 'anticipos concedidos a terceros' (como lo clasificaba sisgere)
                'cash_flow_concept' => $data['cash_flow_concept'] ?? ($isAdvance ? config('finance.cash_flow_defaults.advance') : null),
                'reviewed_by' => $user->full_name, 'reviewed_by_id' => $user->id, 'reviewed_at' => now(), 'supplier_id' => $data['supplier_id'],
                'invoice_number' => "{$data['document_type']} {$document}", 'invoice_date' => $data['document_date'], 'invoice_path' => $invoicePath,
                'amount' => $total, 'amount_payable' => $payable, 'currency' => $data['currency'], 'installments' => 1,
                'has_detraction' => (bool) $reason, 'detraction_reason' => $reason, 'detraction_percent' => $percent, 'detraction_amount' => $detraction,
                'payment_terms' => 'CREDITO', 'payment_method' => $data['payment_method'], 'payment_origin' => 'TRANSFERENCIA',
                'fixed_cost_id' => $fixedCost?->id,
            ]);
            $r->paymentInstallments()->create([
                'tenant_id' => $tenant->id, 'installment' => 1, 'due_date' => $data['due_date'], 'amount' => $payable, 'status' => 'pending',
                'payment_method' => $data['payment_method'], 'registered_by' => $user->full_name, 'is_advance' => $isAdvance,
            ]);

            return $r;
        });
    }
}
