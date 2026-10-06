<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #492 (PAY-UX6): `recorded` (draft) is shared by hand-typed and imported
 * payments, so the status cannot describe where a payment came from. Record
 * the origin separately. Additive and nullable — existing rows stay NULL
 * (origin unknown); no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'source')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 20)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'source')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
