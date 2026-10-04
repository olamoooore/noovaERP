<?php

namespace Modules\HRM\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\HRM\Exceptions\PayrollPostingException;
use Modules\HRM\Http\Requests\StorePayrollRunRequest;
use Modules\HRM\Http\Resources\PayrollRunResource;
use Modules\HRM\Models\PayrollRun;
use Modules\HRM\Services\PayrollJournalService;
use Modules\HRM\Services\PayrollService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payrollService,
        private PayrollJournalService $journalService,
    ) {}

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $runs = PayrollRun::withCount('payrolls')
            ->latest()
            ->paginate(20);

        return PayrollRunResource::collection($runs)->response();
    }

    public function store(StorePayrollRunRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Prevent overlapping payroll periods
        $overlap = PayrollRun::overlapping($data['period_start'], $data['period_end'])->exists();
        if ($overlap) {
            return response()->json([
                'message' => 'A payroll run already exists that overlaps with this period.',
            ], 422);
        }

        // Auto-generate a unique reference: PAY-YYYY-MM
        $data['reference'] = 'PAY-'.Carbon::parse($data['period_start'])->format('Y-m');
        $data['created_by'] = TenantUserHelper::resolveTenantUserId();

        // If reference already taken (e.g. two runs in same month), append a counter
        $base = $data['reference'];
        $n = 1;
        while (PayrollRun::where('reference', $data['reference'])->exists()) {
            $data['reference'] = $base.'-'.++$n;
        }

        $run = PayrollRun::create($data);

        return (new PayrollRunResource($run))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PayrollRun $payrollRun): JsonResponse
    {
        $payrollRun->load(['payrolls.employee.user', 'payrolls.items', 'createdBy', 'approvedBy']);

        return (new PayrollRunResource($payrollRun))->response();
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function calculate(PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'draft') {
            return response()->json([
                'message' => "Cannot calculate — payroll run is already '{$payrollRun->status}'.",
            ], 422);
        }

        $payrollRun = $this->payrollService->calculate($payrollRun);
        $payrollRun->load(['payrolls.employee.user', 'payrolls.items']);

        return (new PayrollRunResource($payrollRun))->response();
    }

    public function approve(PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'calculated') {
            return response()->json([
                'message' => "Cannot approve — payroll run must be 'calculated' first (currently '{$payrollRun->status}').",
            ], 422);
        }

        // Pillar 1 P0-14: SoD check — the approving user must not be the
        // same user who created/calculated the payroll run. Without
        // this check, a single authenticated user can create → calculate
        // → approve → mark-paid (triggering a GL journal posting) in
        // one unbroken sequence.
        $currentUserId = TenantUserHelper::resolveTenantUserId();
        $preparerId = $payrollRun->created_by;
        if ($preparerId !== null && (int) $preparerId === (int) $currentUserId) {
            return response()->json([
                'message' => 'Segregation of duties violation: the user who created or calculated this payroll run cannot also approve it. A different user must perform the approval.',
                'reason_code' => 'SOD_SELF_APPROVAL',
            ], 403);
        }

        // #793 (Y4): the accrual posting is part of the approval transition.
        // The status change and the journal entry commit together or not at
        // all — a failed posting must never leave a run looking approved
        // without its accrual in the ledger.
        try {
            $run = DB::transaction(function () use ($payrollRun, $currentUserId) {
                $posting = $this->journalService->postPayrollRun($payrollRun);

                if (! $posting->success) {
                    throw new PayrollPostingException($posting->error ?? 'Unknown posting error.');
                }

                $payrollRun->update([
                    'status' => 'approved',
                    'approved_by' => $currentUserId,
                    'approved_at' => now(),
                ]);

                return $payrollRun->fresh();
            });
        } catch (PayrollPostingException $e) {
            return response()->json([
                'message' => 'Payroll approval was rolled back — the accrual could not be posted: '.$e->getMessage(),
                'reason_code' => 'PAYROLL_POSTING_FAILED',
            ], 422);
        }

        return (new PayrollRunResource($run))->response();
    }

    public function recalculate(PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'calculated') {
            return response()->json([
                'message' => 'Only a calculated payroll run can be recalculated.',
            ], 422);
        }

        $payrollRun = $this->payrollService->recalculate($payrollRun);
        $payrollRun->load(['payrolls.employee.user', 'payrolls.items']);

        return (new PayrollRunResource($payrollRun))->response();
    }

    public function markPaid(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if ($payrollRun->status !== 'approved') {
            return response()->json([
                'message' => "Cannot mark paid — payroll run must be 'approved' first (currently '{$payrollRun->status}').",
            ], 422);
        }

        // Pillar 1 P0-14: SoD check — the user marking paid must not be
        // the same user who created the run or who approved it. The
        // GL posting is the most consequential side-effect in the
        // module; a self-approver could mark their own run as paid
        // and post the journal entry without a second pair of eyes.
        $currentUserId = TenantUserHelper::resolveTenantUserId();
        $involvedIds = array_filter([
            $payrollRun->created_by,
            $payrollRun->approved_by,
        ]);
        if (in_array((int) $currentUserId, array_map('intval', $involvedIds), true)) {
            return response()->json([
                'message' => 'Segregation of duties violation: the user who created or approved this payroll run cannot also mark it as paid. A different user must perform the mark-paid action.',
                'reason_code' => 'SOD_SELF_PAY',
            ], 403);
        }

        $data = $request->validate([
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'payment_date' => ['nullable', 'date'],
        ]);

        // #793 (Y4): "Mark paid" posts the PAYMENT (DR Payroll Payable /
        // CR Bank), not the accrual — the accrual is posted at approval.
        // Posting and status change share one transaction: if the payment
        // cannot be posted the run stays approved and the caller gets an
        // error, never a 200 with an accounting_warning.
        try {
            $run = DB::transaction(function () use ($payrollRun, $data) {
                $posting = $this->journalService->postPayrollPayment(
                    $payrollRun,
                    isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null,
                    $data['payment_date'] ?? null,
                );

                if (! $posting->success) {
                    throw new PayrollPostingException($posting->error ?? 'Unknown posting error.');
                }

                $payrollRun->update(['status' => 'paid']);

                return $payrollRun->fresh();
            });
        } catch (PayrollPostingException $e) {
            return response()->json([
                'message' => 'Mark paid was rolled back — the payment could not be posted: '.$e->getMessage(),
                'reason_code' => 'PAYROLL_POSTING_FAILED',
            ], 422);
        }

        return (new PayrollRunResource($run))->response();
    }
}
