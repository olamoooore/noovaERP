<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_lines', 'lot_number')) {
                $table->string('lot_number', 100)->nullable()->after('description');
            }
            if (! Schema::hasColumn('invoice_lines', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('lot_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $columns = array_filter(
                ['lot_number', 'expiry_date'],
                fn (string $column) => Schema::hasColumn('invoice_lines', $column)
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
