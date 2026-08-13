<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: relationship-scoped persistent roles on contact_roles.
 *
 * Persistent relationship roles (BILLING_CONTACT, DELIVERY_CONTACT, ...) are
 * scoped to the organisation-person association (CT-VEN-005). relationship_id
 * references contact_relationships.id; contact-level module roles (customer,
 * vendor, ...) keep relationship_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_roles', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_roles', 'relationship_id')) {
                $table->uuid('relationship_id')->nullable()->after('contact_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_roles', function (Blueprint $table) {
            $table->dropColumn('relationship_id');
        });
    }
};
