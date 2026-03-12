<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('leader_id')->nullable();
            $table->string('color', 7)->default('#8B5CF6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('leader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_teams');
    }
};
