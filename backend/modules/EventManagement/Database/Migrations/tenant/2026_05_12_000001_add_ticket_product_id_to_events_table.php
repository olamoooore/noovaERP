<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events') || Schema::hasColumn('events', 'ticket_product_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('ticket_product_id')
                ->nullable()
                ->after('ticket_acquisition_mode')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'ticket_product_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_product_id');
        });
    }
};
