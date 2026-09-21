<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #119 (HIGH): contact_visibility was dead schema — a real table with a
 * real migration but zero read/write paths, an unused hasMany relation, and a
 * module contract that (inaccurately) claimed visibility filtering was
 * enforced. Record-level access control is owned by the platform's
 * EntityAccessService; the module must not maintain a competing vocabulary.
 *
 * This drops the dead table. The model, relation, contract permission and
 * owned-entity entry, and the contract documentation claims were removed in
 * the same change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contact_visibility');
    }

    public function down(): void
    {
        // Intentionally not recreated: the table had no writers/readers, so
        // there is no data or code path a rollback would restore.
    }
};
