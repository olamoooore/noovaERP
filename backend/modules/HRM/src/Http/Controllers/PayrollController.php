<?php

namespace Modules\HRM\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
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

        $payrollRun->update([
            'status' => 'approved',
            'approved_by' => $currentUserId,
            'approved_at' => now(),
        ]);

        return (new PayrollRunResource($payrollRun->fresh()))->response();
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

    public function markPaid(PayrollRun $payrollRun): JsonResponse
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

        $payrollRun->update(['status' => 'paid']);

        // Post to the general ledger (DR Salary Expense, CR Payroll Payable)
        $postingResult = $this->journalService->postPayrollRun($payrollRun);

        $run = $payrollRun->fresh();

        $response = (new PayrollRunResource($run))->response();

        if (! $postingResult->success) {
            // Payroll is marked paid but journal posting failed — include warning
            $data = $response->getData(true);
            $data['accounting_warning'] = $postingResult->error;

            return response()->json($data);
        }

        return $response;
    }
}
