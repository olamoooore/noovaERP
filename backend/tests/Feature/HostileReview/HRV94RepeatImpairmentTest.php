<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Models\FixedAsset;
use App\Models\ImpairmentRecord;
use App\Models\JournalEntryLine;
use Tests\Feature\HR94FixedAssetImpairmentTest;

/**
 * Hostile review repro (#94): a second impairment of the same asset must post
 * its own loss to the GL. Reuses the engineer's HR94 fixture.
 */
class HRV94RepeatImpairmentTest extends HR94FixedAssetImpairmentTest
{
    public function test_second_impairment_posts_its_own_loss(): void
    {
        $asset = FixedAsset::where('asset_number', \Closure::bind(fn () => $this->assetANumber, $this, HR94FixedAssetImpairmentTest::class)())->firstOrFail();
        $user = \Closure::bind(fn () => $this->scopedUser(), $this, HR94FixedAssetImpairmentTest::class)();

        $this->actingAs($user)->postJson("/api/v1/accounting/assets/{$asset->id}/impair", [
            'impairment_date' => now()->toDateString(), 'recoverable_amount' => 600,
        ])->assertCreated();

        $second = $this->actingAs($user)->postJson("/api/v1/accounting/assets/{$asset->id}/impair", [
            'impairment_date' => now()->toDateString(), 'recoverable_amount' => 400,
        ]);
        $second->assertCreated();

        $asset->refresh();
        $records = ImpairmentRecord::where('fixed_asset_id', $asset->id)->orderBy('id')->get();
        $glCredit = (float) JournalEntryLine::whereIn('journal_entry_id', $records->pluck('journal_entry_id')->unique())->sum('credit');

        fwrite(STDERR, sprintf(
            "\n[HRV94] book_value=%.2f accumulated=%.2f records=%d distinct_je=%d je_ids=%s GL_credit_total=%.2f\n",
            $asset->book_value, $asset->accumulated_depreciation, $records->count(),
            $records->pluck('journal_entry_id')->unique()->count(),
            $records->pluck('journal_entry_id')->implode(','), $glCredit
        ));

        $this->assertSame(600.0, (float) $asset->accumulated_depreciation, 'subledger absorbed both losses');
        $this->assertSame(600.0, round($glCredit, 2), 'GL must carry both losses (400 + 200)');
    }
}
