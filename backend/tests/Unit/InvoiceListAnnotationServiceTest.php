<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceListAnnotationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TestCase;

class InvoiceListAnnotationServiceTest extends TestCase
{
    public function test_pending_invoice_is_annotated_for_reviewer_and_submitter(): void
    {
        $reviewer = new User;
        $reviewer->id = 8;
        $submitter = new User;
        $submitter->id = 7;
        $pendingForReviewer = new Invoice;
        $pendingForReviewer->approval_status = Invoice::APPROVAL_PENDING;
        $pendingForReviewer->setRelation('approvalRequest', (object) ['requested_by' => 7]);
        $pendingForSubmitter = new Invoice;
        $pendingForSubmitter->approval_status = Invoice::APPROVAL_PENDING;
        $pendingForSubmitter->setRelation('approvalRequest', (object) ['requested_by' => 7]);
        $paginator = new LengthAwarePaginator([$pendingForReviewer, $pendingForSubmitter], 2, 10);

        $approvals = Mockery::mock(InvoiceApprovalService::class);
        $approvals->shouldReceive('resolveReviewers')->twice()->with(null)->andReturn(collect([$reviewer]));
        $service = new InvoiceListAnnotationService($approvals);

        $service->annotateApprovalView($paginator, $reviewer);
        $service->annotateApprovalView(new LengthAwarePaginator([$pendingForSubmitter], 1, 10), $submitter);

        $this->assertSame('to_approve', $pendingForReviewer->getAttribute('approval_view'));
        $this->assertSame('submitted', $pendingForSubmitter->getAttribute('approval_view'));
    }
}
