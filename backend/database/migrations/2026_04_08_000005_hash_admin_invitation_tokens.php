<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change token column to store SHA-256 hash (64 hex chars)
        // The old unique index on token stays — hashed values are still unique
        Schema::table('admin_invitations', function (Blueprint $table) {
            // SHA-256 produces 64 hex chars — bump column length to 64
            $table->string('token', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('admin_invitations', function (Blueprint $table) {
            $table->string('token', 64)->change();
        });
    }
};
