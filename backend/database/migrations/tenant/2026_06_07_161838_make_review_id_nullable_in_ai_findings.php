<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fci_ai_findings', function (Blueprint $table) {
            // review_id is a uuid with a foreign key; keep the type, only relax nullability.
            $table->uuid('review_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fci_ai_findings', function (Blueprint $table) {
            $table->uuid('review_id')->nullable(false)->change();
        });
    }
};
