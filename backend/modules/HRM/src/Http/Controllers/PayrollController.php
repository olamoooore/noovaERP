<?php

namespace Modules\HRM\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\HRM\Http\Requests\StorePayrollRunRequest;
use Modules\HRM\Http\Resources\PayrollRunResource;
use Modules\HRM\Models\PayrollRun;
use Modules\HRM\Services\PayrollService;
use Modules\HRM\Services\PayrollJournalService;
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
        $data['reference']  = 'PAY-' . Carbon::parse($data['period_start'])->format('Y-m');
        $data['created_by'] = TenantUserHelper::resolveTenantUserId();

        // If reference already taken (e.g. two runs in same month), append a counter
        $base = $data['reference'];
        $n    = 1;
        while (PayrollRun::where('reference', $data['reference'])->exists()) {
            $data['reference'] = $base . '-' . ++$n;
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

        $payrollRun->update([
            'status'      => 'approved',
            'approved_by' => TenantUserHelper::resolveTenantUserId(),
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

        $payrollRun->update(['status' => 'paid']);

        // Post to the general ledger (DR Salary Expense, CR Payroll Payable)
        $postingResult = $this->journalService->postPayrollRun($payrollRun);

        $run = $payrollRun->fresh();

        $response = (new PayrollRunResource($run))->response();

        if (!$postingResult->success) {
            // Payroll is marked paid but journal posting failed — include warning
            $data = $response->getData(true);
            $data['accounting_warning'] = $postingResult->error;
            return response()->json($data);
        }

        return $response;
    }
}
