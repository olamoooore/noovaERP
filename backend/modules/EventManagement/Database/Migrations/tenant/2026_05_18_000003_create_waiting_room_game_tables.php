<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiting_room_games', function (Blueprint $table) {
            $table->id();
            $table->string('game_key')->unique()->comment('Unique key for this game type');
            $table->string('name')->comment('Display name for the game');
            $table->string('game_type')->comment('scratch_card, spin_wheel, trivia, tap_game, etc');
            $table->text('description')->nullable();
            $table->json('game_config')->comment('Game-specific configuration');
            $table->json('reward_config')->comment('Reward tiers, probabilities, frequencies');
            $table->integer('play_duration_seconds')->default(30)->comment('How long each play session lasts');
            $table->integer('max_plays_per_session')->default(3)->comment('Max plays per waiting session');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('game_key');
            $table->index('is_active');
        });

        Schema::create('waiting_room_game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_session_id')->constrained('queue_sessions')->onDelete('cascade');
            $table->foreignId('waiting_room_game_id')->constrained('waiting_room_games')->onDelete('cascade');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->integer('play_count')->default(0);
            $table->integer('total_score')->default(0);
            $table->json('play_results')->nullable()->comment('Array of play results');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('queue_session_id');
            $table->index('customer_id');
        });

        Schema::create('game_rewards', function (Blueprint $table) {
            $table->id();
            $table->string('reward_type')->comment('coin, voucher, discount, free_shipping, etc');
            $table->string('reward_key')->unique()->comment('Unique reward identifier');
            $table->string('name')->comment('Display name for the reward');
            $table->text('description')->nullable();
            $table->decimal('coin_value', 10, 2)->default(0)->comment('Coin value if coin reward');
            $table->decimal('voucher_value', 10, 2)->default(0)->comment('Voucher monetary value');
            $table->string('voucher_code')->nullable()->comment('Generated voucher code');
            $table->integer('voucher_max_uses')->default(1);
            $table->integer('voucher_uses_remaining')->default(1);
            $table->date('voucher_expires_at')->nullable();
            $table->decimal('min_purchase_amount', 10, 2)->default(0)->comment('Minimum purchase for voucher');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('reward_type');
            $table->index('reward_key');
        });

        Schema::create('customer_game_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('waiting_room_game_session_id')->nullable()->constrained('waiting_room_game_sessions')->onDelete('set null');
            $table->foreignId('game_reward_id')->constrained('game_rewards')->onDelete('cascade');
            $table->string('reward_type')->comment('coin, voucher, discount, etc');
            $table->decimal('coin_amount', 10, 2)->default(0);
            $table->decimal('voucher_amount', 10, 2)->default(0);
            $table->string('voucher_code')->nullable();
            $table->date('redeemed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('pending')->comment('pending, redeemed, expired');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('status');
            $table->index('voucher_code');
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_key')->comment('Links to queue campaign if applicable');
            $table->string('name')->comment('Display name');
            $table->text('description')->nullable();
            $table->string('product_ids')->nullable()->comment('Comma-separated product IDs');
            $table->string('image_url')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable()->comment('Percentage discount');
            $table->decimal('discount_amount', 10, 2)->nullable()->comment('Fixed discount amount');
            $table->integer('max_quantity')->nullable()->comment('Max available for this flash sale');
            $table->integer('quantity_remaining')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_waiting_room')->default(true)->comment('Show in waiting room');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('campaign_key');
            $table->index('is_active');
            $table->index('starts_at');
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sales');
        Schema::dropIfExists('customer_game_rewards');
        Schema::dropIfExists('game_rewards');
        Schema::dropIfExists('waiting_room_game_sessions');
        Schema::dropIfExists('waiting_room_games');
    }
};
