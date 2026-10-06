<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EsignRequest;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Chatter\ChatterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Models\DeliveryOrder;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

/**
 * Built-in e-signature: the whole flow, no third-party service.
 *
 *   request()   → mint a token for a document + signer, email the link
 *   findByToken → open a request from the public signing page
 *   complete()  → store the signature, re-render the real document PDF with the
 *                 signature block, record the audit trail, and mirror the signed
 *                 copy onto the document itself
 *
 * The signature is stamped into the document by the existing template renderer
 * (DomPDF), so every tenant template gets a signed version without being edited.
 */
class EsignService
{
    /** What a signature request can be raised against. */
    public const DOCUMENT_TYPES = [
        'invoice' => Invoice::class,
        'delivery_order' => DeliveryOrder::class,
        'quotation' => \App\Models\Quotation::class,
    ];

    public function __construct(
        private readonly ChatterService $chatter,
    ) {}

    /**
     * Raise a signature request for a document.
     *
     * @param  array<int, array{name?:string,email?:string}>  $signers
     */
    public function request(
        string $documentType,
        int $documentId,
        array $signers = [],
        ?int $templateId = null,
        int $expiresInDays = 30,
    ): EsignRequest {
        if (! isset(self::DOCUMENT_TYPES[$documentType])) {
            throw new \InvalidArgumentException("Unsupported e-signature document type: {$documentType}");
        }

        $model = self::DOCUMENT_TYPES[$documentType]::find($documentId);
        if (! $model) {
            throw new \RuntimeException("Document {$documentType}#{$documentId} not found.");
        }

        $signer = $signers[0] ?? [];
        $number = $this->documentNumber($documentType, $model);

        $request = EsignRequest::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_number' => $number,
            'template_id' => $templateId,
            'token' => Str::random(48),
            'status' => EsignRequest::STATUS_PENDING,
            'signer_name' => $signer['name'] ?? null,
            'signer_email' => $signer['email'] ?? null,
            'document_hash' => $this->unsignedHash($documentType, $documentId, $templateId),
            'sent_at' => now(),
            'expires_at' => now()->addDays($expiresInDays),
            'created_by' => auth()->id(),
        ]);

        $this->chatter->logEvent(
            self::DOCUMENT_TYPES[$documentType],
            $documentId,
            'status_changed',
            'Sent for e-signature'.($request->signer_email ? " to {$request->signer_email}" : ''),
            ['esign_request_id' => $request->id, 'expires_at' => $request->expires_at?->toIso8601String()],
        );

        // Issue #466 (DO14): public signing links carry no tenant in the host,
        // so the token → tenant mapping is registered centrally at issuance.
        app(DocumentTokenRegistry::class)->register(
            $request->token,
            tenant('id'),
            $documentType,
            $documentId,
            $request->expires_at,
        );

        return $request;
    }

    /** Resolve a request from its public token, rejecting closed/expired ones. */
    public function findByToken(string $token): ?EsignRequest
    {
        $request = EsignRequest::where('token', $token)->first();

        if (! $request) {
            return null;
        }

        // An expired-but-unsent request is marked expired the first time it is
        // opened, so the record matches what the customer was told.
        if ($request->isPending() && $request->isExpired()) {
            $request->update(['status' => EsignRequest::STATUS_EXPIRED]);
        }

        return $request;
    }

    /**
     * Which tenant owns this signing token. The link can be opened on any
     * domain, so the token — not the host — identifies the tenant.
     */
    public function resolveTenantForToken(string $token): ?Tenant
    {
        $needle = substr($token, 0, 12);

        foreach (Tenant::all() as $tenant) {
            try {
                tenancy()->initialize($tenant);

                if (EsignRequest::where('token', $token)->exists()) {
                    return $tenant;
                }
            } catch (\Throwable $e) {
                Log::warning('EsignService: tenant scan failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                tenancy()->end();
            }
        }

        Log::warning('EsignService: signing token not found in any tenant', ['token_prefix' => $needle]);

        return null;
    }

    /**
     * Complete a signature request: persist the signature, re-render the signed
     * PDF through the document's own template, record the audit trail, and
     * mirror the result onto the document.
     *
     * @param  array{signature:string,method?:string,signer_name?:?string,signer_email?:?string,date?:?string,typed_font?:?string,fields?:?array}  $payload
     * @return array{signed_document_path:string,signature_path:string,document_hash:?string}
     */
    public function complete(EsignRequest $request, array $payload, ?string $ip = null, ?string $userAgent = null): array
    {
        if (! $request->isOpen()) {
            throw new \RuntimeException('This signature request is no longer open.');
        }

        $dataUri = (string) ($payload['signature'] ?? '');
        if (! str_starts_with($dataUri, 'data:image/')) {
            throw new \InvalidArgumentException('The signature could not be read.');
        }

        $binary = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('The signature could not be read.');
        }

        // Normalise the image before it is embedded in the PDF. Browsers hand
        // back a wide variety of PNG encodings and some of them are not
        // decodable by the PDF renderer, which would silently produce a signed
        // document with no signature on it. Re-encoding also flattens
        // transparency onto white (transparent PNGs render as solid black in
        // PDF). Failing here is deliberate: an unsigned-looking signed document
        // is worse than an error the customer can retry.
        $binary = $this->normaliseSignatureImage($binary);

        $documentType = $request->document_type;
        $documentId = (int) $request->document_id;
        $signerName = $payload['signer_name'] ?? $request->signer_name;
        $signerEmail = $payload['signer_email'] ?? $request->signer_email;
        $signedOn = $payload['date'] ?? null;
        $fields = $this->normaliseFields($payload['fields'] ?? null);
        $method = in_array($payload['method'] ?? null, ['draw', 'type', 'upload'], true)
            ? $payload['method']
            : EsignRequest::METHOD_DRAW;

        return DB::transaction(function () use ($request, $binary, $dataUri, $documentType, $documentId, $signerName, $signerEmail, $signedOn, $fields, $method, $ip, $userAgent) {
            $signaturePath = "esign/{$request->id}/signature-".Str::random(8).'.png';
            Storage::disk('public')->put($signaturePath, $binary);

            // The unsigned hash proves what the customer was shown; captured at
            // request time, refreshed here for documents edited since.
            $documentHash = $request->document_hash ?: $this->unsignedHash($documentType, $documentId, $request->template_id);

            $signedAt = now();
            $signedPath = $this->renderSignedDocument($request, [
                'data_uri' => $dataUri,
                'signature_binary' => $binary,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signed_at' => $signedAt->toDateTimeString(),
                'date' => $signedOn,
                'method' => $method,
                'ip' => $ip,
                'document_hash' => $documentHash,
            ], $fields);

            $request->update([
                'status' => EsignRequest::STATUS_COMPLETED,
                'signature_method' => $method,
                'signature_path' => $signaturePath,
                'signed_document_path' => $signedPath,
                'signature_hash' => hash('sha256', $binary),
                'document_hash' => $documentHash,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'signer_ip' => $ip,
                'signer_user_agent' => $userAgent,
                'signed_at' => $signedAt,
                'meta' => array_filter([
                    'typed_font' => $payload['typed_font'] ?? null,
                    'signed_on' => $signedOn,
                ]),
            ]);

            $this->mirrorOntoDocument($request->fresh(), $signedPath, $signerName, $signerEmail, $ip);

            return [
                'signed_document_path' => $signedPath,
                'signature_path' => $signaturePath,
                'document_hash' => $documentHash,
            ];
        });
    }

    /**
     * Record a physically signed (paper) copy uploaded through the public
     * signing link instead of signing on the page. Stored and mirrored
     * distinctly from a digital signature, so the document's record shows which
     * path the customer chose.
     *
     * @return array{uploaded_document_path:string}
     */
    public function uploadSignedCopy(
        EsignRequest $request,
        UploadedFile $file,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        if (! $request->isOpen()) {
            throw new \RuntimeException('This signing link is no longer open.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $path = "esign/{$request->id}/uploaded-".Str::random(8).'.'.$extension;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $signerName = $signerName ?: $request->signer_name;
        $signerEmail = $signerEmail ?: $request->signer_email;

        $request->update([
            'status' => EsignRequest::STATUS_COMPLETED,
            'signed_document_path' => $path,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'signer_ip' => $ip,
            'signer_user_agent' => $userAgent,
            'signed_at' => now(),
            'meta' => array_merge($request->meta ?? [], [
                'completion' => 'uploaded_document',
                'uploaded_file_name' => $file->getClientOriginalName(),
            ]),
        ]);

        $this->mirrorUploadedCopyOntoDocument(
            $request->fresh(),
            $path,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            (int) $file->getSize(),
            $signerName,
            $signerEmail,
            $ip,
        );

        return ['uploaded_document_path' => $path];
    }

    /**
     * Put the uploaded signed copy on the document record, clearly labelled so
     * it is distinguishable from an electronic signature: delivery orders carry
     * their signed_* fields + 'physical' confirmation; invoices get a
     * customer_acceptance evidence row. Both get the file in chatter.
     */
    private function mirrorUploadedCopyOntoDocument(
        EsignRequest $request,
        string $path,
        ?string $originalName,
        ?string $mimeType,
        int $sizeBytes,
        ?string $signerName,
        ?string $signerEmail,
        ?string $ip,
    ): void {
        try {
            if ($request->document_type === 'delivery_order') {
                $order = DeliveryOrder::find($request->document_id);
                $order?->update([
                    'confirmation_method' => 'physical',
                    'signed_document_path' => $path,
                    'signed_document_uploaded_at' => now(),
                    'signed_by_name' => $signerName,
                    'signed_by_email' => $signerEmail,
                    'signed_at' => now(),
                ]);
            }

            if ($request->document_type === 'invoice') {
                $invoice = Invoice::find($request->document_id);
                if ($invoice) {
                    app(InvoiceEvidenceService::class)->recordUploadedSignedCopy(
                        $invoice,
                        $path,
                        $originalName,
                        $mimeType,
                        $sizeBytes,
                        [
                            'signer_name' => $signerName,
                            'signer_email' => $signerEmail,
                            'signer_ip' => $ip,
                        ],
                    );
                }
            }

            $model = self::DOCUMENT_TYPES[$request->document_type] ?? null;
            if ($model) {
                $this->chatter->registerAttachment(
                    $model,
                    (int) $request->document_id,
                    'uploaded-signed-'.$this->safeDocumentLabel($request).'.'.pathinfo($path, PATHINFO_EXTENSION),
                    $mimeType ?: 'application/pdf',
                    $sizeBytes,
                    'public',
                    $path,
                    null,
                    $signerName ?: 'Customer',
                    // Issue #630: the signed copy is the evidence of this
                    // e-signature request — chatter must never destroy it.
                    EsignRequest::class,
                    (int) $request->id,
                );

                $this->chatter->logEvent(
                    $model,
                    (int) $request->document_id,
                    'signed',
                    'Signed copy uploaded by '.($signerName ?: 'the customer').($signerEmail ? " ({$signerEmail})" : ''),
                    ['esign_request_id' => $request->id, 'completion' => 'uploaded_document'],
                );
            }
        } catch (\Throwable $e) {
            // Never fail a received upload because mirroring hit a snag.
            Log::error('EsignService: failed to mirror the uploaded signed copy', [
                'esign_request_id' => $request->id,
                'document_type' => $request->document_type,
                'document_id' => $request->document_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-encode the incoming signature as a plain opaque PNG so the PDF
     * renderer always embeds it.
     */
    private function normaliseSignatureImage(string $binary): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $binary;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new \InvalidArgumentException('The signature image could not be read.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        // Trim the surrounding whitespace to the ink bounds so the signature
        // fills the signing field instead of floating in an empty canvas.
        $bounds = $this->inkBounds($canvas, $width, $height);
        if ($bounds !== null) {
            [$minX, $minY, $maxX, $maxY] = $bounds;
            $padding = 4;
            $minX = max(0, $minX - $padding);
            $minY = max(0, $minY - $padding);
            $maxX = min($width - 1, $maxX + $padding);
            $maxY = min($height - 1, $maxY + $padding);
            $trimmedWidth = $maxX - $minX + 1;
            $trimmedHeight = $maxY - $minY + 1;

            $trimmed = imagecreatetruecolor($trimmedWidth, $trimmedHeight);
            imagefill($trimmed, 0, 0, imagecolorallocate($trimmed, 255, 255, 255));
            imagecopy($trimmed, $canvas, 0, 0, $minX, $minY, $trimmedWidth, $trimmedHeight);
            imagedestroy($canvas);
            $canvas = $trimmed;
        }

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        return $png !== '' ? $png : $binary;
    }

    /**
     * Bounding box of the non-white pixels, or null when the image is blank.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function inkBounds(\GdImage $image, int $width, int $height): ?array
    {
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;

                if ($red < 240 || $green < 240 || $blue < 240) {
                    if ($x < $minX) {
                        $minX = $x;
                    }
                    if ($x > $maxX) {
                        $maxX = $x;
                    }
                    if ($y < $minY) {
                        $minY = $y;
                    }
                    if ($y > $maxY) {
                        $maxY = $y;
                    }
                }
            }
        }

        return $maxX >= 0 ? [$minX, $minY, $maxX, $maxY] : null;
    }

    /**
     * Render the signed document. When the signer supplied field positions (the
     * signature line / date line on the document), the signature is stamped onto
     * the page at those exact coordinates — like DocuSign — instead of appended
     * as a block after the body.
     *
     * @param  array{data_uri:string,signature_binary:string,signer_name?:?string,signer_email?:?string,signed_at?:?string,date?:?string,method?:?string,ip?:?string,document_hash?:?string}  $signature
     * @param  array<int, array{kind:string,page:int,x:float,y:float,width:float,height:float}>|null  $fields
     */
    private function renderSignedDocument(EsignRequest $request, array $signature, ?array $fields = null): string
    {
        $path = "esign/{$request->id}/signed-".$this->safeDocumentLabel($request).'-'.Str::random(6).'.pdf';

        if ($fields !== null && $fields !== []) {
            $unsigned = app(PdfGenerationServiceContract::class)->render(
                $request->document_type,
                (string) $request->document_id,
                array_filter(['template_id' => $request->template_id, 'format' => 'pdf'], fn ($v) => $v !== null),
            );

            $stamped = $this->stampFields((string) ($unsigned['bytes'] ?? ''), $fields, (string) $signature['signature_binary'], $signature['date'] ?? null, $signature['signer_name'] ?? null);
            Storage::disk('public')->put($path, $stamped);

            return $path;
        }

        $pdf = app(PdfGenerationServiceContract::class)->render(
            $request->document_type,
            (string) $request->document_id,
            array_filter([
                'template_id' => $request->template_id,
                'format' => 'pdf',
                'signature' => $signature,
            ], fn ($v) => $v !== null),
        );

        Storage::disk('public')->put($path, $pdf['bytes']);

        return $path;
    }

    /**
     * Overlay the signature image and date onto the generated PDF at the field
     * coordinates the signer saw, using FPDI/TCPDF (import each page, then draw
     * on top). Coordinates are in PDF points, origin at the top-left of the page.
     *
     * @param  array<int, array{kind:string,page:int,x:float,y:float,width:float,height:float}>  $fields
     */
    private function stampFields(string $unsignedPdfBytes, array $fields, string $signatureBinary, ?string $dateText, ?string $nameText = null): string
    {
        $tmpIn = tempnam(sys_get_temp_dir(), 'esign').'.pdf';
        $tmpSig = tempnam(sys_get_temp_dir(), 'esign').'.png';
        file_put_contents($tmpIn, $unsignedPdfBytes);
        file_put_contents($tmpSig, $signatureBinary);

        try {
            $pdf = new SignatureStampPdf('P', 'pt', 'A4', true, 'UTF-8', false);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            $pageCount = $pdf->setSourceFile($tmpIn);
            $fieldsByPage = [];
            foreach ($fields as $field) {
                $fieldsByPage[max(1, (int) ($field['page'] ?? 1))][] = $field;
            }

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $orientation = $size['orientation'] ?? 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);

                foreach ($fieldsByPage[$pageNo] ?? [] as $field) {
                    $kind = $field['kind'] ?? '';
                    $x = (float) ($field['x'] ?? 0);
                    $y = (float) ($field['y'] ?? 0);
                    $width = max(1, (float) ($field['width'] ?? 0));
                    $height = max(1, (float) ($field['height'] ?? 0));

                    if ($kind === 'signature') {
                        $dimensions = @getimagesize($tmpSig);
                        if (is_array($dimensions) && ($dimensions[0] ?? 0) > 0 && ($dimensions[1] ?? 0) > 0) {
                            // Fit the signature inside the field box without
                            // distorting it, anchored to the bottom-left like a
                            // signature written on the line.
                            $aspect = $dimensions[0] / $dimensions[1];
                            $drawHeight = $height;
                            $drawWidth = $drawHeight * $aspect;
                            if ($drawWidth > $width) {
                                $drawWidth = $width;
                                $drawHeight = $drawWidth / $aspect;
                            }
                            $pdf->Image($tmpSig, $x, $y + $height - $drawHeight, $drawWidth, $drawHeight, 'PNG');
                        } else {
                            $pdf->Image($tmpSig, $x, $y, $width, $height, 'PNG');
                        }
                    } elseif ($kind === 'date' && $dateText !== null && $dateText !== '') {
                        $pdf->SetFont('helvetica', '', 11);
                        $pdf->SetTextColor(17, 24, 39);
                        $pdf->Text($x + 2, $y + $height * 0.62, (string) $dateText);
                    } elseif ($kind === 'name' && $nameText !== null && $nameText !== '') {
                        $pdf->SetFont('helvetica', '', 11);
                        $pdf->SetTextColor(17, 24, 39);
                        $pdf->Text($x + 2, $y + $height * 0.62, (string) $nameText);
                    }
                }
            }

            return (string) $pdf->Output('', 'S');
        } finally {
            @unlink($tmpIn);
            @unlink($tmpSig);
        }
    }

    /** Whitelist and coerce the field payload so only sane values reach the stamper. */
    private function normaliseFields(?array $fields): ?array
    {
        if (! is_array($fields) || $fields === []) {
            return null;
        }

        $normalised = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! in_array($field['kind'] ?? null, ['signature', 'date', 'name'], true)) {
                continue;
            }

            $normalised[] = [
                'kind' => $field['kind'],
                'page' => max(1, (int) ($field['page'] ?? 1)),
                'x' => (float) ($field['x'] ?? 0),
                'y' => (float) ($field['y'] ?? 0),
                'width' => (float) ($field['width'] ?? 0),
                'height' => (float) ($field['height'] ?? 0),
            ];
        }

        return $normalised === [] ? null : $normalised;
    }

    private function unsignedHash(string $documentType, int $documentId, ?int $templateId): ?string
    {
        try {
            $built = app(PdfGenerationServiceContract::class)->render(
                $documentType,
                (string) $documentId,
                array_filter(['template_id' => $templateId, 'format' => 'pdf'], fn ($v) => $v !== null),
            );

            return hash('sha256', (string) ($built['bytes'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('EsignService: could not hash the unsigned document', [
                'document_type' => $documentType,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Put the signed copy on the document itself, so the existing screens work:
     * delivery orders carry signed_* fields; invoices get a customer-esign
     * evidence row. Every type also gets the PDF in its chatter.
     */
    private function mirrorOntoDocument(EsignRequest $request, string $signedPath, ?string $signerName, ?string $signerEmail, ?string $ip): void
    {
        try {
            if ($request->document_type === 'delivery_order') {
                $order = DeliveryOrder::find($request->document_id);
                $order?->update([
                    'signed_document_path' => $signedPath,
                    'signed_document_uploaded_at' => now(),
                    'signed_by_name' => $signerName,
                    'signed_by_email' => $signerEmail,
                    'signed_at' => now(),
                ]);
            }

            if ($request->document_type === 'invoice') {
                $invoice = Invoice::find($request->document_id);
                if ($invoice) {
                    app(InvoiceEvidenceService::class)->recordEsignCompletion(
                        $invoice,
                        'Built-in',
                        $request->token,
                        [
                            'signer_name' => $signerName,
                            'signer_email' => $signerEmail,
                            'signer_ip' => $ip,
                        ],
                        $signedPath,
                    );
                }
            }

            // Uniform across types: the signed PDF shows up in the document's
            // chatter, so it is reachable even where no evidence model exists.
            $model = self::DOCUMENT_TYPES[$request->document_type] ?? null;
            if ($model) {
                $this->chatter->registerAttachment(
                    $model,
                    (int) $request->document_id,
                    'signed-'.$this->safeDocumentLabel($request).'.pdf',
                    'application/pdf',
                    (int) (Storage::disk('public')->size($signedPath) ?: 0),
                    'public',
                    $signedPath,
                    null,
                    $signerName ?: 'Customer',
                    // Issue #630: the signed PDF is the evidence of this
                    // e-signature request — chatter must never destroy it.
                    EsignRequest::class,
                    (int) $request->id,
                );

                $this->chatter->logEvent(
                    $model,
                    (int) $request->document_id,
                    'signed',
                    'Signed electronically by '.($signerName ?: 'the customer').($signerEmail ? " ({$signerEmail})" : ''),
                    ['esign_request_id' => $request->id, 'signed_at' => $request->signed_at?->toIso8601String()],
                );
            }
        } catch (\Throwable $e) {
            // Never fail a completed signature because mirroring hit a snag.
            Log::error('EsignService: failed to mirror signed document onto the record', [
                'esign_request_id' => $request->id,
                'document_type' => $request->document_type,
                'document_id' => $request->document_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function documentNumber(string $documentType, mixed $model): ?string
    {
        return match ($documentType) {
            'invoice' => $model->invoice_number ?? null,
            'delivery_order' => $model->delivery_order_number ?? null,
            'quotation' => $model->quotation_number ?? null,
            default => null,
        };
    }

    /** A filename-safe label: numbers like DO2026/04/007 contain slashes. */
    private function safeDocumentLabel(EsignRequest $request): string
    {
        $label = (string) ($request->document_number ?: $request->document_id);
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $label), '-');

        return $safe !== '' ? $safe : (string) $request->document_id;
    }
}
