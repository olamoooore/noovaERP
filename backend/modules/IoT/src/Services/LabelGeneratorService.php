<?php

namespace Modules\IoT\Services;

/**
 * Service for generating TSPL commands for label printers.
 *
 * TSPL (TSC Printer Language) is used by many thermal label printers
 * including Gprinter, TSC, and Zebra compatible printers.
 */
class LabelGeneratorService
{
    /**
     * Default label configuration
     */
    private array $defaultConfig = [
        'labelWidth' => 76,    // mm
        'labelHeight' => 50,   // mm
        'gap' => 3,            // mm between labels
        'dpi' => 203,          // dots per inch (8 dots/mm)
        'speed' => 4,          // print speed
        'density' => 8,        // print darkness (0-15)
    ];

    /**
     * Generate TSPL commands for a product label.
     * Layout: Product Name -> SKU -> Price -> Barcode (at bottom)
     */
    public function generateProductLabel(array $data, array $config = []): string
    {
        $config = array_merge($this->defaultConfig, $config);
        $dotsPerMm = $config['dpi'] / 25.4; // ~8 dots per mm for 203 DPI

        $productName = $data['productName'] ?? 'Product';
        $barcode = $data['barcode'] ?? '';
        $price = $data['price'] ?? '';
        $sku = $data['sku'] ?? '';
        $copies = $data['copies'] ?? 1;

        $commands = [];

        // Initialize printer
        $commands[] = "SIZE {$config['labelWidth']} mm, {$config['labelHeight']} mm";
        $commands[] = "GAP {$config['gap']} mm, 0";
        $commands[] = "SPEED {$config['speed']}";
        $commands[] = "DENSITY {$config['density']}";
        $commands[] = 'DIRECTION 1';
        $commands[] = 'CLS';

        // Calculate label dimensions in dots
        $labelWidthDots = (int) ($config['labelWidth'] * $dotsPerMm);  // ~608 dots for 76mm
        $labelHeightDots = (int) ($config['labelHeight'] * $dotsPerMm); // ~400 dots for 50mm
        $centerX = $labelWidthDots / 2;

        // Start content lower on the label - Y=240 is about 30mm from top
        $currentY = 240;

        // Product name (centered)
        $truncatedName = $this->truncateText($productName, 20);
        $nameWidth = strlen($truncatedName) * 8;
        $nameX = max(20, (int) ($centerX - $nameWidth / 2));
        $commands[] = $this->textCommand($nameX, $currentY, '2', 0, 1, 1, $truncatedName);
        $currentY += 35;

        // SKU (centered)
        if ($sku) {
            $skuText = "SKU: {$sku}";
            $skuWidth = strlen($skuText) * 6;
            $skuX = max(20, (int) ($centerX - $skuWidth / 2));
            $commands[] = $this->textCommand($skuX, $currentY, '1', 0, 1, 1, $skuText);
            $currentY += 30;
        }

        // Price (centered)
        if ($price) {
            $priceWidth = strlen($price) * 12;
            $priceX = max(20, (int) ($centerX - $priceWidth / 2));
            $commands[] = $this->textCommand($priceX, $currentY, '3', 0, 1, 1, $price);
            $currentY += 40;
        }

        // Barcode at bottom (centered)
        if ($barcode) {
            $barcodeWidth = strlen($barcode) * 18;
            $barcodeX = max(20, (int) ($centerX - $barcodeWidth / 2));
            $barcodeHeight = 50;
            $commands[] = $this->barcodeCommand($barcodeX, $currentY, '128', $barcodeHeight, 1, 0, 2, 2, $barcode);
        }

        // Print command
        $commands[] = "PRINT 1, {$copies}";

        return implode("\r\n", $commands)."\r\n";
    }

    /**
     * Generate TSPL commands for a barcode-only label.
     */
    public function generateBarcodeLabel(array $data, array $config = []): string
    {
        $config = array_merge($this->defaultConfig, $config);
        $dotsPerMm = $config['dpi'] / 25.4;

        $barcode = $data['barcode'] ?? '';
        $barcodeType = $data['barcodeType'] ?? 'CODE128';
        $text = $data['text'] ?? '';
        $copies = $data['copies'] ?? 1;

        // Calculate label dimensions in dots
        $labelWidthDots = (int) ($config['labelWidth'] * $dotsPerMm);
        $labelHeightDots = (int) ($config['labelHeight'] * $dotsPerMm);
        $centerX = $labelWidthDots / 2;

        $commands = [];

        // Initialize printer
        $commands[] = "SIZE {$config['labelWidth']} mm, {$config['labelHeight']} mm";
        $commands[] = "GAP {$config['gap']} mm, 0";
        $commands[] = "SPEED {$config['speed']}";
        $commands[] = "DENSITY {$config['density']}";
        $commands[] = 'DIRECTION 1';
        $commands[] = 'CLS';

        $currentY = 40;

        // Optional text above barcode (centered)
        if ($text) {
            $truncatedText = $this->truncateText($text, 20);
            $textWidth = strlen($truncatedText) * 10;
            $textX = max(20, (int) ($centerX - $textWidth / 2));
            $commands[] = $this->textCommand($textX, $currentY, '2', 0, 1, 1, $truncatedText);
            $currentY += 40;
        }

        // Barcode (centered)
        if ($barcode) {
            $tsplBarcodeType = $this->mapBarcodeType($barcodeType);

            if ($barcodeType === 'QR') {
                // QR Code - centered
                $qrSize = 120; // Approximate QR size
                $qrX = (int) ($centerX - $qrSize / 2);
                $commands[] = "QRCODE {$qrX},{$currentY},H,5,A,0,\"{$barcode}\"";
            } else {
                // Linear barcode - centered
                $barcodeWidth = strlen($barcode) * 22;
                $barcodeX = max(20, (int) ($centerX - $barcodeWidth / 2));
                $barcodeHeight = 70;
                $commands[] = $this->barcodeCommand($barcodeX, $currentY, $tsplBarcodeType, $barcodeHeight, 1, 0, 2, 2, $barcode);
            }
        }

        // Print command
        $commands[] = "PRINT 1, {$copies}";

        return implode("\r\n", $commands)."\r\n";
    }

    /**
     * Generate TSPL commands for a shipping label.
     */
    public function generateShippingLabel(array $data, array $config = []): string
    {
        $config = array_merge($this->defaultConfig, $config);

        $fromName = $data['fromName'] ?? '';
        $fromAddress = $data['fromAddress'] ?? '';
        $toName = $data['toName'] ?? '';
        $toAddress = $data['toAddress'] ?? '';
        $trackingNumber = $data['trackingNumber'] ?? '';
        $copies = $data['copies'] ?? 1;

        $commands = [];

        // Initialize printer
        $commands[] = "SIZE {$config['labelWidth']} mm, {$config['labelHeight']} mm";
        $commands[] = "GAP {$config['gap']} mm, 0";
        $commands[] = "SPEED {$config['speed']}";
        $commands[] = "DENSITY {$config['density']}";
        $commands[] = 'DIRECTION 1';
        $commands[] = 'CLS';

        // From section
        $commands[] = $this->textCommand(20, 20, '1', 0, 1, 1, 'FROM:');
        $commands[] = $this->textCommand(20, 40, '2', 0, 1, 1, $this->truncateText($fromName, 25));
        $commands[] = $this->textCommand(20, 65, '1', 0, 1, 1, $this->truncateText($fromAddress, 35));

        // Separator line
        $commands[] = 'BAR 20, 100, 400, 2';

        // To section
        $commands[] = $this->textCommand(20, 115, '1', 0, 1, 1, 'TO:');
        $commands[] = $this->textCommand(20, 135, '3', 0, 1, 1, $this->truncateText($toName, 20));
        $commands[] = $this->textCommand(20, 175, '2', 0, 1, 1, $this->truncateText($toAddress, 30));

        // Tracking barcode at bottom
        if ($trackingNumber) {
            $commands[] = $this->barcodeCommand(20, 230, '128', 50, 1, 0, 2, 2, $trackingNumber);
        }

        // Print command
        $commands[] = "PRINT 1, {$copies}";

        return implode("\r\n", $commands)."\r\n";
    }

    /**
     * Generate a test label to verify printer is working.
     */
    public function generateTestLabel(array $config = []): string
    {
        $config = array_merge($this->defaultConfig, $config);
        $dotsPerMm = $config['dpi'] / 25.4;

        // Calculate label dimensions in dots
        $labelWidthDots = (int) ($config['labelWidth'] * $dotsPerMm);
        $labelHeightDots = (int) ($config['labelHeight'] * $dotsPerMm);
        $centerX = $labelWidthDots / 2;

        $commands = [];

        // Initialize printer
        $commands[] = "SIZE {$config['labelWidth']} mm, {$config['labelHeight']} mm";
        $commands[] = "GAP {$config['gap']} mm, 0";
        $commands[] = "SPEED {$config['speed']}";
        $commands[] = "DENSITY {$config['density']}";
        $commands[] = 'DIRECTION 1';
        $commands[] = 'CLS';

        // Calculate total content height: title(30) + gap(30) + subtitle(25) + gap(25) + date(20) + gap(30) + barcode(70) = ~230
        $contentHeight = 230;
        $startY = (int) (($labelHeightDots - $contentHeight) / 2);
        $startY = max(25, $startY);

        // Title - centered — tenant name, never the ERP brand.
        $title = $this->companyName() ?: 'Label Test';
        $titleX = (int) ($centerX - strlen($title) * 8);
        $commands[] = $this->textCommand($titleX, $startY, '3', 0, 1, 1, $title);

        // Subtitle - centered
        $subtitle = 'Label Printer Test';
        $subtitleX = (int) ($centerX - strlen($subtitle) * 5);
        $commands[] = $this->textCommand($subtitleX, $startY + 55, '2', 0, 1, 1, $subtitle);

        // Date - centered
        $dateStr = date('Y-m-d H:i');
        $dateX = (int) ($centerX - strlen($dateStr) * 4);
        $commands[] = $this->textCommand($dateX, $startY + 105, '1', 0, 1, 1, $dateStr);

        // Test barcode - centered
        $testBarcode = 'TEST123';
        $barcodeWidth = strlen($testBarcode) * 18;
        $barcodeX = (int) ($centerX - $barcodeWidth / 2);
        $commands[] = $this->barcodeCommand($barcodeX, $startY + 150, '128', 55, 1, 0, 2, 2, $testBarcode);

        // Border box with margin
        $margin = 20;
        $commands[] = "BOX {$margin}, {$margin}, ".($labelWidthDots - $margin).', '.($labelHeightDots - $margin).', 2';

        // Print command
        $commands[] = 'PRINT 1, 1';

        return implode("\r\n", $commands)."\r\n";
    }

    /**
     * Generate TEXT command.
     */
    private function textCommand(int $x, int $y, string $font, int $rotation, int $xMul, int $yMul, string $content): string
    {
        // Escape quotes in content
        $content = str_replace('"', '\\"', $content);

        return "TEXT {$x},{$y},\"{$font}\",{$rotation},{$xMul},{$yMul},\"{$content}\"";
    }

    /**
     * Generate BARCODE command.
     */
    private function barcodeCommand(int $x, int $y, string $type, int $height, int $readable, int $rotation, int $narrow, int $wide, string $content): string
    {
        // Escape quotes in content
        $content = str_replace('"', '\\"', $content);

        return "BARCODE {$x},{$y},\"{$type}\",{$height},{$readable},{$rotation},{$narrow},{$wide},\"{$content}\"";
    }

    /**
     * Map common barcode type names to TSPL types.
     */
    private function mapBarcodeType(string $type): string
    {
        $map = [
            'CODE128' => '128',
            'CODE39' => '39',
            'EAN13' => 'EAN13',
            'EAN8' => 'EAN8',
            'UPC-A' => 'UPCA',
            'UPC-E' => 'UPCE',
            'ITF' => 'ITF14',
            'QR' => 'QRCODE', // Handled separately
        ];

        return $map[strtoupper($type)] ?? '128';
    }

    /** Tenant company name for printed headers ('' when not configured). */
    private function companyName(): string
    {
        try {
            return (string) (\Illuminate\Support\Facades\DB::table('company_settings')->value('company_name') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Truncate text to fit label width.
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3).'...';
    }
}
