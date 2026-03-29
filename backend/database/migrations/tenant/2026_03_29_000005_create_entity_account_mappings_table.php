<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->foreignId('master_coa_node_id')->constrained('master_coa_nodes')->onDelete('cascade');
            $table->enum('mapping_type', ['one-to-one', 'one-to-many', 'many-to-one'])->default('one-to-one');
            $table->decimal('confidence', 5, 2)->default(0);
            $table->boolean('is_manual_override')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['chart_of_account_id', 'master_coa_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_account_mappings');
    }
};
