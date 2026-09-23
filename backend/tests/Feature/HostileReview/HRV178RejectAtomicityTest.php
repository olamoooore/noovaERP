<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Tests\Feature\HR178RejectionVersioningTest;

/**
 * Hostile review repro (#178/#179): if cloning the replacement fails midway,
 * the rejection must roll back — the original must not be left cancelled and
 * superseded with no complete replacement.
 */
class HRV178RejectAtomicityTest extends HR178RejectionVersioningTest
{
    public function test_failed_clone_leaves_no_half_rejected_delivery_order(): void
    {
        $bind = fn (\Closure $f) => \Closure::bind($f, $this, HR178RejectionVersioningTest::class)();
        $user = $bind(fn () => $this->user);
        $original = $bind(fn () => $this->deliveryOrder());

        // Simulate any failure while cloning lines (DB error, validation, crash).
        DeliveryOrderLine::creating(function () {
            throw new \RuntimeException('simulated failure while cloning lines');
        });

        $res = $this->actingAs($user)->postJson("/api/v1/sales/delivery-orders/{$original->id}/reject", ['reason_text' => 'customer rejected']);

        $original->refresh();
        $replacements = DeliveryOrder::where('original_document_id', $original->id)->get();

        fwrite(STDERR, sprintf("\n[HRV178] http=%d original.status=%s is_active_version=%s replacement_count=%d replacement_lines=%d\n",
            $res->getStatusCode(), $original->status, var_export((bool) $original->is_active_version, true),
            $replacements->count(), $replacements->sum(fn ($r) => $r->lines()->count())));

        $this->assertNotSame('cancelled', $original->status, 'a failed rejection must not leave the original cancelled');
    }
}
