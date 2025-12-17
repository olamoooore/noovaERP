<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant: Journal Entries (Double-Entry Ledger)
     * IMMUTABLE after posting - accounting principle
     * Every entry must balance: Σ Debits = Σ Credits
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique(); // JE-2024-0001
            $table->date('entry_date');
            $table->enum('type', ['manual', 'invoice', 'payment', 'adjustment', 'opening'])->default('manual');
            $table->string('reference')->nullable(); // Invoice #, Payment #, etc.
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('entry_date');
            $table->index('status');
            $table->index('type');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->decimal('debit', 20, 2)->default(0);
            $table->decimal('credit', 20, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('journal_entry_id');
        });

        // Trigger to validate debit = credit (PostgreSQL)
        DB::statement('
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
                    RAISE EXCEPTION \'Journal entry does not balance: Debit % != Credit %\', total_debit, total_credit;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('
            CREATE TRIGGER enforce_journal_balance
            AFTER INSERT OR UPDATE ON journal_entry_lines
            FOR EACH ROW
            EXECUTE FUNCTION check_journal_balance();
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS enforce_journal_balance ON journal_entry_lines');
        DB::statement('DROP FUNCTION IF EXISTS check_journal_balance()');
        
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
