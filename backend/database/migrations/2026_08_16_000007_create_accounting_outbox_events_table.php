<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->string('event_type', 100)->index();
            $table->string('event_version', 20)->default('1.0');
            $table->string('aggregate_type', 100)->index();
            $table->string('aggregate_id', 100)->index();
            $table->integer('aggregate_version')->nullable();

            $table->string('source_capability', 50);
            $table->uuid('correlation_id')->nullable()->index();
            $table->uuid('causation_id')->nullable();

            $table->json('event_payload');

            $table->timestamp('published_at')->nullable()->index();
            $table->integer('publish_attempts')->default(0);
            $table->text('last_publish_error')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'published_at']);
            $table->index(['tenant_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_outbox_events');
    }
};
