<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acct_compliance_events')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('acct_compliance_events'));
        Schema::table('acct_compliance_events', function (Blueprint $table) use ($columns) {
            if (! isset($columns['previous_status'])) {
                $table->string('previous_status')->nullable();
            }
            if (! isset($columns['actor_type'])) {
                $table->string('actor_type', 30)->nullable();
            }
            if (! isset($columns['created_by'])) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
        });

        // Events are associated through submission_id. Legacy document_id was
        // mandatory even though the current immutable event API does not write it.
        if (Schema::hasColumn('acct_compliance_events', 'document_id')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE acct_compliance_events ALTER COLUMN document_id DROP NOT NULL');
            } elseif (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE acct_compliance_events MODIFY document_id BIGINT UNSIGNED NULL');
            }
        }
    }

    public function down(): void
    {
        // Forward-only audit schema compatibility repair.
    }
};
