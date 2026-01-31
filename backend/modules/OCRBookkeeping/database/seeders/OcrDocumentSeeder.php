<?php

namespace Modules\OCRBookkeeping\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OcrDocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = 1; // Assuming user ID 1 exists

        // Sample documents in various states
        $documents = [
            // Pending document
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/24/sample_pending.pdf',
                'file_name' => 'sample_pending.pdf',
                'file_size' => 102400,
                'mime_type' => 'application/pdf',
                'status' => 'PENDING',
                'created_by' => $userId,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],

            // Processing document
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/24/sample_processing.pdf',
                'file_name' => 'sample_processing.pdf',
                'file_size' => 153600,
                'mime_type' => 'application/pdf',
                'status' => 'PROCESSING',
                'created_by' => $userId,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(30),
            ],

            // Needs review document (high confidence)
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/24/sample_review_high.jpg',
                'file_name' => 'sample_review_high.jpg',
                'file_size' => 204800,
                'mime_type' => 'image/jpeg',
                'status' => 'NEEDS_REVIEW',
                'ocr_raw_data' => [
                    'status' => 'success',
                    'extracted_data' => [
                        'vendor_customer_name' => 'ABC Supplies Ltd',
                        'invoice_date' => '2024-12-20',
                        'invoice_number' => 'INV-2024-001',
                        'total_amount' => 1250.00,
                        'currency' => 'USD',
                    ],
                ],
                'ocr_confidence_scores' => [
                    'vendor_name' => 0.95,
                    'document_date' => 0.92,
                    'total_amount' => 0.98,
                    'invoice_number' => 0.90,
                ],
                'extracted_at' => now()->subMinutes(45),
                'vendor_name' => 'ABC Supplies Ltd',
                'document_date' => Carbon::parse('2024-12-20'),
                'document_reference' => 'INV-2024-001',
                'currency_code' => 'USD',
                'line_items' => [
                    [
                        'description' => 'Office Supplies',
                        'amount' => 1000.00,
                        'quantity' => 1,
                        'account_id' => 16, // Operating Expenses
                    ],
                ],
                'subtotal' => 1000.00,
                'tax_amount' => 250.00,
                'tax_rate' => 25.0,
                'total_amount' => 1250.00,
                'created_by' => $userId,
                'created_at' => now()->subMinutes(50),
                'updated_at' => now()->subMinutes(45),
            ],

            // Needs review document (low confidence)
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/24/sample_review_low.png',
                'file_name' => 'sample_review_low.png',
                'file_size' => 256000,
                'mime_type' => 'image/png',
                'status' => 'NEEDS_REVIEW',
                'ocr_raw_data' => [
                    'status' => 'success',
                    'extracted_data' => [
                        'vendor_customer_name' => 'XYZ Corp',
                        'invoice_date' => '2024-12-19',
                        'total_amount' => 750.50,
                    ],
                ],
                'ocr_confidence_scores' => [
                    'vendor_name' => 0.65,
                    'document_date' => 0.70,
                    'total_amount' => 0.75,
                    'invoice_number' => 0.45,
                ],
                'extracted_at' => now()->subMinutes(30),
                'vendor_name' => 'XYZ Corp',
                'document_date' => Carbon::parse('2024-12-19'),
                'currency_code' => 'USD',
                'total_amount' => 750.50,
                'created_by' => $userId,
                'created_at' => now()->subMinutes(35),
                'updated_at' => now()->subMinutes(30),
            ],

            // Extraction failed document
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/24/sample_failed.pdf',
                'file_name' => 'sample_failed.pdf',
                'file_size' => 51200,
                'mime_type' => 'application/pdf',
                'status' => 'EXTRACTION_FAILED',
                'error_message' => 'OCR service timeout - document quality too low',
                'retry_count' => 3,
                'created_by' => $userId,
                'created_at' => now()->subHours(3),
                'updated_at' => now()->subHours(2),
            ],

            // Posted document
            [
                'uuid' => Str::uuid(),
                'file_path' => '2024/12/23/sample_posted.pdf',
                'file_name' => 'sample_posted.pdf',
                'file_size' => 178000,
                'mime_type' => 'application/pdf',
                'status' => 'POSTED',
                'ocr_raw_data' => [
                    'status' => 'success',
                    'extracted_data' => [
                        'vendor_customer_name' => 'Tech Solutions Inc',
                        'invoice_date' => '2024-12-15',
                        'invoice_number' => 'TS-2024-456',
                        'total_amount' => 2500.00,
                    ],
                ],
                'ocr_confidence_scores' => [
                    'vendor_name' => 0.88,
                    'document_date' => 0.95,
                    'total_amount' => 0.92,
                    'invoice_number' => 0.85,
                ],
                'extracted_at' => now()->subDays(2),
                'vendor_name' => 'Tech Solutions Inc',
                'document_date' => Carbon::parse('2024-12-15'),
                'document_reference' => 'TS-2024-456',
                'currency_code' => 'USD',
                'line_items' => [
                    [
                        'description' => 'Software License',
                        'amount' => 2000.00,
                        'quantity' => 1,
                        'account_id' => 16,
                    ],
                ],
                'subtotal' => 2000.00,
                'tax_amount' => 500.00,
                'tax_rate' => 25.0,
                'total_amount' => 2500.00,
                'journal_entry_id' => 1, // Assuming journal entry exists
                'posted_at' => now()->subDay(),
                'posted_by' => $userId,
                'reviewed_by' => $userId,
                'reviewed_at' => now()->subDays(2),
                'created_by' => $userId,
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDay(),
            ],
        ];

        foreach ($documents as $document) {
            OcrDocument::create($document);
        }

        $this->command->info('OCR documents seeded successfully!');
    }
}
