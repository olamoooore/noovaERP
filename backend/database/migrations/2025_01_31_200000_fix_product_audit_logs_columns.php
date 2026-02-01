<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix product_audit_logs table to have correct column names
     */
    public function up(): void
    {
        if (Schema::hasTable('product_audit_logs')) {
            Schema::table('product_audit_logs', function (Blueprint $table) {
                // Rename 'field' to 'field_name' if 'field' exists and 'field_name' doesn't
                if (Schema::hasColumn('product_audit_logs', 'field') && !Schema::hasColumn('product_audit_logs', 'field_name')) {
                    $table->renameColumn('field', 'field_name');
                }
                
                // If neither exists, add field_name
                if (!Schema::hasColumn('product_audit_logs', 'field') && !Schema::hasColumn('product_audit_logs', 'field_name')) {
                    $table->string('field_name')->nullable()->after('action');
                }

                // Add metadata column if missing
                if (!Schema::hasColumn('product_audit_logs', 'metadata')) {
                    $table->json('metadata')->nullable()->after('new_value');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_audit_logs')) {
            Schema::table('product_audit_logs', function (Blueprint $table) {
                if (Schema::hasColumn('product_audit_logs', 'field_name')) {
                    $table->renameColumn('field_name', 'field');
                }
            });
        }
    }
};
