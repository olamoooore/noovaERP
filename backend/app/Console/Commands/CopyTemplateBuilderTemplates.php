<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\TemplateBuilder\Models\DocumentTemplate;
use Modules\TemplateBuilder\Models\DocumentTemplateAsset;
use Modules\TemplateBuilder\Models\DocumentTemplateAssignment;
use Modules\TemplateBuilder\Models\DocumentTemplateVersion;
use Modules\TemplateBuilder\Models\TemplateRegistration;

class CopyTemplateBuilderTemplates extends Command
{
    protected $signature = 'templates:copy-between-tenants
                            {source : Source tenant UUID}
                            {targets* : One or more target tenant UUIDs}
                            {--template=* : Template UUID to copy; repeat for multiple templates}
                            {--all : Copy every non-deleted source template}
                            {--preserve-status : Preserve published/draft state instead of importing as draft}
                            {--with-tenant-assignments : Copy only tenant-scoped assignment rules}
                            {--dry-run : Show what would be copied without writing}';

    protected $description = 'Copy Template Builder templates, versions, and asset records between tenants';

    public function handle(): int
    {
        $source = Tenant::find($this->argument('source'));
        if (! $source) {
            $this->error('Source tenant was not found.');

            return self::FAILURE;
        }

        $targetIds = array_values(array_unique($this->argument('targets')));
        if (in_array((string) $source->getTenantKey(), $targetIds, true)) {
            $this->error('The source tenant cannot also be a target tenant.');

            return self::FAILURE;
        }

        $targets = Tenant::query()->whereIn('id', $targetIds)->get()->keyBy('id');
        $missingTargets = array_values(array_diff($targetIds, $targets->keys()->all()));
        if ($missingTargets !== []) {
            $this->error('Target tenant(s) not found: '.implode(', ', $missingTargets));

            return self::FAILURE;
        }

        $templateIds = array_values(array_unique(array_filter($this->option('template'))));
        if (! $this->option('all') && $templateIds === []) {
            $this->error('Use --all or provide at least one --template=<uuid>.');

            return self::FAILURE;
        }

        $exports = $source->run(function () use ($templateIds) {
            $query = DocumentTemplate::query()->with(['versions', 'assets', 'assignments', 'registration']);
            if (! $this->option('all')) {
                $query->whereIn('id', $templateIds);
            }

            return $query->orderBy('name')->get()->map(fn (DocumentTemplate $template) => [
                'template' => $template->attributesToArray(),
                'versions' => $template->versions->map->attributesToArray()->all(),
                'assets' => $template->assets->map->attributesToArray()->all(),
                'assignments' => $template->assignments->map->attributesToArray()->all(),
                'registration' => $template->registration?->attributesToArray(),
            ])->all();
        });

        if (! $this->option('all')) {
            $foundIds = array_column(array_column($exports, 'template'), 'id');
            $missingTemplates = array_values(array_diff($templateIds, $foundIds));
            if ($missingTemplates !== []) {
                $this->error('Source template(s) not found: '.implode(', ', $missingTemplates));

                return self::FAILURE;
            }
        }

        if ($exports === []) {
            $this->warn('No source templates were found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s copy %d template(s) from %s to %d tenant(s).',
            $this->option('dry-run') ? 'Would' : 'Will',
            count($exports),
            $source->getTenantKey(),
            $targets->count(),
        ));

        foreach ($targets as $target) {
            if ($this->option('dry-run')) {
                foreach ($exports as $export) {
                    $this->line("[{$target->id}] {$export['template']['name']}");
                }

                continue;
            }

            $result = $target->run(fn () => $this->copyIntoCurrentTenant(
                $exports,
                (string) $source->getTenantKey(),
                (bool) $this->option('preserve-status'),
                (bool) $this->option('with-tenant-assignments'),
            ));

            $this->info("Target {$target->id}: {$result['created']} created, {$result['skipped']} already copied.");
        }

        if (! $this->option('dry-run')) {
            $this->warn('Asset database records were copied. Verify that every asset URL is accessible to each target tenant.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $exports
     * @return array{created: int, skipped: int}
     */
    private function copyIntoCurrentTenant(array $exports, string $sourceTenantId, bool $preserveStatus, bool $withAssignments): array
    {
        return DB::transaction(function () use ($exports, $sourceTenantId, $preserveStatus, $withAssignments) {
            $created = 0;
            $skipped = 0;

            foreach ($exports as $export) {
                $sourceTemplate = $export['template'];
                $alreadyCopied = DocumentTemplate::query()
                    ->where('metadata->copied_from_tenant_id', $sourceTenantId)
                    ->where('metadata->copied_from_template_id', $sourceTemplate['id'])
                    ->exists();
                if ($alreadyCopied) {
                    $skipped++;

                    continue;
                }

                $registrationId = $this->resolveRegistration($export['registration']);
                $templateId = (string) Str::orderedUuid();
                $metadata = is_array($sourceTemplate['metadata'] ?? null) ? $sourceTemplate['metadata'] : [];
                $metadata['copied_from_tenant_id'] = $sourceTenantId;
                $metadata['copied_from_template_id'] = $sourceTemplate['id'];
                $metadata['copied_at'] = now()->toIso8601String();

                DocumentTemplate::create([
                    ...collect($sourceTemplate)->only([
                        'document_type', 'code', 'name', 'description', 'mode', 'format', 'orientation',
                        'paper_width_mm', 'paper_height_mm', 'margin', 'default_font', 'default_locale',
                        'layout_config', 'html', 'css', 'interaction_type', 'domain_module_code',
                        'data_contract_version',
                    ])->all(),
                    'id' => $templateId,
                    'registration_id' => $registrationId,
                    'status' => $preserveStatus ? $sourceTemplate['status'] : DocumentTemplate::STATUS_DRAFT,
                    'published_version' => $preserveStatus ? (int) $sourceTemplate['published_version'] : 0,
                    'is_system_default' => $preserveStatus && (bool) $sourceTemplate['is_system_default'],
                    'created_by' => null,
                    'updated_by' => null,
                    'metadata' => $metadata,
                    'review_status' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_decision' => null,
                    'review_notes' => null,
                    'superseded_by' => null,
                ]);

                foreach ($export['versions'] as $version) {
                    DocumentTemplateVersion::create([
                        ...collect($version)->only(['version', 'layout_config', 'html', 'css', 'change_note'])->all(),
                        'id' => (string) Str::orderedUuid(),
                        'template_id' => $templateId,
                        'is_published' => $preserveStatus && (bool) $version['is_published'],
                        'created_by' => null,
                    ]);
                }

                foreach ($export['assets'] as $asset) {
                    DocumentTemplateAsset::create([
                        ...collect($asset)->only(['kind', 'name', 'url', 'is_default'])->all(),
                        'id' => (string) Str::orderedUuid(),
                        'template_id' => $templateId,
                    ]);
                }

                if ($withAssignments) {
                    foreach ($export['assignments'] as $assignment) {
                        if (($assignment['scope_type'] ?? 'tenant') !== 'tenant'
                            || filled($assignment['entity_id'] ?? null)
                            || filled($assignment['branch_id'] ?? null)) {
                            continue;
                        }
                        DocumentTemplateAssignment::create([
                            ...collect($assignment)->only([
                                'document_type', 'template_type_code', 'interaction_type', 'scope_type',
                                'scope_value', 'language', 'currency', 'country', 'jurisdiction',
                                'customer_group', 'channel', 'effective_from', 'effective_to',
                                'priority', 'tiebreaker', 'is_active',
                            ])->all(),
                            'id' => (string) Str::orderedUuid(),
                            'template_id' => $templateId,
                        ]);
                    }
                }

                $created++;
            }

            return compact('created', 'skipped');
        });
    }

    /** @param array<string, mixed>|null $sourceRegistration */
    private function resolveRegistration(?array $sourceRegistration): ?string
    {
        if (! $sourceRegistration) {
            return null;
        }

        $existing = TemplateRegistration::query()
            ->where('template_type_code', $sourceRegistration['template_type_code'])
            ->where('interaction_type', $sourceRegistration['interaction_type'])
            ->first();
        if ($existing) {
            return $existing->id;
        }

        return TemplateRegistration::create([
            ...collect($sourceRegistration)->only([
                'domain_module_code', 'template_type_code', 'interaction_type', 'runtime_mode', 'name',
                'description', 'source_or_target_contract', 'required_platform_services',
                'entitlement_codes', 'component_profile', 'channels', 'scope_jurisdiction',
                'security_retention', 'is_system', 'is_active', 'acceptance_reference',
            ])->all(),
            'id' => (string) Str::orderedUuid(),
            'created_by' => null,
            'updated_by' => null,
        ])->id;
    }
}
