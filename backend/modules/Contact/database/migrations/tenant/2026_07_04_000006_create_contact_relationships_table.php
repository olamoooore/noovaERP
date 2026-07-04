<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index(); // the contact
            $table->uuid('related_contact_id')->index(); // the related contact
            $table->string('relationship_type', 50); // pic, parent_company, subsidiary, emergency_contact, manager, spouse, department_head
            $table->string('relationship_label')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'related_contact_id', 'relationship_type'], 'contact_relationship_unique');
            $table->index(['tenant_id', 'related_contact_id']);
            $table->index(['tenant_id', 'relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_relationships');
    }
};
