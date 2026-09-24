<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-flight check for the deploy pipeline.
 *
 * `php artisan tenants:migrate` iterates every row in public.tenants and
 * runs migrations inside each schema. A tenant row whose Postgres schema
 * was never created (test debris, a failed provisioning run, or a manual
 * DB restore) makes the vendor command die deep inside the migration
 * runner with the cryptic:
 *
 *   SQLSTATE[3F000]: Invalid schema name: no schema has been selected to create in
 *
 * This command surfaces the same problem BEFORE migrations run, with the
 * exact tenant names/ids and remediation options, so a deploy failure is
 * actionable instead of mysterious.
 *
 * Exit codes:
 *   0 — every tenant row has a matching schema (safe to migrate)
 *   1 — at least one tenant row is missing its schema (deploy should abort)
 *
 * Options:
 *   --fix      Provision an empty schema for each missing tenant so
 *              tenants:migrate can run. Use with care: the tenant will
 *              have the schema structure but no business data.
 *   --prune    Delete tenant rows whose data JSON carries `is_test: true`
 *              and that have NO schema and NO references. Safe cleanup
 *              for test debris leaked into the database.
 */
class TenantsPreflightCommand extends Command
{
    protected $signature = 'tenants:preflight
        {--fix : Create empty schemas for tenants missing them}
        {--prune : Delete is_test tenant rows that have no schema and no references}';

    protected $description = 'Verify every tenant row has a Postgres schema; report orphans before deploying';

    public function handle(): int
    {
        $tenants = Tenant::query()->get(['id', 'name', 'email', 'data']);

        $missingSchemas = [];
        $orphanSchemas = [];

        // Tenants without schemas
        foreach ($tenants as $tenant) {
            $exists = DB::selectOne(
                'SELECT nspname FROM pg_namespace WHERE nspname = ?',
                [$tenant->id]
            );

            if (! $exists) {
                $data = is_array($tenant->data) ? $tenant->data : (json_decode((string) $tenant->data, true) ?: []);
                $missingSchemas[] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'is_test' => (bool) ($data['is_test'] ?? false),
                ];
            }
        }

        // Schemas without tenants
        $schemaRows = DB::select("
            SELECT nspname FROM pg_namespace
            WHERE nspname NOT IN ('pg_catalog', 'information_schema', 'public')
              AND nspname NOT LIKE 'pg_%'
        ");
        $tenantIds = $tenants->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach ($schemaRows as $row) {
            if (! in_array((string) $row->nspname, $tenantIds, true)) {
                $orphanSchemas[] = $row->nspname;
            }
        }

        $this->info(sprintf('Tenants: %d | Schemas: %d', count($tenants), count($schemaRows)));

        if ($orphanSchemas !== []) {
            $this->warn(sprintf('Orphan schemas (no tenant row): %d', count($orphanSchemas)));
            foreach ($orphanSchemas as $schema) {
                $this->line("  - {$schema}");
            }
            $this->line('  (harmless for tenants:migrate, but they inflate backups — drop manually if unused)');
        }

        if ($missingSchemas === []) {
            $this->info('✓ Every tenant row has a Postgres schema. Safe to run tenants:migrate.');

            return self::SUCCESS;
        }

        $this->error(sprintf('Tenant rows WITHOUT a Postgres schema: %d', count($missingSchemas)));
        $this->table(
            ['id', 'name', 'email', 'is_test'],
            array_map(fn ($t) => [
                $t['id'],
                $t['name'],
                $t['email'],
                $t['is_test'] ? 'yes' : 'no',
            ], $missingSchemas)
        );

        // --prune: remove safe test-debris rows
        if ($this->option('prune')) {
            $pruned = 0;
            foreach ($missingSchemas as $t) {
                if (! $t['is_test']) {
                    continue;
                }

                $refs = 0;
                foreach (['public.user_company_access', 'public.domains', 'public.tenant_entitlements'] as $table) {
                    try {
                        $refs += DB::table($table)->where('tenant_id', $t['id'])->count();
                    } catch (\Throwable $e) {
                        // table absent — ignore
                    }
                }

                if ($refs > 0) {
                    $this->warn("  skipped {$t['name']} ({$t['id']}): {$refs} referencing row(s)");
                    continue;
                }

                DB::table('public.tenants')->where('id', $t['id'])->delete();
                $this->info("  pruned test tenant {$t['name']} ({$t['id']})");
                $pruned++;
            }

            if ($pruned > 0 && $this->missingSchemaCount() === 0) {
                $this->info('✓ All schema-less test tenants pruned. Safe to run tenants:migrate.');

                return self::SUCCESS;
            }
        }

        // --fix: provision empty schemas
        if ($this->option('fix')) {
            foreach ($missingSchemas as $t) {
                $schema = str_replace('"', '""', $t['id']);
                DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
                $this->info("  created schema {$t['id']} for {$t['name']}");
            }

            $this->info('✓ Empty schemas created. tenants:migrate will populate them.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('ABORT: tenants:migrate would fail on the rows above.');
        $this->line('Remediation:');
        $this->line('  php artisan tenants:preflight --prune   # drop is_test debris');
        $this->line('  php artisan tenants:preflight --fix     # provision empty schemas');
        $this->line('  or delete/re-provision the affected tenants manually.');

        return self::FAILURE;
    }

    private function missingSchemaCount(): int
    {
        $count = 0;
        foreach (Tenant::query()->pluck('id') as $id) {
            $exists = DB::selectOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [(string) $id]);
            if (! $exists) {
                $count++;
            }
        }

        return $count;
    }
}
