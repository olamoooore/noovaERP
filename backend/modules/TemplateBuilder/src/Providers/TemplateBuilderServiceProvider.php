<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\TemplateBuilder\Contracts\DataSourceContract;
use Modules\TemplateBuilder\Contracts\DocumentTemplateServiceContract;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Renderers\BlockRenderer;
use Modules\TemplateBuilder\Renderers\CodeRenderer;
use Modules\TemplateBuilder\Services\AssignmentResolver;
use Modules\TemplateBuilder\Services\AuditWriter;
use Modules\TemplateBuilder\Services\CommunicationTemplateBridge;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\TemplateBuilder\Services\DocumentTemplateService;
use Modules\TemplateBuilder\Services\EditorialLifecycleService;
use Modules\TemplateBuilder\Services\EligibilityEngine;
use Modules\TemplateBuilder\Services\PdfGenerationService;
use Modules\TemplateBuilder\Services\PublicTokenService;
use Modules\TemplateBuilder\Services\RegistryService;
use Modules\TemplateBuilder\Services\SurveyRuntimeService;
use Modules\TemplateBuilder\Services\TemplateContentSanitiser;

class TemplateBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/templatebuilder.php', 'templatebuilder');

        $this->app->singleton(DataSourceRegistry::class);

        $this->app->singleton(DocumentTemplateServiceContract::class, DocumentTemplateService::class);
        $this->app->alias(DocumentTemplateServiceContract::class, 'template_builder.document_template_service');

        $this->app->singleton(PdfGenerationServiceContract::class, PdfGenerationService::class);
        $this->app->alias(PdfGenerationServiceContract::class, 'template_builder.pdf_service');

        // Renderers keyed by template mode (Strategy resolution).
        $this->app->tag([BlockRenderer::class, CodeRenderer::class], 'template_builder.renderers');
        $this->app->bind('template_builder.renderer.block', BlockRenderer::class);
        $this->app->bind('template_builder.renderer.code', CodeRenderer::class);

        // ADR-004 Universal Template Platform core services.
        $this->app->singleton(AssignmentResolver::class);
        $this->app->singleton(AuditWriter::class);
        $this->app->singleton(RegistryService::class);
        $this->app->singleton(EditorialLifecycleService::class);
        $this->app->singleton(EligibilityEngine::class);

        // Security (ADR-004 §15) + runtimes (ADR-004 §12).
        $this->app->singleton(TemplateContentSanitiser::class);
        $this->app->singleton(PublicTokenService::class);
        $this->app->singleton(FormRuntimeService::class);
        $this->app->singleton(SurveyRuntimeService::class);
        $this->app->singleton(CommunicationTemplateBridge::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'templatebuilder');

        $this->registerDataSources();
    }

    /**
     * Register every DataSourceContract implementation with the registry.
     * Each data source declares the document type it serves.
     */
    private function registerDataSources(): void
    {
        $registry = $this->app->make(DataSourceRegistry::class);

        $sources = [
            \Modules\TemplateBuilder\Services\DataSource\InvoiceDataSource::class,
            \Modules\TemplateBuilder\Services\DataSource\QuotationDataSource::class,
            \Modules\TemplateBuilder\Services\DataSource\PurchaseOrderDataSource::class,
            \Modules\TemplateBuilder\Services\DataSource\PaymentReceiptDataSource::class,
        ];

        foreach ($sources as $sourceClass) {
            /** @var DataSourceContract $source */
            $source = $this->app->make($sourceClass);
            $registry->register($source::documentType(), $sourceClass);
        }
    }
}
