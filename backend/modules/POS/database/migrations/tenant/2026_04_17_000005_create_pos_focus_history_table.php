<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_focus_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('line_id', 100)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('focus_reason', 50)->nullable(); // item_added, qty_changed, item_removed
            $table->string('status_label', 100)->nullable();
            $table->string('image_url')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_focus_history');
    }
};
