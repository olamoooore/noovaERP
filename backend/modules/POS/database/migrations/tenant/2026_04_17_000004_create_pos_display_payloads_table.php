<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_display_payloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('payload_version')->default(1);
            $table->string('display_state', 50);
            $table->unsignedBigInteger('focused_line_id')->nullable();
            $table->json('payload_json');
            $table->string('hash', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
            $table->index(['session_id', 'payload_version']);
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_display_payloads');
    }
};
