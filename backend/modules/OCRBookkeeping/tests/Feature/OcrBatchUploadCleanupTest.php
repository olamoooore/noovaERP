<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class OcrBatchUploadCleanupTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            PreventAccessFromCentralDomains::class,
        ]);

        $this->ensureLegacyFeatureTablesExist();

        $tenantId = '00000000-0000-0000-0000-000000000001';

        DB::table('tenants')->updateOrInsert(
            ['id' => $tenantId],
            [
                'name' => 'Test Tenant',
                'email' => 'test-tenant@example.com',
                'data' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->bindTenantContext($tenantId);

        $this->user = User::factory()->create();

        $this->setOcrBookkeepingEntitlement(true);

        Storage::fake('ocr-documents-local');
    }

    protected function tearDown(): void
    {
        OcrDocument::flushEventListeners();
        Model::clearBootedModels();

        parent::tearDown();
    }

    private function ensureLegacyFeatureTablesExist(): void
    {
        if (!Schema::hasTable('features')) {
            Schema::create('features', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('tenant_features')) {
            Schema::create('tenant_features', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feature_id');
                $table->boolean('is_enabled')->default(true);
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function setOcrBookkeepingEntitlement(bool $entitled): void
    {
        $tenantId = $this->boundTestTenant->getTenantKey();

        $catalogType = CatalogItemType::firstOrCreate(
            ['key' => 'module'],
            [
                'name' => 'Module',
                'is_active' => true,
            ]
        );

        $item = CatalogItem::firstOrCreate(
            ['key' => 'ocr-bookkeeping'],
            [
                'name' => 'OCR Bookkeeping',
                'type_id' => $catalogType->id,
                'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]
        );

        TenantEntitlement::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'catalog_item_id' => $item->id,
            ],
            [
                'is_entitled' => $entitled,
                'source' => $entitled
                    ? TenantEntitlement::SOURCE_TIER_INCLUDED
                    : TenantEntitlement::SOURCE_OVERRIDE_EXCLUDE,
                'resolved_at' => now(),
            ]
        );

        app(FeatureGateService::class)->invalidate($tenantId);
    }

    private function fakePdf(string $name = 'orphan-test.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n% Test PDF content\n"
        );
    }

    public function test_batch_upload_removes_stored_file_when_document_creation_fails(): void
    {
        OcrDocument::creating(function () {
            throw new \RuntimeException('Forced document creation failure after storage');
        });

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->post('/api/v1/ocr/documents/batch-upload', [
                'files' => [
                    $this->fakePdf(),
                ],
            ]);

        $response->assertStatus(207);
        $response->assertJsonPath('data.total_failed', 1);

        $this->assertCount(
            0,
            Storage::disk('ocr-documents-local')->allFiles(),
            'Batch upload left an orphaned stored file after document creation failed.'
        );
    }
}