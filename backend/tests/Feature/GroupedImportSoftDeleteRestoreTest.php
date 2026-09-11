<?php

namespace Tests\Feature;

use App\Platform\Import\ImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

class GroupedImportSoftDeleteRestoreTest extends TenantTestCase
{
    public function test_grouped_import_restores_deleted_header_and_replaces_its_lines(): void
    {
        Schema::create('test_import_headers', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('test_import_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('header_id')->constrained('test_import_headers')->cascadeOnDelete();
            $table->string('description')->nullable();
        });

        $headerId = DB::table('test_import_headers')->insertGetId([
            'document_number' => 'DO2026/05/004',
            'name' => 'Deleted version',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);
        DB::table('test_import_lines')->insert([
            'header_id' => $headerId,
            'description' => 'Old line',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'delivery-orders.csv',
            "document_number,name,description\nDO2026/05/004,Restored version,Replacement line\n",
        );

        $result = app(ImportService::class)->importGroupedCSV(
            'test_import_headers',
            'test_import_lines',
            $file,
            ['document_number' => 'document_number', 'name' => 'name'],
            [
                'group_key' => 'document_number',
                'restore_soft_deleted_by' => 'document_number',
                'line_column_map' => [
                    'header_id' => 'header_id',
                    'map' => ['description' => 'description'],
                ],
            ],
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['restored']);
        $this->assertSame([], $result['errors']);
        $this->assertDatabaseHas('test_import_headers', [
            'id' => $headerId,
            'document_number' => 'DO2026/05/004',
            'name' => 'Restored version',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('test_import_lines', ['header_id' => $headerId, 'description' => 'Old line']);
        $this->assertDatabaseHas('test_import_lines', ['header_id' => $headerId, 'description' => 'Replacement line']);
    }
}
