<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant user-creation is restricted to the platform team; tenant
     * owners/admins raise a request here and the SuperAdmin console
     * approves (creating the account via the shared member service) or
     * declines it. Central (public) table — requests are cross-tenant.
     */
    public function up(): void
    {
        Schema::create('user_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 50)->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('role', 50);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_creation_requests');
    }
};
