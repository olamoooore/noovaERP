<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatter_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable()->index();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'chatter_msg_subject_idx');
        });

        Schema::create('chatter_activities', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('assigned_to_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->string('activity_type', 30);
            $table->string('summary');
            $table->text('note')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->string('status', 20)->default('planned');
            $table->dateTime('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'status'], 'chatter_act_subject_idx');
            $table->index(['assigned_to_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('chatter_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_id')->nullable()->index();
            $table->string('filename');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'chatter_att_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatter_attachments');
        Schema::dropIfExists('chatter_activities');
        Schema::dropIfExists('chatter_messages');
    }
};
