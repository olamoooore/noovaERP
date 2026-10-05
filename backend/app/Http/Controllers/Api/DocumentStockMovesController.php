<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentStockMovesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #690 (SALES-INV / INV-UX4): the read-only "Stock moves" tab for a
 * sales document. Mounted once per document type (invoice, quotation, sales
 * order, delivery order, credit note) with the document's own view permission
 * — the same registration pattern as the #905 DocumentAuditController — so a
 * user who may open the record may read its warehouse trail, and no broader
 * inventory permission is required to see it.
 *
 * There are no write verbs: every route is GET-only and the response is built
 * from the recorded stock_movements (plus their source-document lines for
 * lot/serial detail). Legal-entity scoping is inherited from the route group.
 */
class DocumentStockMovesController extends Controller
{
    public function __construct(
        private DocumentStockMovesService $stockMoves,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subject = (string) $request->route('stockMovesSubject');
        $subjectId = (int) $request->route('subjectId');

        abort_unless(isset(DocumentStockMovesService::SUBJECTS[$subject]), 404);

        return response()->json([
            'data' => $this->stockMoves->forDocument($subject, $subjectId),
        ]);
    }
}
