<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #123 (hostile re-review 2026-09-23):
 *
 * B1 — gating was a hard-coded tenant UUID list in config/contact.php,
 * bypassing the platform's per-tenant state. This migration moves the
 * decision into the tenant setting `contacts.healthcare_taxonomy` so the
 * live path reads tenant state, and stamps existing tenants from the old
 * config list (one-off config→state migration).
 *
 * B2 — non-healthcare tenants seeded before gating existed still carry the
 * healthcare-only codes. Treatment: codes that are referenced by contact
 * data are kept but demoted to tenant-owned (is_system = false); unreferenced
 * codes are removed.
 *
 * B3 — contact_groups gains is_system so classification codes added through
 * the tenant API are distinguishable from reserved ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_groups') && ! Schema::hasColumn('contact_groups', 'is_system')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->boolean('is_system')->default(true)->after('group_type');
            });
        }

        $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->id : null;
        if ($tenantId === null) {
            // Central/pre-migration context (e.g. test bootstrap): stamping and
            // data treatment are per-tenant and happen when the tenant runs.
            return;
        }

        $isHealthcare = in_array(
            $tenantId,
            (array) config('contact.healthcare_tenant_ids', []),
            true
        );

        // 1. Stamp the tenant setting (authoritative going forward).
        if (Schema::hasTable('settings')) {
            $encoded = json_encode($isHealthcare);
            $existing = DB::table('settings')->where('key', 'contacts.healthcare_taxonomy')->first();
            if ($existing) {
                DB::table('settings')->where('key', 'contacts.healthcare_taxonomy')
                    ->update(['value' => $encoded, 'updated_at' => now()]);
            } else {
                DB::table('settings')->insert([
                    'key' => 'contacts.healthcare_taxonomy',
                    'value' => $encoded,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($isHealthcare) {
            return;
        }

        // 2. Treat healthcare-only codes seeded before gating.
        $healthcareOnly = [
            'functional_category' => ['CLINICAL'],
            'persistent_role' => ['CLINICAL_CONTACT', 'OT_CONTACT'],
            'classification' => ['DOCTOR', 'NURSE'],
        ];

        foreach ($healthcareOnly as $type => $codes) {
            foreach ($codes as $code) {
                if ($type === 'classification') {
                    $group = DB::table('contact_groups')
                        ->where('tenant_id', $tenantId)
                        ->where('group_name', $code)
                        ->first();

                    if (! $group) {
                        continue;
                    }

                    $referenced = DB::table('contact_group_members')
                        ->where('group_id', $group->id)
                        ->exists();

                    if ($referenced) {
                        // Keep it as tenant-owned vocabulary.
                        DB::table('contact_groups')->where('id', $group->id)->update(['is_system' => false]);
                    } else {
                        DB::table('contact_groups')->where('id', $group->id)->delete();
                        DB::table('contact_taxonomy')
                            ->where('tenant_id', $tenantId)
                            ->where('taxonomy_type', $type)
                            ->where('code', $code)
                            ->delete();
                    }

                    continue;
                }

                $row = DB::table('contact_taxonomy')
                    ->where('tenant_id', $tenantId)
                    ->where('taxonomy_type', $type)
                    ->where('code', $code)
                    ->first();

                if (! $row) {
                    continue;
                }

                $referenced = $type === 'persistent_role'
                    && DB::table('contact_roles')->where('role', $code)->exists();

                if ($referenced) {
                    DB::table('contact_taxonomy')->where('id', $row->id)->update(['is_system' => false]);
                } else {
                    DB::table('contact_taxonomy')->where('id', $row->id)->delete();
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_groups') && Schema::hasColumn('contact_groups', 'is_system')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }
    }
};
