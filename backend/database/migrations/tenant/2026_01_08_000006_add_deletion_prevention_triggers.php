<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create function to prevent deletion of posted documents
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_posted_document_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status IN ('posted', 'paid', 'sent') THEN
                    RAISE EXCEPTION 'Cannot delete posted document. Use credit note or reversal instead. Document: % %', 
                        TG_TABLE_NAME, OLD.id;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Trigger for invoices
        DB::statement('
            CREATE TRIGGER prevent_invoice_deletion
            BEFORE DELETE ON invoices
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');

        // Trigger for bills
        DB::statement('
            CREATE TRIGGER prevent_bill_deletion
            BEFORE DELETE ON bills
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');

        // Trigger for payments
        DB::statement('
            CREATE TRIGGER prevent_payment_deletion
            BEFORE DELETE ON payments
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');

        // Trigger for customer credit notes
        DB::statement('
            CREATE TRIGGER prevent_customer_credit_note_deletion
            BEFORE DELETE ON customer_credit_notes
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');

        // Trigger for vendor credit notes
        DB::statement('
            CREATE TRIGGER prevent_vendor_credit_note_deletion
            BEFORE DELETE ON vendor_credit_notes
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');

        // Trigger for journal entries (prevent deletion of posted entries)
        DB::statement('
            CREATE TRIGGER prevent_journal_entry_deletion
            BEFORE DELETE ON journal_entries
            FOR EACH ROW
            EXECUTE FUNCTION prevent_posted_document_deletion();
        ');
    }

    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS prevent_invoice_deletion ON invoices');
        DB::statement('DROP TRIGGER IF EXISTS prevent_bill_deletion ON bills');
        DB::statement('DROP TRIGGER IF EXISTS prevent_payment_deletion ON payments');
        DB::statement('DROP TRIGGER IF EXISTS prevent_customer_credit_note_deletion ON customer_credit_notes');
        DB::statement('DROP TRIGGER IF EXISTS prevent_vendor_credit_note_deletion ON vendor_credit_notes');
        DB::statement('DROP TRIGGER IF EXISTS prevent_journal_entry_deletion ON journal_entries');

        // Drop function
        DB::statement('DROP FUNCTION IF EXISTS prevent_posted_document_deletion()');
    }
};
