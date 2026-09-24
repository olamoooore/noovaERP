<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer portal for delivery orders: issue a public acceptance link so the
 * customer can view/download the DO, sign it on paper, and upload the signed
 * copy (or any supporting evidence) back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'acceptance_token')) {
                $table->uuid('acceptance_token')->nullable()->index();
            }
            if (! Schema::hasColumn('delivery_orders', 'acceptance_expires_at')) {
                $table->timestamp('acceptance_expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_orders', 'acceptance_token')) {
                $table->dropColumn('acceptance_token');
            }
            if (Schema::hasColumn('delivery_orders', 'acceptance_expires_at')) {
                $table->dropColumn('acceptance_expires_at');
            }
        });
    }
};
