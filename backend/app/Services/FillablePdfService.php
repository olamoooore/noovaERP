<?php

declare(strict_types=1);

namespace App\Services;

use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Smalot\PdfParser\Parser;

/**
 * Produce a genuine fillable PDF (AcroForm) from a rendered document.
 *
 * The document is rendered through its normal template, then the printed
 * signature / date lines are located by their text and native text fields are
 * stamped on top of them. The result opens in Acrobat or any browser PDF
 * viewer with real, fillable fields — no image placeholders.
 */
class FillablePdfService
{
    private const SIGNATURE_FIELD_WIDTH = 150.0;

    private const DATE_FIELD_WIDTH = 112.0;

    private const FIELD_HEIGHT = 22.0;

    /**
     * @return array{bytes:string,fields:array<int,array<string,mixed>>}
     */
    public function makeFillable(string $documentType, string $documentId, ?int $templateId = null): array
    {
        $rendered = app(PdfGenerationServiceContract::class)->render(
            $documentType,
            $documentId,
            array_filter(['template_id' => $templateId, 'format' => 'pdf'], fn ($v) => $v !== null),
        );

        $bytes = (string) ($rendered['bytes'] ?? '');
        if ($bytes === '') {
            throw new \RuntimeException("Could not render {$documentType}#{$documentId}.");
        }

        $fields = $this->detectFields($bytes);

        if ($fields !== []) {
            $bytes = $this->addFormFields($bytes, $fields, $this->companyEmail());
        }

        return ['bytes' => $bytes, 'fields' => $fields];
    }

    private function companyEmail(): string
    {
        try {
            $company = \Modules\TemplateBuilder\Services\DataSource\CompanyDataSource::forCurrentTenant();

            return (string) ($company['email'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Locate the signature / date lines inside the generated PDF via their text.
     *
     * @return array<int, array{page:int,name:string,x:float,y:float,width:float,height:float}>
     */
    public function detectFields(string $pdfBytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fill').'.pdf';
        file_put_contents($tmp, $pdfBytes);

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($tmp);
            $found = [];

            foreach ($pdf->getPages() as $pageNo => $page) {
                $pageHeight = 841.89;
                try {
                    $mediaBox = $page->get('MediaBox');
                    $elements = $mediaBox->getElements();
                    $pageHeight = (float) ($elements[3]->value - $elements[1]->value);
                } catch (\Throwable) {
                    // fall back to A4 portrait
                }

                $signature = null;
                $dates = [];

                foreach ($page->getDataTm() as $entry) {
                    $matrix = $entry[0] ?? [];
                    $text = trim((string) ($entry[1] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    $x = (float) ($matrix[4] ?? 0);
                    $y = (float) ($matrix[5] ?? 0);

                    if ($signature === null && $this->isSignatureLabel($text)) {
                        $signature = ['x' => $x, 'y' => $y];
                    }

                    if ($text === 'Date' || $text === 'Date:') {
                        $dates[] = ['x' => $x, 'y' => $y];
                    }
                }

                if ($signature !== null) {
                    $date = $dates[0] ?? null;
                    foreach ($dates as $candidate) {
                        if ($date === null || abs($candidate['y'] - $signature['y']) < abs($date['y'] - $signature['y'])) {
                            $date = $candidate;
                        }
                    }

                    $top = $pageHeight - ($signature['y'] - 8);

                    $found[] = [
                        'page' => $pageNo + 1,
                        'name' => 'received_by',
                        'x' => $signature['x'],
                        'y' => $top,
                        'width' => self::SIGNATURE_FIELD_WIDTH,
                        'height' => self::FIELD_HEIGHT,
                    ];

                    if ($date !== null) {
                        $found[] = [
                            'page' => $pageNo + 1,
                            'name' => 'date',
                            'x' => $date['x'],
                            'y' => $pageHeight - ($date['y'] - 8),
                            'width' => self::DATE_FIELD_WIDTH,
                            'height' => self::FIELD_HEIGHT,
                        ];
                    }
                }
            }

            return $found;
        } finally {
            @unlink($tmp);
        }
    }

    private function isSignatureLabel(string $text): bool
    {
        if (str_contains($text, 'Delivered by')) {
            return false;
        }

        return str_contains($text, 'Received by')
            || str_contains($text, 'Signature')
            || str_contains($text, 'Signed')
            || str_contains($text, 'Authorized');
    }

    /**
     * Re-export the document with native text fields placed on the detected lines,
     * plus a submit button and (Acrobat-only) date formatting.
     *
     * @param  array<int, array{page:int,name:string,x:float,y:float,width:float,height:float}>  $fields
     */
    private function addFormFields(string $pdfBytes, array $fields, string $companyEmail): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fill').'.pdf';
        file_put_contents($tmp, $pdfBytes);

        try {
            $pdf = new SignatureStampPdf('P', 'pt', 'A4', true, 'UTF-8', false);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            $today = now()->format('d/m/Y');

            $pageCount = $pdf->setSourceFile($tmp);
            $fieldsByPage = [];
            foreach ($fields as $field) {
                $fieldsByPage[max(1, (int) $field['page'])][] = $field;
            }

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'] ?? 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);

                $pageFields = $fieldsByPage[$pageNo] ?? [];

                foreach ($pageFields as $field) {
                    $value = $field['name'] === 'date' ? $today : '';
                    $pdf->TextField(
                        (string) $field['name'],
                        (float) $field['width'],
                        (float) $field['height'],
                        [
                            'value' => $value,
                            'strokeColor' => [37, 99, 235],
                            'fillColor' => [235, 244, 255],
                            'fontSizePt' => 10,
                        ],
                        [],
                        (float) $field['x'],
                        (float) $field['y'],
                    );
                }

                if ($pageNo === $pageCount && $pageFields !== []) {
                    $lowest = 0.0;
                    foreach ($pageFields as $field) {
                        $lowest = max($lowest, (float) $field['y'] + (float) $field['height']);
                    }

                    $btnWidth = 110.0;
                    $btnHeight = 24.0;
                    $btnX = ($size['width'] - $btnWidth) / 2;
                    $btnY = $lowest + 18;
                    $pdf->Button(
                        'submit',
                        $btnWidth,
                        $btnHeight,
                        'Submit',
                        $companyEmail !== '' ? 'mailto:'.$companyEmail.'?subject=Completed document' : '',
                        [],
                        [],
                        $btnX,
                        $btnY,
                    );
                }
            }

            // Acrobat only: date-format actions give the date field a real
            // calendar picker. Chrome's built-in viewer ignores PDF JavaScript.
            $pdf->IncludeJS("try{var f=this.getField('date'); if(f){f.setAction('Keystroke','AFDate_KeystrokeEx(\"dd/mm/yyyy\")'); f.setAction('Format','AFDate_FormatEx(\"dd/mm/yyyy\")');}}catch(e){}");

            return (string) $pdf->Output('', 'S');
        } finally {
            @unlink($tmp);
        }
    }
}
