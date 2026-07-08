<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;

/**
 * Seeds the starter document templates (Invoice, Quotation, PO, Receipt) for
 * the current tenant. Safe to run repeatedly — it skips document types that
 * already have a system-default template.
 */
class SeedDefaultTemplatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(DocumentTemplateServiceContract $service): void
    {
        $service->seedDefaults();
    }
}
