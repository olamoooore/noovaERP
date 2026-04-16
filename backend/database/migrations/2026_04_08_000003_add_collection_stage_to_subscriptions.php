<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('collection_stage')->nullable()->after('cancellation_reason'); // reminder|warning|final_notice|suspended|cleared
            $table->timestamp('collection_stage_updated_at')->nullable()->after('collection_stage');
            $table->text('collection_notes')->nullable()->after('collection_stage_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['collection_stage', 'collection_stage_updated_at', 'collection_notes']);
        });
    }
};
