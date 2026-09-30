<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MRD R5.3/R6.2: make tax_registrations usable for real.
 *
 * The table shipped wired to the orphan tax_profiles and with mandatory
 * legal_entity/tax_profile FKs that nothing could satisfy. This additive
 * migration carries jurisdiction + regime directly on the registration
 * (that IS the registrant fact), and relaxes both FKs to nullable so a
 * tenant-wide SST/GST number with no legal-entity split is representable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_registrations')) {
            return;
        }

        Schema::table('tax_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_registrations', 'jurisdiction')) {
                $table->string('jurisdiction', 2)->nullable()->after('legal_entity_id');
            }

            if (! Schema::hasColumn('tax_registrations', 'regime')) {
                $table->string('regime', 30)->nullable()->after('jurisdiction');
            }

            if (! Schema::hasColumn('tax_registrations', 'notes')) {
                $table->string('notes')->nullable()->after('status');
            }
        });

        Schema::table('tax_registrations', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->change();
            $table->unsignedBigInteger('tax_profile_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only additive change; columns remain (harmless if rolled back).
    }
};
