<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acct_compliance_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('acct_compliance_submissions', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('acct_compliance_submissions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
