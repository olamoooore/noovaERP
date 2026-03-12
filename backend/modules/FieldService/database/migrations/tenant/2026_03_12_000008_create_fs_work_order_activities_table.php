<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_work_order_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('type', [
                'note', 'status_change', 'assignment', 'file', 'email', 'whatsapp', 'system'
            ])->default('note');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('work_order_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_work_order_activities');
    }
};
