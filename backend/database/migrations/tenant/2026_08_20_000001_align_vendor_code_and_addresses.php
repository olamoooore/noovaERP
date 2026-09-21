<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('id');
            $table->text('billing_address')->nullable()->after('address');
            $table->text('shipping_address')->nullable()->after('billing_address');
        });

        // Preserve the legacy address as both defaults for existing vendors.
        DB::table('vendors')
            ->whereNotNull('address')
            ->update([
                'billing_address' => DB::raw('address'),
                'shipping_address' => DB::raw('address'),
            ]);

        DB::statement(
            'CREATE UNIQUE INDEX vendors_code_unique ON vendors (code) '
            .'WHERE code IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // Schema-qualified: unqualified DROP INDEX can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".vendors_code_unique");

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['code', 'billing_address', 'shipping_address']);
        });
    }
};
