<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_member_display_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->unique();
            $table->string('display_name', 100)->nullable();
            $table->string('favorite_order_label', 200)->nullable();
            $table->json('favorite_order_json')->nullable();
            $table->json('recent_orders_json')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_member_display_profiles');
    }
};
