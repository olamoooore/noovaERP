<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->boolean('is_prorated')->default(false)->after('notes');
            $table->integer('proration_adjustment_amount')->nullable()->after('is_prorated'); // cents
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn(['is_prorated', 'proration_adjustment_amount']);
        });
    }
};
