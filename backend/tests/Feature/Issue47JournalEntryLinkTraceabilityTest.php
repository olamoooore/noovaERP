<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Invoice;
use App\Models\JournalEntryLink;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Runtime tests for issue #47 — deleting a source document must NOT delete a
 * valid (non-orphaned) JournalEntryLink just because the parent's own
 * journal_entry_id column is null. A link is cleaned only when its referenced
 * JournalEntry is physically gone (genuinely orphaned).
 *
 * Verifies the identical fix across all four affected models:
 * Invoice, Bill, Payment, BillPayment.
 */
class Issue47JournalEntryLinkTraceabilityTest extends TenantTestCase
{
    protected string $tenantUuid;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantUuid,
            'name' => 'Test Tenant',
            'email' => $this->tenantUuid.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // journal_entries.created_by/posted_by have FKs to users
        $this->userId = (int) \App\Models\User::factory()->create()->id;
    }

    private function makeJournalEntry(bool $alsoRemove = false): int
    {
        $id = (int) DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-47-'.uniqid(),
            'entry_date' => now()->toDateString(),
            'description' => 'JE for #47 traceability',
            'status' => 'posted',
            'created_by' => $this->userId,
            'posted_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($alsoRemove) {
            // Genuinely orphan the link by physically removing the JE row
            DB::table('journal_entries')->where('id', $id)->delete();
        }

        return $id;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     *         label => [model class, source_document_type, number column]
     */
    private function docSpecs(): array
    {
        return [
            'invoice' => [Invoice::class, 'invoice', 'invoice_number'],
            'bill' => [Bill::class, 'bill', 'bill_number'],
            'payment' => [Payment::class, 'payment', 'payment_number'],
            'bill_payment' => [BillPayment::class, 'bill_payment', 'payment_number'],
        ];
    }

    /**
     * Create a document (journal_entry_id NULL) via its factory, passing a
     * unique document number so the models' auto-numbering (which derives
     * from max-sequence and collides within a single test run) is bypassed.
     */
    private function createDoc(string $modelClass, string $numberColumn): object
    {
        $doc = $modelClass::factory()->create([
            $numberColumn => 'X47-'.strtoupper(Str::random(10)),
        ]);
        // Ensure the parent's own pointer column is null (the #47 precondition)
        $doc->forceFill(['journal_entry_id' => null])->saveQuietly();
        $doc->refresh();
        $this->assertNull($doc->journal_entry_id, 'Precondition: parent pointer must be null');

        return $doc;
    }

    /**
     * AC1: valid link (parent pointer null, referenced JE exists) survives
     * parent deletion. Verified identically for all four models.
     */
    public function test_valid_link_survives_deletion_across_all_models(): void
    {
        foreach ($this->docSpecs() as $label => [$modelClass, $docType, $numberCol]) {
            $doc = $this->createDoc($modelClass, $numberCol);
            $jeId = $this->makeJournalEntry();

            $link = JournalEntryLink::create([
                'source_document_type' => $docType,
                'source_document_id' => $doc->id,
                'journal_entry_id' => $jeId,
                'relation_type' => 'post',
            ]);

            $doc->delete();

            $this->assertDatabaseHas('journal_entry_links', [
                'id' => $link->id,
                'source_document_type' => $docType,
                'source_document_id' => $doc->id,
                'journal_entry_id' => $jeId,
            ]);
        }
    }

    /**
     * AC2: a genuinely orphaned link (referenced JE does not exist) IS
     * cleaned up on parent deletion. Verified for all four models.
     */
    public function test_orphaned_link_is_cleaned_across_all_models(): void
    {
        // A journal entry id that provably does not exist in journal_entries.
        $nonexistentJeId = (int) DB::table('journal_entries')->max('id') + 1000;

        foreach ($this->docSpecs() as $label => [$modelClass, $docType, $numberCol]) {
            $doc = $this->createDoc($modelClass, $numberCol);

            $link = JournalEntryLink::create([
                'source_document_type' => $docType,
                'source_document_id' => $doc->id,
                'journal_entry_id' => $nonexistentJeId,
                'relation_type' => 'post',
            ]);

            $doc->delete();

            $this->assertDatabaseMissing('journal_entry_links', [
                'id' => $link->id,
            ]);
        }
    }

    /**
     * AC4: normal deletion of a source document that never had a link is
     * unaffected (no links to reconcile, no error). Uses find()===null so
     * it is correct for both hard- and soft-deleting models.
     */
    public function test_document_without_links_deletes_normally(): void
    {
        foreach ($this->docSpecs() as $label => [$modelClass, $docType, $numberCol]) {
            $doc = $this->createDoc($modelClass, $numberCol);
            $id = $doc->id;

            $doc->delete();

            $this->assertNull($modelClass::find($id), "{$label} should be deleted");
        }
    }
}
