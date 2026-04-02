<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('customers', [
            'company_registration_number' => fn (Blueprint $table) => $table->string('company_registration_number')->nullable()->after('tax_number'),
        ]);

        $this->addColumns('vendors', [
            'company_registration_number' => fn (Blueprint $table) => $table->string('company_registration_number')->nullable()->after('tax_number'),
        ]);

        $this->addColumns('invoices', [
            'customer_reference' => fn (Blueprint $table) => $table->string('customer_reference')->nullable()->after('due_date'),
            'internal_reference' => fn (Blueprint $table) => $table->string('internal_reference')->nullable()->after('customer_reference'),
            'attention_to' => fn (Blueprint $table) => $table->string('attention_to')->nullable()->after('internal_reference'),
            'contact_email' => fn (Blueprint $table) => $table->string('contact_email')->nullable()->after('attention_to'),
            'contact_phone' => fn (Blueprint $table) => $table->string('contact_phone')->nullable()->after('contact_email'),
            'billing_address_snapshot' => fn (Blueprint $table) => $table->text('billing_address_snapshot')->nullable()->after('contact_phone'),
            'shipping_address_snapshot' => fn (Blueprint $table) => $table->text('shipping_address_snapshot')->nullable()->after('billing_address_snapshot'),
            'company_registration_number' => fn (Blueprint $table) => $table->string('company_registration_number')->nullable()->after('shipping_address_snapshot'),
            'tax_registration_number' => fn (Blueprint $table) => $table->string('tax_registration_number')->nullable()->after('company_registration_number'),
        ]);

        $this->addColumns('bills', [
            'vendor_reference' => fn (Blueprint $table) => $table->string('vendor_reference')->nullable()->after('due_date'),
            'internal_reference' => fn (Blueprint $table) => $table->string('internal_reference')->nullable()->after('vendor_reference'),
            'attention_to' => fn (Blueprint $table) => $table->string('attention_to')->nullable()->after('internal_reference'),
            'contact_email' => fn (Blueprint $table) => $table->string('contact_email')->nullable()->after('attention_to'),
            'contact_phone' => fn (Blueprint $table) => $table->string('contact_phone')->nullable()->after('contact_email'),
            'vendor_address_snapshot' => fn (Blueprint $table) => $table->text('vendor_address_snapshot')->nullable()->after('contact_phone'),
            'remittance_address_snapshot' => fn (Blueprint $table) => $table->text('remittance_address_snapshot')->nullable()->after('vendor_address_snapshot'),
            'company_registration_number' => fn (Blueprint $table) => $table->string('company_registration_number')->nullable()->after('remittance_address_snapshot'),
            'tax_registration_number' => fn (Blueprint $table) => $table->string('tax_registration_number')->nullable()->after('company_registration_number'),
        ]);
    }

    public function down(): void
    {
        $this->dropColumns('customers', [
            'company_registration_number',
        ]);

        $this->dropColumns('vendors', [
            'company_registration_number',
        ]);

        $this->dropColumns('invoices', [
            'customer_reference',
            'internal_reference',
            'attention_to',
            'contact_email',
            'contact_phone',
            'billing_address_snapshot',
            'shipping_address_snapshot',
            'company_registration_number',
            'tax_registration_number',
        ]);

        $this->dropColumns('bills', [
            'vendor_reference',
            'internal_reference',
            'attention_to',
            'contact_email',
            'contact_phone',
            'vendor_address_snapshot',
            'remittance_address_snapshot',
            'company_registration_number',
            'tax_registration_number',
        ]);
    }

    /**
     * @param  array<string, callable(Blueprint): void>  $definitions
     */
    private function addColumns(string $tableName, array $definitions): void
    {
        foreach ($definitions as $column => $definition) {
            if (!Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, $definition);
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $tableName, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($tableName, $column)));

        if ($existing === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};