<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('secondary_name')->nullable()->after('name');
            $table->json('gallery')->nullable()->after('image');

            $table->enum('tax_mode', ['default', 'none', 'custom', 'tax'])->default('default')->after('tax_rate');
            $table->boolean('tax_included')->default(false)->after('tax_mode');
            $table->decimal('tax_rate_override', 5, 2)->nullable()->after('tax_included');
            $table->foreignId('tax_id')->nullable()->after('tax_rate_override')->constrained('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_id');
            $table->dropColumn(['secondary_name', 'gallery', 'tax_mode', 'tax_included', 'tax_rate_override']);
        });
    }
};
