<?php

namespace App\Modules\Finance\Controllers\Api;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Finance\Models\PaymentInstallment;
use App\Modules\Finance\Models\Signatory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Firma APP": la persona designada ve las órdenes de pago programadas y las autoriza desde
 * la app. Misma regla que el panel (PaymentInstallment::authorizeBy).
 */
class PaymentAuthorizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(Signatory::canAuthorize($request->user()), 403, 'Solo la persona designada por la empresa autoriza pagos.');

        $pending = PaymentInstallment::with('requisition:id,code,series,number,detail,currency,supplier_id,purchase_order_number,service_order_number,invoice_number,installments',
            'requisition.supplier:id,name,ruc')
            ->where('status', 'pending')->whereNotNull('scheduled_for')->whereNull('authorized_at')
            ->orderBy('scheduled_for')->limit(200)->get()
            ->map(fn (PaymentInstallment $p) => [
                'id' => $p->id, 'order' => $p->requisition?->orderNumber(), 'requisition' => $p->requisition?->code(),
                'detail' => $p->requisition?->detail, 'supplier' => $p->requisition?->supplier?->only(['name', 'ruc']),
                'invoice' => $p->requisition?->invoice_number, 'installment' => $p->installment, 'of' => $p->requisition?->installments,
                'amount' => round((float) $p->amount - (float) $p->paid_amount, 2), 'currency' => $p->requisition?->currency,
                'scheduled_for' => $p->scheduled_for?->toDateString(), 'due_date' => $p->due_date?->toDateString(),
            ]);

        return response()->json(['success' => true, 'data' => $pending]);
    }

    public function approve(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        $p = PaymentInstallment::with('requisition')->findOrFail($id);
        $p->authorizeBy($request->user());
        $audit->log('installment.authorized', $p->requisition, ['installment' => $p->installment, 'via' => 'app']);

        return response()->json(['success' => true, 'message' => 'Pago autorizado: tesorería ya puede pagarlo.']);
    }
}
