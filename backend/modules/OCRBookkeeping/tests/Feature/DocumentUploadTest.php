<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TenantTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Jobs\ProcessOcrDocument;
use App\Models\User;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Document Upload Tests
 */
class DocumentUploadTest extends TenantTestCase
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

    private function fakeSuccessfulOcrResponse(array $overrides = []): void
    {
        Http::fake([
            config('ocr.service.url') . '/api/bookkeeping/upload' => Http::response([
                'status' => 'success',
                'extracted_data' => array_merge([
                    'vendor_customer_name' => 'Upload Test Vendor',
                    'invoice_date' => '2026-04-16',
                    'invoice_number' => 'OCR-UPLOAD-001',
                    'total_amount' => 125.50,
                    'currency' => 'USD',
                    'line_items' => [
                        [
                            'description' => 'Uploaded Item',
                            'amount' => 125.50,
                            'quantity' => 1,
                        ],
                    ],
                    'tax_amount' => 0,
                ], $overrides),
                'processing_details' => [
                    'ocr_confidence' => 95,
                ],
            ], 200),
        ]);
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF"
        );
    }

    private function uploadDocument(UploadedFile $file)
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->post('/api/v1/ocr/documents/upload', [
                'file' => $file,
            ]);
    }

    private function batchUploadDocuments(array $files)
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->user, 'sanctum')
            ->post('/api/v1/ocr/documents/batch-upload', [
                'files' => $files,
            ]);
    }

    /**
     * Property 1: Upload creates storage and database records
     */
    public function test_upload_creates_storage_and_database_records()
    {
        $this->fakeSuccessfulOcrResponse();

        $response = $this->uploadDocument(UploadedFile::fake()->image('receipt.jpg'));

        $response->assertCreated()
            ->assertJsonPath('message', 'Document uploaded successfully');

        $this->assertEquals(1, OcrDocument::count());

        $document = OcrDocument::firstOrFail();

        $this->assertTrue(Storage::disk('ocr-documents-local')->exists($document->file_path));
        $this->assertEquals('receipt.jpg', $document->file_name);
        $this->assertEquals($this->user->id, $document->created_by);
        $this->assertEquals($document->uuid, $response->json('data.uuid'));
    }

    /**
     * Property 2: Upload triggers OCR processing
     */
    public function test_upload_triggers_ocr_job()
    {
        $this->fakeSuccessfulOcrResponse();

        $response = $this->uploadDocument($this->fakePdf('invoice.pdf'));

        $response->assertCreated();

        $document = OcrDocument::firstOrFail();

        // The controller dispatches OCR processing synchronously, so the document
        // must not remain in its initial PENDING state after upload.
        $this->assertContains($document->status, ['NEEDS_REVIEW', 'EXTRACTION_FAILED']);
        $this->assertNotEquals('PENDING', $document->status);
    }

    /**
     * Property 3: Document identifiers are unique
     */
    public function test_document_identifiers_are_unique()
    {
        // This property can be tested without HTTP — OcrDocument UUIDs are model-generated
        $doc1 = OcrDocument::factory()->create();
        $doc2 = OcrDocument::factory()->create();

        $this->assertNotEquals($doc1->uuid, $doc2->uuid);

        // Verify UUIDs are valid v4
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $doc1->uuid
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $doc2->uuid
        );
    }

    /**
     * Property 4: Invalid file types are rejected
     */
    public function test_invalid_file_types_are_rejected()
    {
        $response = $this->uploadDocument(
            UploadedFile::fake()->create('payload.exe', 100, 'application/octet-stream')
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonValidationErrors(['file']);

        $this->assertEquals(0, OcrDocument::count());
    }

    /**
     * Test: Valid file types are accepted
     */
    public function test_valid_file_types_are_accepted()
    {
        $files = [
            UploadedFile::fake()->image('receipt.jpg'),
            UploadedFile::fake()->image('receipt.png'),
            $this->fakePdf('invoice.pdf'),
        ];

        foreach ($files as $file) {
            $this->fakeSuccessfulOcrResponse();

            $response = $this->uploadDocument($file);

            $response->assertCreated();
        }

        $this->assertEquals(3, OcrDocument::count());
    }

    /**
     * Test: File size limit is enforced
     */
    public function test_file_size_limit_is_enforced()
    {
        $response = $this->uploadDocument(
            UploadedFile::fake()->create('oversized.pdf', 10241, 'application/pdf')
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonValidationErrors(['file']);

        $this->assertEquals(0, OcrDocument::count());
    }

    /**
     * Test: Batch upload creates multiple records
     */
    public function test_batch_upload_creates_multiple_records()
    {
        $this->fakeSuccessfulOcrResponse();

        $response = $this->batchUploadDocuments([
            UploadedFile::fake()->image('first.jpg'),
            $this->fakePdf('second.pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Batch upload completed')
            ->assertJsonPath('data.total_uploaded', 2)
            ->assertJsonPath('data.total_failed', 0);

        $this->assertEquals(2, OcrDocument::count());
        $this->assertCount(2, $response->json('data.uploaded'));
    }

    /**
     * Test: Batch upload rejects request when any file fails validation
     */
    public function test_batch_upload_handles_mixed_files()
    {
        $response = $this->batchUploadDocuments([
            UploadedFile::fake()->image('valid.jpg'),
            UploadedFile::fake()->create('invalid.exe', 100, 'application/octet-stream'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonValidationErrors(['files.1']);

        $this->assertEquals(0, OcrDocument::count());
    }

    /**
     * Test: Upload requires authentication
     */
    public function test_upload_requires_authentication()
    {
        $this->fakeSuccessfulOcrResponse();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/ocr/documents/upload', [
                'file' => UploadedFile::fake()->image('receipt.jpg'),
            ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Upload requires OCR bookkeeping entitlement
     */
    public function test_upload_requires_ocr_bookkeeping_entitlement()
    {
        $this->setOcrBookkeepingEntitlement(false);

        $this->fakeSuccessfulOcrResponse();

        $response = $this->uploadDocument(UploadedFile::fake()->image('no-entitlement.jpg'));

        $response->assertStatus(403);

        $this->assertEquals(0, OcrDocument::count());
    }

    /**
     * Test: ProcessOcrDocument job constructor takes int documentId
     *
     * Verifies the job API contract since HTTP tests are skipped.
     */
    public function test_process_ocr_document_job_accepts_document_id()
    {
        $document = OcrDocument::factory()->create();

        // Job constructor takes int documentId, NOT model
        $job = new ProcessOcrDocument($document->id);

        $this->assertInstanceOf(ProcessOcrDocument::class, $job);
    }
}
