<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_key', 80);          // e.g. 'accounts_receivable', 'sales_revenue', 'cogs'
            $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->string('document_type', 50)->nullable(); // null = default, else 'invoice','bill','pos_order'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mapping_key', 'document_type']);
            $table->index('mapping_key');
        });

        // Seed common default mappings (accounts will be linked during setup)
        // These map the well-known keys used by PostingEngine
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
