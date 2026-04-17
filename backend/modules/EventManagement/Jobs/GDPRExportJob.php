<?php

namespace Modules\EventManagement\Jobs;

use Modules\EventManagement\Services\PIIVaultService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * GDPRExportJob - Task 4.3 (Part 2)
 * 
 * Handles GDPR data export requests with secure file generation,
 * encryption, and automated cleanup.
 * 
 * Requirements: 20.4, 20.5
 */
class GDPRExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $requestReference;
    protected string $entityType;
    protected int $entityId;
    protected array $requestedFields;
    protected array $exportOptions;

    private function currentTenantId(): string
    {
        if (!function_exists('tenant') || !tenant()) {
            throw new \RuntimeException('GDPR export requires active tenant context.');
        }

        return (string) tenant('id');
    }

    private function exportDirectory(): string
    {
        return storage_path('app/gdpr_exports/' . $this->currentTenantId());
    }

    /**
     * Create a new job instance.
     *
     * @param string $requestReference Unique reference for the export request
     * @param string $entityType Type of entity to export
     * @param int $entityId ID of the entity
     * @param array $requestedFields Specific fields to export (empty for all)
     * @param array $exportOptions Export configuration options
     */
    public function __construct(
        string $requestReference,
        string $entityType,
        int $entityId,
        array $requestedFields = [],
        array $exportOptions = []
    ) {
        $this->requestReference = $requestReference;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->requestedFields = $requestedFields;
        $this->exportOptions = array_merge([
            'format' => 'json',
            'include_metadata' => true,
            'encrypt_export' => true,
            'file_expires_days' => 7,
        ], $exportOptions);
        
        // Set queue and connection
        $this->onQueue('gdpr-export');
        $this->onConnection('redis');
    }

    /**
     * Execute the job.
     *
     * @param PIIVaultService $piiVaultService
     * @return void
     * 
     * Requirements: 20.4, 20.5
     */
    public function handle(PIIVaultService $piiVaultService): void
    {
        $startTime = microtime(true);
        
        Log::info('Starting GDPR export job', [
            'request_reference' => $this->requestReference,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'requested_fields' => $this->requestedFields,
            'export_options' => $this->exportOptions,
        ]);

        try {
            // Update request status to processing
            $this->updateRequestStatus('processing', 'Export job started');

            // Step 1: Collect PII data from vault
            $piiData = $piiVaultService->exportPIIData(
                $this->entityType,
                $this->entityId,
                $this->requestedFields
            );

            // Step 2: Collect related data from other tables
            $relatedData = $this->collectRelatedData();

            // Step 3: Build complete export dataset
            $exportData = $this->buildExportDataset($piiData, $relatedData);

            // Step 4: Generate export file
            $exportFilePath = $this->generateExportFile($exportData);

            // Step 5: Encrypt file if requested
            if ($this->exportOptions['encrypt_export']) {
                $exportFilePath = $this->encryptExportFile($exportFilePath);
            }

            // Step 6: Calculate file expiration
            $fileExpiresAt = Carbon::now()->addDays($this->exportOptions['file_expires_days']);

            // Step 7: Update request with completion details
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->updateRequestStatus('completed', 'Export completed successfully', [
                'export_file_path' => $exportFilePath,
                'file_size_bytes' => filesize($exportFilePath),
                'record_count' => count($piiData),
                'execution_time_ms' => $executionTime,
                'file_expires_at' => $fileExpiresAt,
            ]);

            Log::info('GDPR export job completed', [
                'request_reference' => $this->requestReference,
                'export_file_path' => $exportFilePath,
                'record_count' => count($piiData),
                'execution_time_ms' => $executionTime,
            ]);

        } catch (\Exception $e) {
            $this->updateRequestStatus('failed', 'Export failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Log::error('GDPR export job failed', [
                'request_reference' => $this->requestReference,
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Collect related data from other tables based on entity type.
     * 
     * @return array Related data organized by table/type
     */
    private function collectRelatedData(): array
    {
        $relatedData = [];

        switch ($this->entityType) {
            case 'customer':
                $relatedData = $this->collectCustomerRelatedData();
                break;
            
            case 'booking':
                $relatedData = $this->collectBookingRelatedData();
                break;
            
            case 'attendee':
                $relatedData = $this->collectAttendeeRelatedData();
                break;
            
            default:
                Log::warning('Unknown entity type for GDPR export', [
                    'entity_type' => $this->entityType,
                    'request_reference' => $this->requestReference,
                ]);
        }

        return $relatedData;
    }

    /**
     * Collect customer-related data for export.
     * 
     * @return array Customer-related data
     */
    private function collectCustomerRelatedData(): array
    {
        $customerId = $this->entityId;
        $data = [];

        // Customer profile data
        $customer = DB::table('customers')
            ->where('id', $customerId)
            ->first();
        
        if ($customer) {
            $data['customer_profile'] = [
                'id' => $customer->id,
                'created_at' => $customer->created_at,
                'updated_at' => $customer->updated_at,
                'status' => $customer->status ?? 'active',
                'preferences' => $customer->preferences ? json_decode($customer->preferences, true) : [],
            ];
        }

        // Bookings made by customer
        $bookings = DB::table('bookings')
            ->where('customer_id', $customerId)
            ->select('id', 'event_id', 'status', 'total_amount', 'created_at')
            ->get();
        
        $data['bookings'] = $bookings->toArray();

        // Customer tier information
        $tierInfo = DB::table('customer_tiers')
            ->where('customer_id', $customerId)
            ->first();
        
        if ($tierInfo) {
            $data['loyalty_tier'] = [
                'tier_name' => $tierInfo->tier_name,
                'points_balance' => $tierInfo->points_balance,
                'tier_achieved_at' => $tierInfo->tier_achieved_at,
                'next_tier_points_required' => $tierInfo->next_tier_points_required,
            ];
        }

        // Consent records
        $consentRecords = DB::table('pii_consent_tracking')
            ->where('entity_type', 'customer')
            ->where('entity_id', $customerId)
            ->select('consent_type', 'consent_given', 'consent_given_at', 'consent_withdrawn_at')
            ->get();
        
        $data['consent_history'] = $consentRecords->toArray();

        // Access log summary (last 30 days)
        $accessSummary = DB::table('pii_access_log')
            ->join('pii_vault', 'pii_access_log.vault_id', '=', 'pii_vault.id')
            ->where('pii_vault.entity_type', 'customer')
            ->where('pii_vault.entity_id', $customerId)
            ->where('pii_access_log.accessed_at', '>', Carbon::now()->subDays(30))
            ->select('pii_access_log.access_type', 'pii_access_log.access_reason', 'pii_access_log.accessed_at')
            ->orderBy('pii_access_log.accessed_at', 'desc')
            ->limit(100)
            ->get();
        
        $data['recent_access_history'] = $accessSummary->toArray();

        return $data;
    }

    /**
     * Collect booking-related data for export.
     * 
     * @return array Booking-related data
     */
    private function collectBookingRelatedData(): array
    {
        $bookingId = $this->entityId;
        $data = [];

        // Booking details
        $booking = DB::table('bookings')
            ->leftJoin('events', 'bookings.event_id', '=', 'events.id')
            ->where('bookings.id', $bookingId)
            ->select(
                'bookings.*',
                'events.title as event_title',
                'events.event_date',
                'events.venue_name'
            )
            ->first();
        
        if ($booking) {
            $data['booking_details'] = [
                'id' => $booking->id,
                'event_title' => $booking->event_title,
                'event_date' => $booking->event_date,
                'venue_name' => $booking->venue_name,
                'status' => $booking->status,
                'total_amount' => $booking->total_amount,
                'created_at' => $booking->created_at,
            ];
        }

        // Tickets for this booking
        $tickets = DB::table('tickets')
            ->where('booking_id', $bookingId)
            ->select('id', 'ticket_type', 'status', 'issued_at', 'used_at')
            ->get();
        
        $data['tickets'] = $tickets->toArray();

        // Payment records
        $payments = DB::table('payments')
            ->where('booking_id', $bookingId)
            ->select('id', 'amount', 'status', 'payment_method', 'processed_at')
            ->get();
        
        $data['payments'] = $payments->toArray();

        return $data;
    }

    /**
     * Collect attendee-related data for export.
     * 
     * @return array Attendee-related data
     */
    private function collectAttendeeRelatedData(): array
    {
        $attendeeId = $this->entityId;
        $data = [];

        // Attendee details
        $attendee = DB::table('attendees')
            ->leftJoin('bookings', 'attendees.booking_id', '=', 'bookings.id')
            ->leftJoin('events', 'bookings.event_id', '=', 'events.id')
            ->where('attendees.id', $attendeeId)
            ->select(
                'attendees.*',
                'events.title as event_title',
                'events.event_date'
            )
            ->first();
        
        if ($attendee) {
            $data['attendee_details'] = [
                'id' => $attendee->id,
                'booking_id' => $attendee->booking_id,
                'event_title' => $attendee->event_title,
                'event_date' => $attendee->event_date,
                'ticket_type' => $attendee->ticket_type,
                'created_at' => $attendee->created_at,
            ];
        }

        return $data;
    }

    /**
     * Build complete export dataset combining PII and related data.
     * 
     * @param array $piiData PII data from vault
     * @param array $relatedData Related data from other tables
     * @return array Complete export dataset
     */
    private function buildExportDataset(array $piiData, array $relatedData): array
    {
        $exportData = [
            'export_metadata' => [
                'request_reference' => $this->requestReference,
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'export_generated_at' => Carbon::now()->toISOString(),
                'export_format' => $this->exportOptions['format'],
                'gdpr_article' => 'Article 20 - Right to data portability',
            ],
            'pii_data' => $piiData,
            'related_data' => $relatedData,
        ];

        // Add metadata if requested
        if ($this->exportOptions['include_metadata']) {
            $exportData['export_metadata']['requested_fields'] = $this->requestedFields;
            $exportData['export_metadata']['export_options'] = $this->exportOptions;
            $exportData['export_metadata']['data_sources'] = [
                'pii_vault' => 'Encrypted PII storage',
                'operational_tables' => 'Business operational data',
                'audit_logs' => 'Access and consent history',
            ];
        }

        return $exportData;
    }

    /**
     * Generate export file in the specified format.
     * 
     * @param array $exportData Complete export dataset
     * @return string Path to generated export file
     */
    private function generateExportFile(array $exportData): string
    {
        $fileName = "gdpr_export_{$this->requestReference}_{$this->entityType}_{$this->entityId}";
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        
        switch ($this->exportOptions['format']) {
            case 'json':
                $fileName .= "_{$timestamp}.json";
                $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
            
            case 'csv':
                $fileName .= "_{$timestamp}.csv";
                $content = $this->convertToCSV($exportData);
                break;
            
            case 'xml':
                $fileName .= "_{$timestamp}.xml";
                $content = $this->convertToXML($exportData);
                break;
            
            default:
                throw new \InvalidArgumentException("Unsupported export format: {$this->exportOptions['format']}");
        }

        // Create secure tenant-owned export directory if it doesn't exist
        $exportDir = $this->exportDirectory();
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0750, true);
        }

        $filePath = $exportDir . '/' . $fileName;
        
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException("Failed to write export file: {$filePath}");
        }

        // Set secure file permissions
        chmod($filePath, 0640);

        return $filePath;
    }

    /**
     * Convert export data to CSV format.
     * 
     * @param array $exportData Export dataset
     * @return string CSV content
     */
    private function convertToCSV(array $exportData): string
    {
        $csv = [];
        
        // Add header
        $csv[] = "Section,Field,Value,Classification,Created At,Expires At";
        
        // Add PII data
        foreach ($exportData['pii_data'] as $piiRecord) {
            $csv[] = sprintf(
                '"%s","%s","%s","%s","%s","%s"',
                'PII Data',
                $piiRecord['field_name'],
                $piiRecord['value'],
                $piiRecord['data_classification'] ?? '',
                $piiRecord['created_at'] ?? '',
                $piiRecord['expires_at'] ?? ''
            );
        }
        
        // Add related data (simplified)
        foreach ($exportData['related_data'] as $section => $records) {
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (is_array($record)) {
                        foreach ($record as $field => $value) {
                            $csv[] = sprintf(
                                '"%s","%s","%s","","",""',
                                $section,
                                $field,
                                is_array($value) ? json_encode($value) : $value
                            );
                        }
                    }
                }
            }
        }
        
        return implode("\n", $csv);
    }

    /**
     * Convert export data to XML format.
     * 
     * @param array $exportData Export dataset
     * @return string XML content
     */
    private function convertToXML(array $exportData): string
    {
        $xml = new \SimpleXMLElement('<gdpr_export/>');
        
        // Add metadata
        $metadata = $xml->addChild('metadata');
        foreach ($exportData['export_metadata'] as $key => $value) {
            $metadata->addChild($key, htmlspecialchars($value));
        }
        
        // Add PII data
        $piiSection = $xml->addChild('pii_data');
        foreach ($exportData['pii_data'] as $piiRecord) {
            $record = $piiSection->addChild('record');
            foreach ($piiRecord as $key => $value) {
                $record->addChild($key, htmlspecialchars($value));
            }
        }
        
        // Add related data
        $relatedSection = $xml->addChild('related_data');
        foreach ($exportData['related_data'] as $section => $records) {
            $sectionNode = $relatedSection->addChild($section);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $recordNode = $sectionNode->addChild('record');
                    if (is_array($record)) {
                        foreach ($record as $key => $value) {
                            $recordNode->addChild($key, htmlspecialchars(
                                is_array($value) ? json_encode($value) : $value
                            ));
                        }
                    }
                }
            }
        }
        
        return $xml->asXML();
    }

    /**
     * Encrypt export file for secure delivery.
     * 
     * @param string $filePath Path to export file
     * @return string Path to encrypted file
     */
    private function encryptExportFile(string $filePath): string
    {
        $encryptedFilePath = $filePath . '.encrypted';
        
        // Generate a random encryption key for this export
        $encryptionKey = random_bytes(32); // 256-bit key
        $iv = random_bytes(16); // 128-bit IV
        
        // Read original file
        $content = file_get_contents($filePath);
        
        // Encrypt content
        $encryptedContent = openssl_encrypt($content, 'AES-256-CBC', $encryptionKey, 0, $iv);
        
        // Create encrypted file with key and IV prepended
        $encryptedData = base64_encode($encryptionKey) . '|' . base64_encode($iv) . '|' . $encryptedContent;
        
        if (file_put_contents($encryptedFilePath, $encryptedData) === false) {
            throw new \RuntimeException("Failed to write encrypted export file: {$encryptedFilePath}");
        }
        
        // Set secure file permissions
        chmod($encryptedFilePath, 0640);
        
        // Remove original unencrypted file
        unlink($filePath);
        
        return $encryptedFilePath;
    }

    /**
     * Update export request status in database.
     * 
     * @param string $status New status
     * @param string $message Status message
     * @param array $metadata Additional metadata
     */
    private function updateRequestStatus(string $status, string $message, array $metadata = []): void
    {
        DB::table('pii_export_requests')
            ->where('request_reference', $this->requestReference)
            ->update([
                'status' => $status,
                'status_message' => $message,
                'export_file_path' => $metadata['export_file_path'] ?? null,
                'file_size_bytes' => $metadata['file_size_bytes'] ?? null,
                'record_count' => $metadata['record_count'] ?? null,
                'execution_time_ms' => $metadata['execution_time_ms'] ?? null,
                'file_expires_at' => $metadata['file_expires_at'] ?? null,
                'completed_at' => $status === 'completed' ? Carbon::now() : null,
                'error_details' => isset($metadata['error']) ? json_encode($metadata) : null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Handle job failure.
     * 
     * @param \Throwable $exception
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GDPR export job failed permanently', [
            'request_reference' => $this->requestReference,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'job_attempts' => $this->attempts(),
        ]);

        $this->updateRequestStatus('failed', 'Export job failed permanently: ' . $exception->getMessage(), [
            'error' => $exception->getMessage(),
            'job_attempts' => $this->attempts(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'gdpr-export',
            'privacy',
            'data-portability',
            'entity:' . $this->entityType,
            'format:' . $this->exportOptions['format'],
        ];
    }
}
