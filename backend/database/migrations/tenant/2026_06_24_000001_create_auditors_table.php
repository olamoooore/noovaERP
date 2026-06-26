<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditors', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->string('email')->index();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->string('invitation_token', 64)->unique()->nullable();
            $table->enum('status', ['pending', 'active', 'expired', 'revoked', 'declined'])->default('pending');
            $table->timestamp('invited_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditors');
    }
};
