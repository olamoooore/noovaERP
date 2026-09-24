<?php

namespace App\Services;

use App\Contracts\EsignProviderInterface;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceEvidence;
use App\Models\User;
use App\Platform\Notifications\NotificationService;
use App\Services\Chatter\ChatterService;
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
        private ChatterService $chatterService,
        private NotificationService $notificationService,
        private EsignService $esignService,
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
                'invoice_number' => $invoice->invoice_number,
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
        InvoiceEvidence $linkEvidence,
        string $signerName,
        string $signerEmail,
        string $signerIp,
        string $signerUserAgent,
        ?string $signatureDataUrl = null,
    ): InvoiceEvidence {

        $signaturePath = null;
        if ($signatureDataUrl && str_starts_with($signatureDataUrl, 'data:image/')) {
            $signaturePath = $this->storeSignatureImage($invoice, $signatureDataUrl);
        }

        // Update the SAME evidence row that backs the acceptance link (it holds
        // the token in external_id). Creating a second row left the link row
        // unsigned, so re-opening the link reported "not accepted" and dropped
        // the signer data on reload.
        DB::transaction(function () use ($invoice, $linkEvidence, $signerName, $signerEmail, $signerIp, $signerUserAgent, $signaturePath) {
            $linkEvidence->update([
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signer_ip' => $signerIp,
                'signer_user_agent' => $signerUserAgent,
                'file_path' => $signaturePath,
                'file_name' => $signaturePath ? basename($signaturePath) : null,
                'mime_type' => $signaturePath ? 'image/png' : null,
                'signed_at' => now(),
            ]);

            $this->syncEvidenceStatus($invoice);
        });

        $evidence = $linkEvidence->fresh();

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'invoice_number' => $invoice->invoice_number,
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

        $this->notifyCustomerAction($invoice, 'invoice.customer_accepted', [
            'type' => 'Invoice Acceptance',
            'invoice_number' => $invoice->invoice_number,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'invoice_id' => $invoice->id,
        ]);

        return $evidence;
    }

    /**
     * Record customer-supplied evidence (bank-transfer receipt, signed
     * document, or anything else) through the public acceptance portal and
     * surface it in chatter + to the invoice creator.
     */
    public function recordCustomerEvidence(
        Invoice $invoice,
        UploadedFile $file,
        ?string $notes = null,
        ?string $customerName = null,
        ?string $customerEmail = null,
    ): InvoiceEvidence {
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

        $evidence = DB::transaction(function () use ($invoice, $file, $notes, $customerName, $customerEmail, $path, $checksum, $mimeType) {
            return InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => InvoiceEvidence::TYPE_OTHER,
                'source' => InvoiceEvidence::SOURCE_UPLOAD,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => $checksum,
                'notes' => $notes,
                'signer_name' => $customerName,
                'signer_email' => $customerEmail,
                'uploaded_by' => null,
            ]);
        });

        // Surface the file as a chatter attachment so the invoice's creator can
        // see the customer's evidence directly in the chatter panel.
        try {
            $this->chatterService->registerAttachment(
                Invoice::class,
                $invoice->id,
                $file->getClientOriginalName(),
                $mimeType,
                (int) $file->getSize(),
                'public',
                $path,
                null,
                // Customer uploads have no platform user; name them so the
                // feed does not attribute the file to "System".
                trim(($customerName ?: 'Customer').($customerEmail ? " ({$customerEmail})" : '')),
            );
        } catch (\Throwable $e) {
            Log::warning('InvoiceEvidenceService: failed to mirror evidence to chatter', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_EVIDENCE_ADDED,
            [
                'invoice_number' => $invoice->invoice_number,
                'evidence_id' => $evidence->id,
                'type' => InvoiceEvidence::TYPE_OTHER,
                'file_name' => $file->getClientOriginalName(),
                'notes' => $notes,
            ],
            $invoice->getOriginal('evidence_status') ?? Invoice::EVIDENCE_NOT_REQUIRED,
            $invoice->fresh()->evidence_status,
            InvoiceActivityLog::ACTOR_CUSTOMER,
            null,
        );

        $this->notifyCustomerAction($invoice, 'invoice.evidence_uploaded', [
            'type' => 'Evidence',
            'invoice_number' => $invoice->invoice_number,
            'file_name' => $file->getClientOriginalName(),
            'invoice_id' => $invoice->id,
        ]);

        Log::info('Invoice evidence uploaded by customer', [
            'evidence_id' => $evidence->id,
            'invoice_id' => $invoice->id,
            'file_name' => $file->getClientOriginalName(),
        ]);

        return $evidence;
    }

    /**
     * Notify the invoice's creator of a customer action (acceptance, evidence).
     * Fails soft — notification issues must never break the customer flow.
     */
    private function notifyCustomerAction(Invoice $invoice, string $event, array $data): void
    {
        try {
            $creator = $invoice->createdBy;
            if (! $creator) {
                return;
            }

            // Notifications render `subject` + `body`; without them the service
            // falls back to dumping the raw payload as JSON, which is what the
            // bell was showing. Build readable text per event.
            $number = $invoice->invoice_number;
            $amount = trim(($invoice->currency_code ?: '').' '.number_format((float) $invoice->total, 2));

            [$defaultSubject, $defaultBody] = match ($event) {
                'invoice.customer_accepted' => [
                    "Invoice {$number} accepted by the customer",
                    ($data['signer_name'] ?? 'The customer')
                        .($data['signer_email'] ? " ({$data['signer_email']})" : '')
                        ." accepted invoice {$number} for {$amount}.",
                ],
                'invoice.evidence_uploaded' => [
                    "Evidence uploaded for invoice {$number}",
                    ($data['customer_name'] ?? $data['signer_name'] ?? 'The customer')
                        .' uploaded '.($data['file_name'] ?? 'a document')
                        ." against invoice {$number} ({$amount}).",
                ],
                default => [
                    "Update on invoice {$number}",
                    "Invoice {$number} was updated by the customer.",
                ],
            };

            $this->notificationService->dispatch($event, $creator, array_merge([
                'subject' => $defaultSubject,
                'body' => $defaultBody,
            ], $data));
        } catch (\Throwable $e) {
            Log::warning('InvoiceEvidenceService: failed to notify creator of customer action', [
                'invoice_id' => $invoice->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordEsignCompletion(
        Invoice $invoice,
        string $provider,
        string $externalId,
        array $signerData = [],
        ?string $documentPath = null,
    ): InvoiceEvidence {

        $evidence = DB::transaction(function () use ($invoice, $provider, $externalId, $signerData, $documentPath) {
            $evidence = InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ESIGN,
                'source' => InvoiceEvidence::SOURCE_ESIGN_PROVIDER,
                'provider' => $provider,
                'external_id' => $externalId,
                // Without this the evidence row recorded that a signature
                // happened but had no signed document attached to it.
                'file_path' => $documentPath,
                'file_name' => $documentPath ? basename($documentPath) : null,
                'mime_type' => $documentPath ? 'application/pdf' : null,
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
                'invoice_number' => $invoice->invoice_number,
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

    /**
     * Record a physically signed (paper) copy uploaded through the public
     * signing link. Deliberately distinct from recordEsignCompletion (which
     * records a digital signature): this lands as a customer_acceptance
     * evidence row, so the invoice shows the customer chose to sign on paper
     * and upload the scan.
     *
     * @param  array{signer_name?:?string,signer_email?:?string,signer_ip?:?string}  $signerData
     */
    public function recordUploadedSignedCopy(
        Invoice $invoice,
        string $documentPath,
        ?string $originalName = null,
        ?string $mimeType = null,
        ?int $sizeBytes = null,
        array $signerData = [],
    ): InvoiceEvidence {
        $evidence = DB::transaction(function () use ($invoice, $documentPath, $originalName, $mimeType, $sizeBytes, $signerData) {
            $evidence = InvoiceEvidence::create([
                'invoice_id' => $invoice->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
                'source' => InvoiceEvidence::SOURCE_UPLOAD,
                'provider' => 'Built-in',
                'file_path' => $documentPath,
                'file_name' => $originalName ?: basename($documentPath),
                'mime_type' => $mimeType ?: 'application/pdf',
                'size_bytes' => $sizeBytes,
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
                'invoice_number' => $invoice->invoice_number,
                'evidence_id' => $evidence->id,
                'type' => InvoiceEvidence::TYPE_CUSTOMER_ACCEPTANCE,
                'source' => InvoiceEvidence::SOURCE_UPLOAD,
                'completion' => 'uploaded_document',
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

    /**
     * Vendor-side confirmation of a customer's uploaded evidence. Once
     * confirmed, the evidence satisfies the "Evidence confirmed" trail step.
     */
    public function confirmEvidence(InvoiceEvidence $evidence, User $user): InvoiceEvidence
    {
        if ($evidence->isConfirmed()) {
            return $evidence;
        }

        $evidence->update([
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        $invoice = $evidence->invoice ?? Invoice::find($evidence->invoice_id);

        if ($invoice) {
            $this->activityLog->log(
                $invoice,
                InvoiceActivityLog::EVENT_EVIDENCE_CONFIRMED,
                [
                    'invoice_number' => $invoice->invoice_number,
                    'evidence_id' => $evidence->id,
                    'type' => $evidence->type,
                    'file_name' => $evidence->file_name,
                    'confirmed_by' => $user->id,
                ],
                $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
                $invoice->fresh()->evidence_status,
                InvoiceActivityLog::ACTOR_USER,
                $user->id,
            );
        }

        Log::info('Invoice evidence confirmed', [
            'evidence_id' => $evidence->id,
            'invoice_id' => $evidence->invoice_id,
            'confirmed_by' => $user->id,
        ]);

        return $evidence->fresh();
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

        // Built-in e-signature: the link now opens our /sign/{token} page where
        // the customer signs the real invoice PDF, instead of the legacy
        // /invoice-accept portal. The old portal is retained only for links
        // already in the wild; new requests become EsignRequest rows and the
        // signed copy is stored as a customer_esign evidence on completion.
        $esign = $this->esignService->request(
            'invoice',
            $invoice->id,
            [[
                'name' => $invoice->customer?->name,
                'email' => $invoice->customer?->email,
            ]],
            null,
            $expiresInDays,
        );

        $frontendUrl = rtrim((string) (env('FRONTEND_URL') ?: config('app.url', '')), '/');

        return [
            'token' => $esign->token,
            'url' => "{$frontendUrl}/sign/{$esign->token}",
            'expires_at' => $esign->expires_at?->toIso8601String(),
            'evidence_id' => null,
            'esign_request_id' => $esign->id,
        ];
    }

    public function findByAcceptanceToken(string $token): ?InvoiceEvidence
    {
        return InvoiceEvidence::where('external_id', $token)
            ->where('source', InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE)
            ->whereNull('revoked_at')
            ->whereNull('signed_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /**
     * Revoke every outstanding acceptance link for an invoice.
     *
     * Called when the invoice is withdrawn (reset to draft) or cancelled: the
     * customer must not be able to accept an invoice that is being reissued,
     * and any prior acceptance no longer counts for the new version.
     */
    public function revokeAcceptanceLinks(Invoice $invoice): int
    {
        // A withdrawn/cancelled invoice must also stop being signable through
        // the built-in e-sign link issued for it.
        \App\Models\EsignRequest::where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->where('status', \App\Models\EsignRequest::STATUS_PENDING)
            ->update(['status' => \App\Models\EsignRequest::STATUS_CANCELLED]);

        $revoked = InvoiceEvidence::where('invoice_id', $invoice->id)
            ->where('source', InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            Log::info('InvoiceEvidenceService: revoked acceptance links', [
                'invoice_id' => $invoice->id,
                'count' => $revoked,
            ]);
        }

        return $revoked;
    }

    public function findAnyByAcceptanceToken(string $token): ?InvoiceEvidence
    {
        return InvoiceEvidence::where('external_id', $token)
            ->where('source', InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE)
            ->first();
    }

    /**
     * Locate which tenant owns a given acceptance token.
     *
     * Customer-facing acceptance links carry no tenant in the host, so the
     * public route must resolve the tenant from the token itself. The token is
     * a globally-unique UUID, so scanning tenant schemas is safe and cheap
     * (acceptance links are low-volume).
     */
    public function resolveTenantForAcceptanceToken(string $token): ?\App\Models\Tenant
    {
        foreach (\App\Models\Tenant::all() as $tenant) {
            $found = $tenant->run(function () use ($token) {
                return InvoiceEvidence::where('external_id', $token)
                    ->where('source', InvoiceEvidence::SOURCE_PORTAL_ACCEPTANCE)
                    ->exists();
            });

            if ($found) {
                return $tenant;
            }
        }

        return null;
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
                'invoice_number' => $invoice->invoice_number,
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

    private function storeSignatureImage(Invoice $invoice, string $dataUrl): string
    {
        $payload = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $binary = base64_decode($payload, true);
        if ($binary === false) {
            throw new \InvalidArgumentException('Invalid signature data.');
        }

        $filename = sprintf('invoice-%d-signature-%s.png', $invoice->id, \Illuminate\Support\Str::random(8));
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default') === 'local' ? 'local' : 'public');
        $path = 'invoice-evidence/'.$invoice->id.'/'.$filename;
        $disk->put($path, $binary);

        return $path;
    }
}
