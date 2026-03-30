<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_account_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legal_entity_id');
            $table->foreignId('account_role_id')->constrained('account_roles')->onDelete('cascade');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'account_role_id', 'chart_of_account_id'], 'ear_entity_role_account_unique');
            $table->index('legal_entity_id');

            if (Schema::hasTable('legal_entities')) {
                $table->foreign('legal_entity_id')->references('id')->on('legal_entities')->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_account_roles');
    }
};
