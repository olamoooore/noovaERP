<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_duplicate_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id_1')->index();
            $table->uuid('contact_id_2')->index();
            $table->string('match_type', 50); // exact_name, exact_email, exact_phone, fuzzy_name, fuzzy_all
            $table->decimal('match_score', 5, 2); // 0.00 to 100.00
            $table->json('match_details')->nullable();
            $table->string('status', 50)->default('pending'); // pending, reviewed, merged, dismissed
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_id_1']);
            $table->index(['tenant_id', 'contact_id_2']);
        });

        Schema::create('contact_merge_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('primary_contact_id')->index(); // the surviving contact
            $table->uuid('merged_contact_id')->index(); // the deleted contact
            $table->json('merge_details')->nullable(); // what was merged
            $table->uuid('merged_by')->index();
            $table->timestamp('merged_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'primary_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_merge_history');
        Schema::dropIfExists('contact_duplicate_candidates');
    }
};
