<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domain event records — persisted events for audit + replay
        Schema::create('domain_event_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('event_name', 100)->index();
            $table->string('version', 20)->default('1.0.0');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->uuid('correlation_id')->index();
            $table->string('source_module', 50)->index();
            $table->timestamp('occurred_at')->index();
            $table->json('payload');
            $table->boolean('idempotent')->default(true);
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'event_name']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        // Event subscriptions — which modules listen to which events
        Schema::create('domain_event_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module_key', 50)->index();
            $table->json('event_names');
            $table->string('handler_class', 255);
            $table->integer('priority')->default(100);
            $table->string('tenant_filter', 50)->nullable();
            $table->json('payload_filter')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['module_key', 'handler_class']);
            $table->index(['is_active', 'priority']);
        });

        // Dead letters — failed events requiring manual intervention
        Schema::create('domain_event_dead_letters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('event_record_id')->index();
            $table->string('event_name', 100)->index();
            $table->string('event_class', 255)->nullable();
            $table->string('module_key', 50)->index();
            $table->string('handler_class', 255);
            $table->uuid('correlation_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('payload');
            $table->json('error');
            $table->integer('retry_count')->default(0);
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_dead_letters');
        Schema::dropIfExists('domain_event_subscriptions');
        Schema::dropIfExists('domain_event_records');
    }
};
