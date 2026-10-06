<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assigns a legal entity (and its book) to legacy rows whose
 * `legal_entity_id` is still NULL.
 *
 * Why this exists: entity scoping is fail-closed — once a user holds entity
 * access, `accessibleBy()` hides every NULL-entity document. A tenant that
 * predates legal entities therefore "loses" its whole document history the
 * moment its users are granted entity access (delivery orders, sales orders,
 * invoices, … all 404/empty). This command converges that legacy data.
 *
 * Safety:
 *   - dry-run by default when reporting; `--dry-run` never writes
 *   - refuses to guess when a tenant has more than one active entity unless
 *     `--entity` names the target explicitly
 *   - skips uuid-typed columns (a different identifier space) and views
 *   - never touches access grants (user_entity_access) or entity/books config
 */
class BackfillTenantEntitiesCommand extends Command
{
    protected $signature = 'tenants:backfill-entities
        {--tenant= : Tenant id, or a unique name/email match. Omit to process every tenant.}
        {--entity= : Target legal entity id. Required when the tenant has more than one active entity.}
        {--dry-run : Report the rows that would be filled without writing.}';

    protected $description = 'Backfill NULL legal_entity_id (and entity_book_id) rows so entity-scoped lists can see legacy documents';

    /** Config/access tables that must never be backfilled. */
    private const EXCLUDED_TABLES = [
        'user_entity_access',
        'entity_books',
        'legal_entities',
        'users',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenants = $this->resolveTenants();

        if ($tenants === null) {
            return Command::FAILURE;
        }

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched.');

            return Command::FAILURE;
        }

        $this->info('Entity backfill '.($dryRun ? '(dry-run — no writes)' : '(writing)'));
        $this->newLine();

        $failed = 0;

        foreach ($tenants as $tenant) {
            $this->line("Tenant: <options=bold>{$tenant->name}</> ({$tenant->id})");

            $result = $tenant->run(fn () => $this->processTenant($tenant, $dryRun));

            if (! $result) {
                $failed++;
            }

            $this->newLine();
        }

        if ($failed > 0) {
            $this->error("{$failed} tenant(s) could not be processed — see the messages above.");

            return Command::FAILURE;
        }

        $this->info($dryRun ? 'Dry-run complete.' : 'Backfill complete.');

        return Command::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Tenant>|null
     */
    private function resolveTenants()
    {
        $needle = $this->option('tenant');

        if (! $needle) {
            return Tenant::query()->orderBy('name')->get();
        }

        $tenant = Tenant::find($needle);

        if (! $tenant) {
            $matches = Tenant::query()
                ->where('name', 'ilike', "%{$needle}%")
                ->orWhere('email', 'ilike', "%{$needle}%")
                ->get();

            if ($matches->count() > 1) {
                $this->error("Tenant match is ambiguous ({$matches->count()} matches) — pass the tenant id:");
                foreach ($matches as $match) {
                    $this->line("  {$match->id}  {$match->name}");
                }

                return null;
            }

            $tenant = $matches->first();
        }

        if (! $tenant) {
            $this->error("Tenant not found: {$needle}");

            return null;
        }

        return collect([$tenant]);
    }

    /**
     * Runs inside the tenant context. Returns false when the tenant could not
     * be processed (and must not be treated as "nothing to do").
     */
    private function processTenant(Tenant $tenant, bool $dryRun): bool
    {
        $entities = DB::table('legal_entities')
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($entities->isEmpty()) {
            $this->line('  no active legal entities — nothing to backfill.');

            return true;
        }

        $targetEntityId = $this->resolveTargetEntity($entities);
        if ($targetEntityId === null) {
            return false;
        }

        $bookId = $this->resolveBook($targetEntityId);

        $tables = $this->entityTables();
        $rows = [];
        $total = 0;

        foreach ($tables as $table) {
            if ($table->data_type === 'uuid') {
                $rows[] = [$table->table_name, 'skipped (uuid column)', ''];

                continue;
            }

            if (! Schema::hasColumn($table->table_name, 'legal_entity_id')) {
                continue;
            }

            $nullEntities = DB::table($table->table_name)->whereNull('legal_entity_id')->count();

            $hasBook = Schema::hasColumn($table->table_name, 'entity_book_id');
            $nullBooks = $hasBook && $bookId
                ? DB::table($table->table_name)->whereNull('entity_book_id')->count()
                : 0;

            if ($nullEntities === 0 && $nullBooks === 0) {
                continue;
            }

            if (! $dryRun) {
                $update = ['legal_entity_id' => $targetEntityId];
                if ($hasBook && $bookId) {
                    $update['entity_book_id'] = $bookId;
                }

                DB::table($table->table_name)->whereNull('legal_entity_id')->update($update);

                if ($hasBook && $bookId && $nullBooks > 0) {
                    // Rows that already had an entity but no book get the book too.
                    DB::table($table->table_name)
                        ->whereNotNull('legal_entity_id')
                        ->whereNull('entity_book_id')
                        ->update(['entity_book_id' => $bookId]);
                }
            }

            $rows[] = [
                $table->table_name,
                $nullEntities,
                ($hasBook && $nullBooks > 0) ? $nullBooks : '',
            ];
            $total += $nullEntities;
        }

        if ($rows === []) {
            $this->line('  everything already has an entity — nothing to do.');

            return true;
        }

        $this->table(
            ['table', 'rows to fill (entity)', $bookId ? 'rows to fill (book)' : 'book'],
            $rows,
        );

        $this->line(sprintf(
            '  target entity: <options=bold>#%d</>%s · candidate rows: <options=bold>%d</>%s',
            $targetEntityId,
            $bookId ? " (book #{$bookId})" : ' (no book)',
            $total,
            $dryRun ? ' — dry-run, nothing written' : '',
        ));

        return true;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $entities
     */
    private function resolveTargetEntity($entities): ?int
    {
        $requested = $this->option('entity');

        if ($requested !== null && $requested !== '') {
            $entity = $entities->firstWhere('id', (int) $requested);
            if (! $entity) {
                $this->error("  entity #{$requested} is not an active entity of this tenant. Active: ".
                    $entities->map(fn ($e) => "#{$e->id} {$e->name}")->implode(', '));

                return null;
            }

            return (int) $entity->id;
        }

        if ($entities->count() === 1) {
            return (int) $entities->first()->id;
        }

        $this->error('  this tenant has multiple active entities — pass --entity=<id> to choose one: '.
            $entities->map(fn ($e) => "#{$e->id} {$e->name}")->implode(', '));

        return null;
    }

    private function resolveBook(int $entityId): ?int
    {
        if (! Schema::hasTable('entity_books')) {
            return null;
        }

        $bookId = DB::table('entity_books')
            ->where('legal_entity_id', $entityId)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('id');

        return $bookId ? (int) $bookId : null;
    }

    /**
     * Base tables (not views) in the current tenant schema that carry a
     * legal_entity_id column, minus config/access tables.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function entityTables()
    {
        return collect(DB::select("
            select c.table_name, col.data_type
            from information_schema.columns col
            join information_schema.tables c
              on c.table_schema = col.table_schema and c.table_name = col.table_name
            where col.table_schema = current_schema()
              and c.table_type = 'BASE TABLE'
              and col.column_name = 'legal_entity_id'
            order by c.table_name
        "))->reject(fn ($row) => in_array($row->table_name, self::EXCLUDED_TABLES, true));
    }
}
