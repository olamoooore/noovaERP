<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #612 (TP2): multi-entity tenants printed every document with the
 * tenant's company settings as the issuer, because legal_entities carried no
 * letterhead data beyond its name. Each selling entity now carries its own
 * registration number, TIN, SST/GST number, address, logo and bank details so
 * the `company` merge block can be built from the document's entity.
 *
 * Additive and nullable with no backfill — an entity without a letterhead
 * value falls back per field to the tenant's existing company settings.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $columns = [
        'registration_number',
        'tax_id',
        'sst_registration_number',
        'address',
        'logo_url',
        'email',
        'phone',
        'website',
        'bank_details',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('legal_entities')) {
            return;
        }

        Schema::table('legal_entities', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_entities', 'registration_number')) {
                $table->string('registration_number', 100)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'tax_id')) {
                $table->string('tax_id', 100)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'sst_registration_number')) {
                $table->string('sst_registration_number', 100)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'address')) {
                $table->text('address')->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'logo_url')) {
                $table->string('logo_url', 500)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'email')) {
                $table->string('email')->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'phone')) {
                $table->string('phone', 50)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'website')) {
                $table->string('website', 500)->nullable();
            }

            if (! Schema::hasColumn('legal_entities', 'bank_details')) {
                $table->json('bank_details')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('legal_entities')) {
            return;
        }

        Schema::table('legal_entities', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('legal_entities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
