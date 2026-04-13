<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add collection tracking to invoices (correct layer)
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('collection_stage')->nullable()->after('proration_adjustment_amount');
            $table->timestamp('collection_stage_updated_at')->nullable()->after('collection_stage');
            $table->text('collection_notes')->nullable()->after('collection_stage_updated_at');
        });

        // Remove from subscriptions (wrong layer)
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['collection_stage', 'collection_stage_updated_at', 'collection_notes']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['collection_stage', 'collection_stage_updated_at', 'collection_notes']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('collection_stage')->nullable();
            $table->timestamp('collection_stage_updated_at')->nullable();
            $table->text('collection_notes')->nullable();
        });
    }
};
