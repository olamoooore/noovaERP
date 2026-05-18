<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_configs', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_key')->unique()->comment('Unique key for the campaign/event');
            $table->string('campaign_name')->comment('Display name for the campaign');
            $table->integer('max_active_customers')->default(200)->comment('Maximum customers allowed in transaction area');
            $table->integer('slot_ttl_seconds')->default(600)->comment('How long a slot remains active (seconds)');
            $table->integer('max_queue_size')->default(10000)->comment('Maximum queue size before rejection');
            $table->boolean('is_enabled')->default(true)->comment('Whether queue is active');
            $table->boolean('waiting_room_enabled')->default(true)->comment('Whether waiting room is enabled');
            $table->text('waiting_room_message')->nullable()->comment('Custom message shown in waiting room');
            $table->json('settings')->nullable()->comment('Additional queue settings');
            $table->timestamps();
            $table->softDeletes();

            $table->index('campaign_key');
            $table->index('is_enabled');
        });

        Schema::create('queue_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique()->comment('Unique queue token for this session');
            $table->string('campaign_key')->comment('Which campaign this session belongs to');
            $table->unsignedBigInteger('customer_id')->nullable()->comment('Customer ID if authenticated');
            $table->string('customer_email')->nullable()->comment('Customer email for tracking');
            $table->integer('sequence')->comment('Order in which customer joined queue');
            $table->string('status')->default('queued')->comment('queued, active, expired, completed');
            $table->timestamp('joined_at')->comment('When customer joined queue');
            $table->timestamp('activated_at')->nullable()->comment('When customer entered transaction area');
            $table->timestamp('expires_at')->nullable()->comment('When session expires');
            $table->timestamp('completed_at')->nullable()->comment('When customer completed transaction');
            $table->integer('heartbeat_count')->default(0)->comment('Number of heartbeats sent');
            $table->json('metadata')->nullable()->comment('Additional session data');
            $table->timestamps();
            $table->softDeletes();

            $table->index('token');
            $table->index('campaign_key');
            $table->index('status');
            $table->index(['campaign_key', 'status']);
            $table->index(['campaign_key', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_sessions');
        Schema::dropIfExists('queue_configs');
    }
};