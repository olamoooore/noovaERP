<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_publications', function (Blueprint $table) {
            $table->uuid('website_uuid')->primary();
            $table->uuid('tenant_id');
            $table->string('slug', 100);
            $table->string('publication_status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('source_event_sequence')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'publication_status']);
        });

        Schema::create('hostnames', function (Blueprint $table) {
            $table->id();
            $table->string('hostname', 253)->unique();
            $table->uuid('tenant_id');
            $table->string('purpose', 20);
            $table->uuid('website_uuid')->nullable();
            $table->boolean('primary_requested')->default(false);
            $table->boolean('primary_effective')->default(false);
            $table->string('ownership_status', 20)->default('pending');
            $table->string('routing_status', 20)->default('pending');
            $table->string('tls_status', 20)->default('pending');
            $table->string('serving_status', 24)->default('pending');
            $table->unsignedInteger('challenge_version')->default(1);
            $table->text('challenge_token_ciphertext')->nullable();
            $table->string('challenge_token_hash', 64)->nullable();
            $table->timestamp('challenge_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_ownership_check_at')->nullable();
            $table->timestamp('last_routing_check_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('certificate_provider', 50)->nullable();
            $table->string('certificate_order_id')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();
            $table->uuid('ingress_revision')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('release_requested_at')->nullable();
            $table->timestamp('quarantined_until')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->uuid('previous_owner_tenant_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'website_uuid']);
            $table->index(['purpose', 'serving_status']);
            $table->index(['next_retry_at', 'serving_status']);
        });

        Schema::create('hostname_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hostname_id')->constrained('hostnames')->cascadeOnDelete();
            $table->uuid('tenant_id');
            $table->uuid('website_uuid')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->uuid('operation_id');
            $table->string('event_type', 80);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['hostname_id', 'created_at']);
            $table->index('operation_id');
        });

        Schema::create('hostname_provisioning_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('hostname_id')->constrained('hostnames')->cascadeOnDelete();
            $table->unsignedInteger('expected_lock_version');
            $table->uuid('desired_ingress_revision');
            $table->string('desired_mode', 20);
            $table->string('status', 20)->default('pending');
            $table->string('payload_checksum', 64);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE hostnames ADD CONSTRAINT hostnames_purpose_website_chk CHECK ((purpose = 'website' AND website_uuid IS NOT NULL) OR (purpose = 'workspace' AND website_uuid IS NULL))");
            DB::statement("CREATE UNIQUE INDEX hostnames_one_primary_requested ON hostnames (tenant_id, website_uuid) WHERE purpose = 'website' AND primary_requested = true AND serving_status <> 'released'");
            DB::statement("CREATE UNIQUE INDEX hostnames_one_primary_effective ON hostnames (tenant_id, website_uuid) WHERE purpose = 'website' AND primary_effective = true AND serving_status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hostname_provisioning_operations');
        Schema::dropIfExists('hostname_audit_events');
        Schema::dropIfExists('hostnames');
        Schema::dropIfExists('website_publications');
    }
};
