<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Storefront\Services\StorefrontReportService;

/**
 * Tenant-scoped reporting + ops dashboard (FR-ADM-06, P3). Read-only:
 * every figure derives from rows the transactional flows write (orders,
 * transactions, settlements, carts) — there are no report-side counters to
 * drift. CSV export is the same aggregation rendered as text/csv.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly StorefrontReportService $reports,
    ) {}

    public function sales(Request $request): JsonResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->sales($from, $to);

        return $this->respond($request, $report, 'sales');
    }

    public function tax(Request $request): JsonResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->tax($from, $to);

        return $this->respond($request, $report, 'tax');
    }

    public function reconciliation(Request $request): JsonResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->reconciliation($from, $to);

        return $this->respond($request, $report, 'reconciliation');
    }

    public function funnel(Request $request): JsonResponse|Response
    {
        [$from, $to] = $this->range($request);
        $report = $this->reports->funnel($from, $to);

        return $this->respond($request, $report, 'funnel');
    }

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->reports->overview()]);
    }

    /**
     * @return array{0:\Illuminate\Support\Carbon,1:\Illuminate\Support\Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to'])
            ? \Illuminate\Support\Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();
        $from = isset($validated['from'])
            ? \Illuminate\Support\Carbon::parse($validated['from'])->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        if ($from->diffInDays($to) > 366) {
            abort(422, 'Reporting window is capped at 366 days.');
        }

        return [$from, $to];
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function respond(Request $request, array $report, string $kind): JsonResponse|Response
    {
        if ($request->string('format')->toString() !== 'csv') {
            return response()->json(['data' => $report]);
        }

        $csv = $this->reports->toCsv($report, $kind);
        $lines = [implode(',', $csv['headers'])];
        foreach ($csv['rows'] as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="storefront-'.$kind.'-'.date('Ymd').'.csv"',
        ]);
    }
}
