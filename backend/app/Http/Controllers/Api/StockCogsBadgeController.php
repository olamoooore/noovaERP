<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockCogsReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #696 (INV-D3): the read-only stock & COGS reconciliation badge for a
 * sales document. Mounted once per document type (sales order, delivery
 * order, invoice) with the document's own view permission — the same
 * registration pattern as the #690 stock-moves tab — so whoever may open the
 * record can see whether its warehouse movements and COGS postings agree.
 *
 * GET-only: the payload is computed from recorded stock_movements and
 * journal_entry_links. Nothing is written; the nightly report command owns
 * recording mismatches to the platform exception queue.
 */
class StockCogsBadgeController extends Controller
{
    /** Movement/document subjects this badge knows how to reconcile. */
    public const SUBJECTS = ['sales_order', 'delivery_order', 'invoice'];

    public function __construct(
        private StockCogsReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subject = (string) $request->route('stockCogsSubject');
        $subjectId = (int) $request->route('subjectId');

        abort_unless(in_array($subject, self::SUBJECTS, true), 404);

        return response()->json([
            'data' => $this->reconciliation->badge($subject, $subjectId),
        ]);
    }
}
