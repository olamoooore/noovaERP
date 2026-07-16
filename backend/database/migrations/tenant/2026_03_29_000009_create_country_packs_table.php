<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('country_packs', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->unique()->comment('ISO 3166-1 alpha-2');
            $table->string('name');
            $table->jsonb('statutory_coa_template')->nullable();
            $table->jsonb('default_tax_rates')->nullable();
            $table->jsonb('mandatory_tax_types')->nullable();
            $table->jsonb('regulatory_report_mappings')->nullable();
            $table->jsonb('e_invoicing_requirements')->nullable();
            $table->jsonb('numbering_conventions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed Singapore
        DB::table('country_packs')->insert([
            'country_code' => 'SG',
            'name' => 'Singapore',
            'statutory_coa_template' => json_encode([
                'standard' => 'SFRS',
                'groups' => [
                    ['code' => '1000', 'name' => 'Assets'],
                    ['code' => '2000', 'name' => 'Liabilities'],
                    ['code' => '3000', 'name' => 'Equity'],
                    ['code' => '4000', 'name' => 'Revenue'],
                    ['code' => '5000', 'name' => 'Cost of Sales'],
                    ['code' => '6000', 'name' => 'Operating Expenses'],
                    ['code' => '7000', 'name' => 'Other Income'],
                    ['code' => '8000', 'name' => 'Other Expenses'],
                    ['code' => '9000', 'name' => 'Tax & Provisions'],
                ],
            ]),
            'default_tax_rates' => json_encode([
                ['type' => 'GST', 'rate' => 9.0, 'effective_from' => '2024-01-01'],
            ]),
            'mandatory_tax_types' => json_encode(['GST']),
            'regulatory_report_mappings' => json_encode([
                'gst_f5' => 'GST Return (F5)',
                'gst_f7' => 'GST Return (F7)',
                'iras_ci' => 'Corporate Income Tax (Form C-S/C)',
            ]),
            'e_invoicing_requirements' => json_encode([
                'required' => false,
                'framework' => 'Peppol',
                'mandate_date' => null,
            ]),
            'numbering_conventions' => json_encode([
                'invoice_prefix' => 'INV-',
                'bill_prefix' => 'BILL-',
                'journal_prefix' => 'JE-',
                'fiscal_year_start' => '01-01',
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed Malaysia
        DB::table('country_packs')->insert([
            'country_code' => 'MY',
            'name' => 'Malaysia',
            'statutory_coa_template' => json_encode([
                'standard' => 'MFRS',
                'groups' => [
                    ['code' => '1000', 'name' => 'Assets'],
                    ['code' => '2000', 'name' => 'Liabilities'],
                    ['code' => '3000', 'name' => 'Equity'],
                    ['code' => '4000', 'name' => 'Revenue'],
                    ['code' => '5000', 'name' => 'Cost of Sales'],
                    ['code' => '6000', 'name' => 'Operating Expenses'],
                    ['code' => '7000', 'name' => 'Other Income'],
                    ['code' => '8000', 'name' => 'Other Expenses'],
                    ['code' => '9000', 'name' => 'Tax & Provisions'],
                ],
            ]),
            'default_tax_rates' => json_encode([
                ['type' => 'SST_SALES', 'rate' => 10.0, 'effective_from' => '2018-09-01'],
                ['type' => 'SST_SERVICE', 'rate' => 8.0, 'effective_from' => '2024-03-01'],
            ]),
            'mandatory_tax_types' => json_encode(['SST_SALES', 'SST_SERVICE']),
            'regulatory_report_mappings' => json_encode([
                'sst_02' => 'SST Return (SST-02)',
                'cp204' => 'Estimated Tax Payable (CP204)',
                'form_e' => 'Employer Return (Form E)',
            ]),
            'e_invoicing_requirements' => json_encode([
                'required' => true,
                'framework' => 'MyInvois',
                'mandate_date' => '2024-08-01',
            ]),
            'numbering_conventions' => json_encode([
                'invoice_prefix' => 'INV-',
                'bill_prefix' => 'BILL-',
                'journal_prefix' => 'JE-',
                'fiscal_year_start' => '01-01',
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('country_packs');
    }
};
