<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $existingColumns = collect(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'ocr_documents'"
        ))->pluck('column_name')->all();

        Schema::table('ocr_documents', function (Blueprint $table) use ($existingColumns) {
            if (! in_array('promoted_bill_id', $existingColumns, true)) {
                $table->unsignedBigInteger('promoted_bill_id')->nullable()->after('status');
                $table->index('promoted_bill_id');
            }
            if (! in_array('promoted_invoice_id', $existingColumns, true)) {
                $table->unsignedBigInteger('promoted_invoice_id')->nullable()->after('promoted_bill_id');
                $table->index('promoted_invoice_id');
            }
            if (! in_array('journal_entry_id', $existingColumns, true)) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('promoted_invoice_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ocr_documents', function (Blueprint $table) {
            $table->dropColumn(['promoted_bill_id', 'promoted_invoice_id', 'journal_entry_id']);
        });
    }
};
