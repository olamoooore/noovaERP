<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The original trigger validates balance after each row insert, which breaks
        // multi-line journal entries. Recreate it as a DEFERRABLE constraint trigger.
        DB::statement('DROP TRIGGER IF EXISTS enforce_journal_balance ON journal_entry_lines');
        DB::statement('DROP FUNCTION IF EXISTS check_journal_balance()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_journal_balance()
            RETURNS TRIGGER AS $$
            DECLARE
                total_debit NUMERIC(20,2);
                total_credit NUMERIC(20,2);
            BEGIN
                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                INTO total_debit, total_credit
                FROM journal_entry_lines
                WHERE journal_entry_id = NEW.journal_entry_id;

                IF total_debit != total_credit THEN
                    RAISE EXCEPTION 'Journal entry does not balance: Debit % != Credit %', total_debit, total_credit;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER enforce_journal_balance
            AFTER INSERT OR UPDATE ON journal_entry_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION check_journal_balance();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS enforce_journal_balance ON journal_entry_lines');
        DB::statement('DROP FUNCTION IF EXISTS check_journal_balance()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_journal_balance()
            RETURNS TRIGGER AS $$
            DECLARE
                total_debit NUMERIC(20,2);
                total_credit NUMERIC(20,2);
            BEGIN
                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                INTO total_debit, total_credit
                FROM journal_entry_lines
                WHERE journal_entry_id = NEW.journal_entry_id;

                IF total_debit != total_credit THEN
                    RAISE EXCEPTION 'Journal entry does not balance: Debit % != Credit %', total_debit, total_credit;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER enforce_journal_balance
            AFTER INSERT OR UPDATE ON journal_entry_lines
            FOR EACH ROW
            EXECUTE FUNCTION check_journal_balance();
        SQL);
    }
};
