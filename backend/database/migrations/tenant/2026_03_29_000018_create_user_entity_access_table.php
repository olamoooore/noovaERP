<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_entity_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('legal_entity_id');
            $table->string('role', 30)->default('viewer'); // owner, admin, editor, viewer
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'legal_entity_id']);
            $table->foreign('legal_entity_id')
                ->references('id')->on('legal_entities')
                ->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_entity_access');
    }
};
