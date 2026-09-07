<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('acct_compliance_submissions')) {
            return;
        }

        if (!Schema::hasColumn('acct_compliance_submissions', 'deleted_at')) {
            Schema::table('acct_compliance_submissions', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('acct_compliance_submissions')) {
            return;
        }

        if (Schema::hasColumn('acct_compliance_submissions', 'deleted_at')) {
            Schema::table('acct_compliance_submissions', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
