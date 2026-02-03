<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['received_by']);
            
            // Make received_by nullable
            $table->unsignedBigInteger('received_by')->nullable()->change();
            
            // Re-add the foreign key constraint with nullable
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->unsignedBigInteger('received_by')->nullable(false)->change();
            $table->foreign('received_by')->references('id')->on('users');
        });
    }
};
