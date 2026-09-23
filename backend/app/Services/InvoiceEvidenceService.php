<?php

namespace App\Services;

use App\Contracts\EsignProviderInterface;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceEvidenceService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/jpg',
    ];

    public function __construct(
        private InvoiceActivityService $activityLog,
    ) {}

    public function upload(
        Invoice $invoice,
        User $user,
        string $type,
        UploadedFile $file,
        ?string $notes = null,
    ): InvoiceEvidence {

        if (! in_array($type, InvoiceEvidence::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid evidence type: {$type}. Allowed: ".implode(', ', InvoiceEvidence::TYPES));
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of 10 MB.');
        }

        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "File type {$mimeType} is not allowed. Allowed types: ".implode(', ', self::ALLOWED_MIME_TYPES)
            );
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        $path = $this->storeFile($invoice, $file);

        $evidence = DB::transaction(function () use ($invoice, $user, $type, $path, $file, $checksum, $mimeType, $notes) {
            $evidence = InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => $type,
                'source' => InvoiceEvidence::SOURCE_UPLOAD,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => $checksum,
                'notes' => $notes,
                'uploaded_by' => $user->id,
            ]);

            $this->syncEvidenceStatus($invoice);

            return $evidence;
        });

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'evidence_id' => $evidence->id,
                'type' => $type,
                'file_name' => $file->getClientOriginalName(),
                'uploaded_by' => $user->id,
                'checksum' => $checksum,
            ],
            $invoice->getOriginal('evidence_status') ?? Invoice::EVIDENCE_NOT_REQUIRED,
            $invoice->fresh()->evidence_status,
            InvoiceActivityLog::ACTOR_USER,
            $user->id,
        );

        Log::info('Invoice evidence uploaded', [
            'evidence_id' => $evidence->id,
            'invoice_id' => $invoice->id,
            'type' => $type,
            'uploaded_by' => $user->id,
        ]);

        return $evidence;
    }

    public function recordPortalAcceptance(
        Invoice $invoice,
        string $signerName,
        string $signerEmail,
        string $signerIp,
        string $signerUserAgent,
    ): InvoiceEvidence {

        $evidence = DB::transaction(function () use ($invoice, $signerName, $signerEmail, $signerIp, $signerUserAgent) {
            $evidence = InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
                'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signer_ip' => $signerIp,
                'signer_user_agent' => $signerUserAgent,
                'signed_at' => now(),
            ]);

            $this->syncEvidenceStatus($invoice);

            return $evidence;
        });

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'evidence_id' => $evidence->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
                'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
                'signer_email' => $signerEmail,
            ],
            $invoice->getOriginal('evidence_status') ?? Invoice::EVIDENCE_NOT_REQUIRED,
            $invoice->fresh()->evidence_status,
            InvoiceActivityLog::ACTOR_CUSTOMER,
            null,
        );

        return $evidence;
    }

    public function recordEsignCompletion(
        Invoice $invoice,
        string $provider,
        string $externalId,
        array $signerData = [],
    ): InvoiceEvidence {

        $evidence = DB::transaction(function () use ($invoice, $provider, $externalId, $signerData) {
            $evidence = InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ESIGN,
                'source' => InvoiceEvidence::SOURCE_ESIGN_PROVIDER,
                'provider' => $provider,
                'external_id' => $externalId,
                'signer_name' => $signerData['signer_name'] ?? null,
                'signer_email' => $signerData['signer_email'] ?? null,
                'signer_ip' => $signerData['signer_ip'] ?? null,
                'signed_at' => now(),
            ]);

            $this->syncEvidenceStatus($invoice);

            return $evidence;
        });

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'evidence_id' => $evidence->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ESIGN,
                'provider' => $provider,
                'external_id' => $externalId,
            ],
            $invoice->getOriginal('evidence_status') ?? Invoice::EVIDENCE_NOT_REQUIRED,
            $invoice->fresh()->evidence_status,
            InvoiceActivityLog::ACTOR_CUSTOMER,
            null,
        );

        return $evidence;
    }

    public function softDelete(InvoiceEvidence $evidence, User $user, string $reason): void
    {
        if ($evidence->invoice->status !== 'draft') {
            throw new \InvalidArgumentException('Evidence can only be deleted from draft invoices.');
        }

        $evidence->update(['notes' => ($evidence->notes ? "{$evidence->notes}\n" : '')."Deleted: {$reason}"]);
        $evidence->delete();

        $this->syncEvidenceStatus($evidence->invoice);

        Log::info('Invoice evidence soft-deleted', [
            'evidence_id' => $evidence->id,
            'invoice_id' => $evidence->invoice_id,
            'deleted_by' => $user->id,
            'reason' => $reason,
        ]);
    }

    public function syncEvidenceStatus(Invoice $invoice): void
    {
        $requiresEvidence = $invoice->requires_approval;

        if (! $requiresEvidence) {
            $newStatus = Invoice::EVIDENCE_NOT_REQUIRED;
        } elseif ($this->hasQualifyingEvidence($invoice)) {
            $newStatus = Invoice::EVIDENCE_SATISFIED;
        } else {
            $newStatus = Invoice::EVIDENCE_PENDING;
        }

        if ($invoice->evidence_status !== $newStatus) {
            $invoice->updateQuietly(['evidence_status' => $newStatus]);
        }
    }

    public function hasQualifyingEvidence(Invoice $invoice): bool
    {
        return $invoice->evidence()
            ->qualifying()
            ->whereNull('deleted_at')
            ->exists();
    }

    public function checkGuard(Invoice $invoice): array
    {
        if (! $invoice->requires_approval) {
            return ['blocked' => false];
        }

        if ($invoice->evidence_status === Invoice::EVIDENCE_PENDING) {
            return [
                'blocked' => true,
                'code' => 'EVIDENCE_REQUIRED',
                'message' => 'Invoice requires evidence before posting. Please upload required documents.',
                'evidence_status' => $invoice->evidence_status,
                'required_types' => InvoiceEvidence::QUALIFYING_TYPES,
            ];
        }

        return ['blocked' => false];
    }

    private function storeFile(Invoice $invoice, UploadedFile $file): string
    {
        $tenant = tenant();
        $tenantId = $tenant?->id ?? 'public';
        $path = "invoices/{$invoice->id}/evidence/".$file->getClientOriginalName();

        Storage::disk('public')->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return $path;
    }

    public function createPortalAcceptanceLink(Invoice $invoice, int $expiresInDays = 30): array
    {
        $token = \Illuminate\Support\Str::uuid()->toString();
        $expiresAt = now()->addDays($expiresInDays);

        $evidence = InvoiceEvidence::create([
            'invoice_id' => $invoice->id,
            'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
            'source' => InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE,
            'external_id' => $token,
            'signed_at' => null,
        ]);

        $signedUrl = url("/api/v1/invoice-accept/{$token}");

        return [
            'token' => $token,
            'url' => $signedUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'evidence_id' => $evidence->id,
        ];
    }

    public function findByAcceptanceToken(string $token): ?InvoiceEvidence
    {
        return InvoiceEvidence::where('external_id', $token)
            ->where('source', InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE)
            ->whereNull('signed_at')
            ->first();
    }

    public function sendForEsign(Invoice $invoice, array $signers = []): array
    {
        $provider = app(EsignProviderInterface::class);

        $envelope = $provider->createEnvelope([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'signers' => $signers,
            'document_url' => url("/api/v1/invoices/{$invoice->id}/pdf"),
        ]);

        $evidence = InvoiceEvidence::create([
            'invoice_id' => $invoice->id,
            'type' => InvoiceEvidence::TYPE_CUSTOMER_ESIGN,
            'source' => InvoiceEvidence::SOURCE_ESIGN_PROVIDER,
            'external_id' => $envelope['envelope_id'],
            'provider' => class_basename($provider),
        ]);

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'evidence_id' => $evidence->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ESIGN,
                'envelope_id' => $envelope['envelope_id'],
                'provider' => class_basename($provider),
                'status' => $envelope['status'],
            ],
            $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
            $invoice->fresh()->evidence_status,
            InvoiceActivityLog::ACTOR_SYSTEM,
            null,
        );

        return [
            'envelope_id' => $envelope['envelope_id'],
            'status' => $envelope['status'],
            'evidence_id' => $evidence->id,
        ];
    }
}
