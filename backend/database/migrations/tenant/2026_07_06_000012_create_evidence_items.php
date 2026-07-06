<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_evidence_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('pack_id')->index();
            $table->string('item_type');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_evidence_items');
    }
};
