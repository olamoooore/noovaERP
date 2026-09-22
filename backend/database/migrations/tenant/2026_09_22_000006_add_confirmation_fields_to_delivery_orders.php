<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #177: delivery orders need a dual confirmation path — physical
 * (printed, signed copy uploaded) vs digital (email/web/WhatsApp, confirmed
 * in-system) — with an audit trail distinguishing the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_orders') || Schema::hasColumn('delivery_orders', 'confirmation_method')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('confirmation_method', 20)->nullable()->after('status');
            $table->string('confirmed_via_channel', 50)->nullable()->after('confirmation_method');
            $table->string('signed_document_path')->nullable()->after('confirmed_via_channel');
            $table->timestamp('signed_document_uploaded_at')->nullable()->after('signed_document_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_orders') || ! Schema::hasColumn('delivery_orders', 'confirmation_method')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn([
                'confirmation_method',
                'confirmed_via_channel',
                'signed_document_path',
                'signed_document_uploaded_at',
            ]);
        });
    }
};
