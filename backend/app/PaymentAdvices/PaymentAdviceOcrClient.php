<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use Illuminate\Support\Facades\Http;

/**
 * #1001: image advice OCR through the shared ocr-service (the same service
 * the bookkeeping ingestion uses). Returns raw text for the bank parsers.
 */
class PaymentAdviceOcrClient
{
    public function text(string $filePath, string $fileName): string
    {
        $url = rtrim((string) config('ocr.service.url', env('OCR_SERVICE_URL', 'http://localhost:8000')), '/').'/api/ocr/raw';
        $serviceKey = (string) config('ocr.service.key', env('OCR_SERVICE_KEY', ''));

        if ($serviceKey === '') {
            throw new \RuntimeException('OCR_SERVICE_KEY is not configured — refusing to call the OCR service without authentication.');
        }

        $contents = (string) file_get_contents($filePath);

        if ($contents === '') {
            throw new \RuntimeException('The payment advice file is empty.');
        }

        $response = Http::withHeaders(['X-OCR-Service-Key' => $serviceKey])
            ->timeout((int) config('ocr.service.timeout', 60))
            ->attach('file', $contents, $fileName)
            ->post($url);

        $response->throw();

        return (string) ($response->json('text') ?? '');
    }
}
