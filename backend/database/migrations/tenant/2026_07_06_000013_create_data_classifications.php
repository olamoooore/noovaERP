<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_data_classifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->enum('classification', ['public', 'internal', 'confidential', 'restricted', 'secret']);
            $table->unsignedBigInteger('classified_by');
            $table->timestamp('classified_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_data_classifications');
    }
};
