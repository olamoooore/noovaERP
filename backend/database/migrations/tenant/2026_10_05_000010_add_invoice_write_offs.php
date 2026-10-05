<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #879 (SA6): bad-debt write-off — the invoice written-off amount
 * (settles like cash), the `written_off` status, and an auditable per-event
 * record (amount, reason, expense account, journal entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'written_off_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('written_off_amount', 15, 2)->default(0);
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'status')) {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
            DB::statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_status_check '.
                "CHECK (status IN ('draft', 'sent', 'posted', 'partial', 'paid', 'overdue', 'cancelled', 'written_off'))"
            );
        }

        if (! Schema::hasTable('invoice_write_offs')
            && Schema::hasTable('invoices')
            && Schema::hasTable('chart_of_accounts')) {
            Schema::create('invoice_write_offs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->decimal('recovered_amount', 15, 2)->default(0);
                $table->string('reason');
                $table->foreignId('account_id')->constrained('chart_of_accounts');
                $table->unsignedBigInteger('journal_entry_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['invoice_id', 'id'], 'invoice_write_offs_invoice_fifo_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_write_offs');

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'written_off_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('written_off_amount');
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'status')) {
            DB::statement("UPDATE invoices SET status = 'posted' WHERE status = 'written_off'");
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
            DB::statement(
                'ALTER TABLE invoices ADD CONSTRAINT invoices_status_check '.
                "CHECK (status IN ('draft', 'sent', 'posted', 'partial', 'paid', 'overdue', 'cancelled'))"
            );
        }
    }
};
