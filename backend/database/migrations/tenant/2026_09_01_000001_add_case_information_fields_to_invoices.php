<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('pr_no', 100)->nullable();
            $table->string('contract_no', 100)->nullable();
            $table->uuid('buyer_contact_id')->nullable();
            $table->string('plant_code', 100)->nullable();
            $table->string('company_code', 100)->nullable();

            if (Schema::hasTable('core_contacts')) {
                $table->foreign('buyer_contact_id')->references('id')->on('core_contacts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasTable('core_contacts')) {
                $table->dropForeign(['buyer_contact_id']);
            }
            $table->dropColumn(['pr_no', 'contract_no', 'buyer_contact_id', 'plant_code', 'company_code']);
        });
    }
};
