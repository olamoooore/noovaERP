<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'partial' to bills status enum
        DB::statement("ALTER TABLE bills MODIFY COLUMN status ENUM('draft', 'posted', 'partial', 'paid', 'overdue', 'cancelled') DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bills MODIFY COLUMN status ENUM('draft', 'posted', 'paid', 'overdue', 'cancelled') DEFAULT 'draft'");
    }
};
