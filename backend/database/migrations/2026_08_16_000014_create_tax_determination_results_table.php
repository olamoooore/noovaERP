<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_determination_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->integer('result_version')->default(1);
            $table->string('jurisdiction', 50)->nullable();
            $table->string('transaction_type', 50)->nullable();
            $table->date('transaction_date')->nullable();

            $table->json('tax_rule_refs')->nullable();
            $table->json('tax_lines')->nullable();
            $table->string('rounding_method', 30)->nullable();
            $table->string('calculation_basis', 30)->nullable();
            $table->string('result_status', 30)->default('DETERMINED');

            $table->timestamp('determined_at')->nullable();

            $table->string('source_record_type', 100)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();

            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'source_record_type', 'source_record_id']);
            $table->index(['tenant_id', 'result_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_determination_results');
    }
};
