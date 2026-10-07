<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use Smalot\PdfParser\Parser;

/**
 * #1001: text extraction — PDFs use their embedded text (no OCR loss), other
 * uploads (screenshots/photos) go through the shared OCR service.
 */
class PaymentAdviceTextExtractor
{
    public function __construct(private readonly PaymentAdviceOcrClient $ocr) {}

    /**
     * @return array{text: string, extractor: string}
     */
    public function extract(string $filePath, ?string $mimeType, string $originalName): array
    {
        $isPdf = $mimeType === 'application/pdf'
            || str_ends_with(strtolower($originalName), '.pdf');

        if ($isPdf) {
            return [
                'text' => (new Parser)->parseFile($filePath)->getText(),
                'extractor' => 'pdf-text',
            ];
        }

        return [
            'text' => $this->ocr->text($filePath, $originalName),
            'extractor' => 'ocr',
        ];
    }
}
