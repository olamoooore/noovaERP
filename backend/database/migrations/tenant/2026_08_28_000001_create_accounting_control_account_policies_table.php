<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The central migration (database/migrations/2026_08_16_000006_create_
        // accounting_control_account_policies_table.php) creates this table in
        // the public schema. The tenant schemas also need this table — the
        // TenantTestCase runs all tenant migrations against a fresh tenant
        // schema, so the table must be created here as well. Idempotent.

        $currentSchema = DB::selectOne('SHOW search_path')->search_path ?? 'public';

        // Check if the table already exists in any schema (central may have created it).
        $exists = DB::scalar(
            "SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1",
            ['accounting_control_account_policies']
        );

        if (! $exists) {
            Schema::create('accounting_control_account_policies', function (Blueprint $table) {
                $table->id();
                $table->uuid('tenant_id')->index();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('control_account_type', 50)->index();

                $table->json('allowed_source_capabilities')->nullable();
                $table->boolean('requires_exceptional_authority')->default(false);
                $table->string('exceptional_permission_code', 100)->nullable();
                $table->boolean('requires_workflow_approval')->default(false);
                $table->boolean('reconciliation_consequence_required')->default(true);
                $table->string('blocking_policy', 50)->default('BLOCK');

                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamps();

                $table->index(['tenant_id', 'account_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_control_account_policies');
    }
};
