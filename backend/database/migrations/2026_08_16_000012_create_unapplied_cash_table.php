<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unapplied_cash', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->unsignedBigInteger('settlement_id')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');

            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('AVAILABLE')->index();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->uuid('correlation_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unapplied_cash');
    }
};
