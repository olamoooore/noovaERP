<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entries', 'is_period_close_entry')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->boolean('is_period_close_entry')->default(false)->after('status');
            });
        }
        if (! Schema::hasColumn('journal_entries', 'period_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->foreignId('period_id')->nullable()->after('is_period_close_entry')->index();
            });
        }

        // Extend the type enum to include 'period_close'
        $allowedTypes = ['manual', 'invoice', 'payment', 'adjustment', 'opening', 'pos_order', 'credit_note', 'bill', 'vendor_credit_note', 'ocr_document', 'web_order', 'fx_revaluation', 'depreciation', 'asset_disposal', 'bank_adjustment', 'period_close'];

        // Normalize any existing rows whose type is not in the allowed set so the
        // new CHECK constraint can be applied. This is safe because these rows
        // already violate the intended enum domain.
        DB::table('journal_entries')
            ->whereNotIn('type', $allowedTypes)
            ->update(['type' => 'manual']);

        $existingValues = DB::scalar(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'journal_entries_type_check'"
        );
        if ($existingValues && ! str_contains($existingValues, 'period_close')) {
            DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check');
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (type::text = ANY (ARRAY['manual','invoice','payment','adjustment','opening','pos_order','credit_note','bill','vendor_credit_note','ocr_document','web_order','fx_revaluation','depreciation','asset_disposal','bank_adjustment','period_close']::text[]))");
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['is_period_close_entry', 'period_id']);
        });
    }
};
