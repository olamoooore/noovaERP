<?php

namespace Modules\OCRBookkeeping\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Jobs\ProcessOcrDocument;
use App\Models\User;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
        
        // Fake storage and queue
        Storage::fake('ocr-documents-local');
        Queue::fake();
    }

    /**
     * Property 1: Upload creates storage and database records
     */
    public function test_upload_creates_storage_and_database_records()
    {
        $this->markTestSkipped('Requires full database setup with tenant migrations');
        
        $file = UploadedFile::fake()->image('receipt.jpg', 800, 600)->size(1024);

        $response = $this->postJson('/api/v1/ocr/documents/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        
        // Verify database record created
        $this->assertDatabaseHas('ocr_documents', [
            'file_name' => 'receipt.jpg',
            'status' => 'PENDING',
            'created_by' => $this->user->id,
        ]);

        // Verify file stored
        $document = OcrDocument::where('file_name', 'receipt.jpg')->first();
        Storage::disk('ocr-documents-local')->assertExists($document->file_path);
    }

    /**
     * Property 2: Upload triggers OCR job
     */
    public function test_upload_triggers_ocr_job()
    {
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->postJson('/api/v1/ocr/documents/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(201);

        // Verify job was dispatched
        Queue::assertPushed(ProcessOcrDocument::class, function ($job) {
            return $job->document instanceof OcrDocument;
        });
    }

    /**
     * Property 3: Document identifiers are unique
     */
    public function test_document_identifiers_are_unique()
    {
        $file1 = UploadedFile::fake()->image('receipt1.jpg');
        $file2 = UploadedFile::fake()->image('receipt2.jpg');

        $response1 = $this->postJson('/api/v1/ocr/documents/upload', ['file' => $file1]);
        $response2 = $this->postJson('/api/v1/ocr/documents/upload', ['file' => $file2]);

        $uuid1 = $response1->json('data.uuid');
        $uuid2 = $response2->json('data.uuid');

        $this->assertNotEquals($uuid1, $uuid2);
        
        // Verify UUIDs are valid
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid1
        );
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid2
        );
    }

    /**
     * Property 4: Invalid file types are rejected
     */
    public function test_invalid_file_types_are_rejected()
    {
        // Test invalid file types
        $invalidFiles = [
            UploadedFile::fake()->create('document.txt', 100, 'text/plain'),
            UploadedFile::fake()->create('document.doc', 100, 'application/msword'),
            UploadedFile::fake()->create('document.exe', 100, 'application/x-msdownload'),
        ];

        foreach ($invalidFiles as $file) {
            $response = $this->postJson('/api/v1/ocr/documents/upload', [
                'file' => $file,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('file');
        }

        // Verify no documents created
        $this->assertEquals(0, OcrDocument::count());
    }

    /**
     * Test: Valid file types are accepted
     */
    public function test_valid_file_types_are_accepted()
    {
        $validFiles = [
            UploadedFile::fake()->image('receipt.jpg'),
            UploadedFile::fake()->image('receipt.jpeg'),
            UploadedFile::fake()->image('receipt.png'),
            UploadedFile::fake()->create('invoice.pdf', 1024, 'application/pdf'),
        ];

        foreach ($validFiles as $file) {
            $response = $this->postJson('/api/v1/ocr/documents/upload', [
                'file' => $file,
            ]);

            $response->assertStatus(201);
        }

        $this->assertEquals(4, OcrDocument::count());
    }

    /**
     * Test: File size limit is enforced
     */
    public function test_file_size_limit_is_enforced()
    {
        // File larger than 10MB
        $largeFile = UploadedFile::fake()->create('large.jpg', 11000); // 11MB

        $response = $this->postJson('/api/v1/ocr/documents/upload', [
            'file' => $largeFile,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }

    /**
     * Test: Batch upload creates multiple records
     */
    public function test_batch_upload_creates_multiple_records()
    {
        $files = [
            UploadedFile::fake()->image('receipt1.jpg'),
            UploadedFile::fake()->image('receipt2.jpg'),
            UploadedFile::fake()->image('receipt3.jpg'),
        ];

        $response = $this->postJson('/api/v1/ocr/documents/batch-upload', [
            'files' => $files,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.uploaded', 3);
        $response->assertJsonPath('data.failed', 0);

        $this->assertEquals(3, OcrDocument::count());
        
        // Verify all jobs dispatched
        Queue::assertPushed(ProcessOcrDocument::class, 3);
    }

    /**
     * Test: Batch upload handles mixed valid/invalid files
     */
    public function test_batch_upload_handles_mixed_files()
    {
        $files = [
            UploadedFile::fake()->image('receipt1.jpg'),
            UploadedFile::fake()->create('invalid.txt', 100, 'text/plain'),
            UploadedFile::fake()->image('receipt2.jpg'),
        ];

        $response = $this->postJson('/api/v1/ocr/documents/batch-upload', [
            'files' => $files,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.uploaded', 2);
        $response->assertJsonPath('data.failed', 1);

        $this->assertEquals(2, OcrDocument::count());
    }

    /**
     * Test: Upload requires authentication
     */
    public function test_upload_requires_authentication()
    {
        $this->app['auth']->forgetGuards();

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->postJson('/api/v1/ocr/documents/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: Upload requires permission
     */
    public function test_upload_requires_permission()
    {
        // Create user without ocr.upload permission
        $userWithoutPermission = User::factory()->create();
        $this->actingAs($userWithoutPermission);

        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->postJson('/api/v1/ocr/documents/upload', [
            'file' => $file,
        ]);

        // Assuming permission middleware returns 403
        $response->assertStatus(403);
    }
}
