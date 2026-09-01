<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\InitializeTenantForPublicAccess;
use Illuminate\Support\Facades\Route;
use Modules\TemplateBuilder\Http\Controllers\DocumentRenderController;
use Modules\TemplateBuilder\Http\Controllers\FormController;
use Modules\TemplateBuilder\Http\Controllers\LifecycleController;
use Modules\TemplateBuilder\Http\Controllers\MergeFieldController;
use Modules\TemplateBuilder\Http\Controllers\PublicAccessController;
use Modules\TemplateBuilder\Http\Controllers\RegistryController;
use Modules\TemplateBuilder\Http\Controllers\SurveyController;
use Modules\TemplateBuilder\Http\Controllers\TemplateController;
use Modules\TemplateBuilder\Http\Controllers\TemplateEditorController;

/*
|--------------------------------------------------------------------------
| Universal Template Platform — tenant API routes (ADR-004)
|--------------------------------------------------------------------------
| Auto-loaded by ModuleManager inside the authenticated api/v1 group
| (auth:sanctum + tenant schema middleware is already applied).
|
| ADR-004 §14 RBAC: each route carries a RequirePermission middleware.
| Public form/survey execution uses signed tokens instead of Sanctum.
*/

$perm = static fn (string $p): string => \App\Http\Middleware\RequirePermission::class.':'.$p;

// ─── Consumer render endpoints (existing detail-page buttons) ──────────────
Route::get('/invoices/{id}/pdf', [DocumentRenderController::class, 'invoicePdf'])
    ->middleware($perm('document.view'));
Route::get('/invoices/{id}/html', [DocumentRenderController::class, 'invoiceHtml'])
    ->middleware($perm('document.view'));
Route::get('/purchase-orders/{id}/pdf', [DocumentRenderController::class, 'purchaseOrderPdf'])
    ->middleware($perm('document.view'));
Route::get('/purchase-orders/{id}/html', [DocumentRenderController::class, 'purchaseOrderHtml'])
    ->middleware($perm('document.view'));
Route::get('/payments/{id}/pdf', [DocumentRenderController::class, 'paymentReceiptPdf'])
    ->middleware($perm('document.view'));
Route::get('/delivery-orders/{id}/pdf', [DocumentRenderController::class, 'deliveryOrderPdf'])
    ->middleware($perm('document.view'));
Route::get('/delivery-orders/pdf-templates', [DocumentRenderController::class, 'deliveryOrderTemplates'])
    ->middleware($perm('document.view'));
Route::get('/invoices/pdf-templates', [DocumentRenderController::class, 'invoiceTemplates'])
    ->middleware($perm('document.view'));
Route::get('/quotations/{id}/pdf', [DocumentRenderController::class, 'quotationPdf'])
    ->middleware($perm('document.view'));
Route::get('/customer-statements/{id}/pdf', [DocumentRenderController::class, 'customerStatementPdf'])
    ->middleware($perm('document.view'));

// ─── Template management (ADR-004 §14) ─────────────────────────────────────
Route::prefix('template-builder')->group(function () use ($perm) {
    Route::get('/starters', [TemplateController::class, 'starters'])->middleware($perm('template.view'));
    Route::get('/active-defaults', [TemplateController::class, 'activeDefaults'])->middleware($perm('template.view'));

    Route::get('/merge-fields/{documentType}', [MergeFieldController::class, 'catalog'])->middleware($perm('template.view'));
    Route::get('/sample-data/{documentType}', [MergeFieldController::class, 'sample'])->middleware($perm('template.view'));

    Route::post('/templates/from-starter/{documentType}', [TemplateController::class, 'createFromStarter'])->middleware($perm('template.create'));

    Route::get('/templates', [TemplateController::class, 'index'])->middleware($perm('template.view'));
    Route::post('/templates', [TemplateController::class, 'store'])->middleware($perm('template.create'));
    Route::get('/templates/{id}', [TemplateController::class, 'show'])->middleware($perm('template.view'));
    Route::put('/templates/{id}', [TemplateController::class, 'update'])->middleware($perm('template.edit_draft'));
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy'])->middleware($perm('template.archive'));
    Route::post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate'])->middleware($perm('template.duplicate'));
    Route::post('/templates/{id}/publish', [TemplateController::class, 'publish'])->middleware($perm('template.publish'));
    Route::post('/templates/{id}/archive', [TemplateController::class, 'archive'])->middleware($perm('template.archive'));
    Route::post('/templates/{id}/default', [TemplateController::class, 'setDefault'])->middleware($perm('template.assign'));
    Route::get('/templates/{id}/sample-pdf', [TemplateController::class, 'samplePdf'])->middleware($perm('template.view'));

    Route::get('/templates/{id}/versions', [TemplateController::class, 'versions'])->middleware($perm('template.view'));
    Route::post('/templates/{id}/versions/{version}/restore', [TemplateController::class, 'restoreVersion'])->middleware($perm('template.edit_draft'));

    Route::post('/templates/{id}/preview', [TemplateEditorController::class, 'preview'])->middleware($perm('template.view'));
    Route::post('/templates/validate', [TemplateEditorController::class, 'validateDefinition'])->middleware($perm('template.validate'));

    // ─── Universal Registry (ADR-004 §7) ───────────────────────────────────
    Route::get('/registry', [RegistryController::class, 'index'])->middleware($perm('template.view'));
    Route::post('/registry/seed', [RegistryController::class, 'seed'])->middleware($perm('template.assign'));
    Route::get('/registry/{typeCode}', [RegistryController::class, 'show'])->middleware($perm('template.view'));
    Route::post('/registry', [RegistryController::class, 'store'])->middleware($perm('template.assign'));
    Route::post('/registry/{typeCode}/activate', [RegistryController::class, 'activate'])->middleware($perm('template.assign'));
    Route::post('/registry/{typeCode}/deactivate', [RegistryController::class, 'deactivate'])->middleware($perm('template.assign'));

    // ─── Editorial lifecycle (ADR-004 §9) ──────────────────────────────────
    Route::post('/templates/{id}/submit-for-review', [LifecycleController::class, 'submitForReview'])->middleware($perm('template.edit_draft'));
    Route::post('/templates/{id}/approve', [LifecycleController::class, 'approve'])->middleware($perm('template.review'));
    Route::post('/templates/{id}/reject', [LifecycleController::class, 'reject'])->middleware($perm('template.review'));
    Route::post('/templates/{id}/restore', [LifecycleController::class, 'restore'])->middleware($perm('template.restore'));
    Route::post('/eligibility', [LifecycleController::class, 'evaluateEligibility'])->middleware($perm('template.view'));

    // ─── Form runtime (ADR-004 §8.2 / §12) ─────────────────────────────────
    Route::post('/forms/{templateId}/submit', [FormController::class, 'submit'])->middleware($perm('form.submit'));
    Route::get('/forms/{templateId}/submissions', [FormController::class, 'index'])->middleware($perm('form.submission.view'));
    Route::get('/forms/{templateId}/submissions/{submissionId}', [FormController::class, 'show'])->middleware($perm('form.submission.view'));

    // ─── Survey runtime (ADR-004 §12 / §13) ────────────────────────────────
    Route::post('/surveys/{templateId}/respond', [SurveyController::class, 'respond'])->middleware($perm('survey.respond'));
    Route::get('/surveys/{templateId}/responses', [SurveyController::class, 'index'])->middleware($perm('survey.analyse'));
    Route::get('/surveys/{templateId}/responses/{responseId}', [SurveyController::class, 'show'])->middleware($perm('survey.analyse'));

    // ─── Public token issuance (issuer side) ───────────────────────────────
    Route::post('/public-tokens', [PublicAccessController::class, 'issueToken'])->middleware($perm('template.assign'));
    Route::post('/public-tokens/revoke', [PublicAccessController::class, 'revokeToken'])->middleware($perm('template.assign'));
});

// ─── Public access (signed token; no Sanctum session) ──────────────────────
// ADR-000 #169 / #176: tenant is resolved by host/subdomain, NOT auth.
Route::prefix('template-builder/public')
    ->middleware(['api', InitializeTenantForPublicAccess::class])
    ->group(function () {
        Route::post('/forms/open', [PublicAccessController::class, 'openForm']);
        Route::post('/forms/submit', [PublicAccessController::class, 'submitPublicForm']);
        Route::post('/surveys/respond', [PublicAccessController::class, 'respondPublicSurvey']);
    });
