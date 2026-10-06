<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003B W3 (D3/D4, R1): a person listed under a company owns no address;
 * the organisation-person relationship links the person to one of the
 * organisation's `site` addresses (one site per person per company).
 *
 * Additive and nullable — no existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contact_relationships', 'site_address_id')) {
            return;
        }

        Schema::table('contact_relationships', function (Blueprint $table) {
            $table->uuid('site_address_id')->nullable()->after('department_id');
            $table->index('site_address_id', 'contact_rel_site_address_idx');
            $table->foreign('site_address_id', 'contact_rel_site_address_fk')
                ->references('id')->on('contact_addresses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contact_relationships', 'site_address_id')) {
            return;
        }

        Schema::table('contact_relationships', function (Blueprint $table) {
            $table->dropForeign('contact_rel_site_address_fk');
            $table->dropIndex('contact_rel_site_address_idx');
            $table->dropColumn('site_address_id');
        });
    }
};
