<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label')->nullable();
            $table->string('street');
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip', 30)->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['vendor_id', 'type']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX vendor_addresses_one_default_per_type '
            .'ON vendor_addresses (vendor_id, type) WHERE is_default = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_addresses');
    }
};
