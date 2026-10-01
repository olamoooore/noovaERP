<?php

namespace Modules\IoT\Services;

/**
 * Service for generating ESC/POS commands for receipt printers.
 *
 * ESC/POS is the standard command language for thermal receipt printers.
 */
class ReceiptGeneratorService
{
    // ESC/POS Control Characters
    private const ESC = "\x1B";

    private const GS = "\x1D";

    // Initialize printer
    private const INIT = "\x1B\x40";

    // Text alignment
    private const ALIGN_LEFT = "\x1B\x61\x00";

    private const ALIGN_CENTER = "\x1B\x61\x01";

    private const ALIGN_RIGHT = "\x1B\x61\x02";

    // Text styles
    private const TEXT_NORMAL = "\x1B\x21\x00";

    private const TEXT_BOLD = "\x1B\x21\x08";

    private const TEXT_LARGE = "\x1B\x21\x30";

    private const TEXT_DOUBLE_HEIGHT = "\x1B\x21\x10";

    private const TEXT_DOUBLE_WIDTH = "\x1B\x21\x20";

    // Paper cut
    private const CUT_FULL = "\x1D\x56\x00";

    private const CUT_PARTIAL = "\x1D\x56\x01";

    // Line feed
    private const LF = "\n";

    /**
     * Generate ESC/POS commands for a sales receipt.
     */
    public function generateSalesReceipt(array $data): string
    {
        $storeName = $data['store_name'] ?? 'NoovaERP Store';
        $items = $data['items'] ?? [];
        $subtotal = $data['subtotal'] ?? 0;
        $tax = $data['tax'] ?? 0;
        $total = $data['total'] ?? 0;
        // MRD FR-9.1: currency-aware receipt; foreign orders append the
        // frozen base equivalent (document currency primary).
        $symbol = (string) ($data['currency_symbol'] ?? '$');
        $baseSymbol = (string) ($data['base_symbol'] ?? '');
        $baseCurrency = (string) ($data['base_currency_code'] ?? '');
        $currency = (string) ($data['currency_code'] ?? '');
        $exchangeRate = (float) ($data['exchange_rate'] ?? 1.0);
        $baseTotal = isset($data['base_total']) ? (float) $data['base_total'] : null;
        $showBase = $baseCurrency !== '' && $currency !== '' && $currency !== $baseCurrency && $baseTotal !== null;
        $paymentMethod = $data['payment_method'] ?? 'Cash';
        $paymentAmount = $data['payment_amount'] ?? $total;
        $change = $data['change'] ?? 0;
        $receiptNumber = $data['receipt_number'] ?? '';
        $cashier = $data['cashier'] ?? '';
        $date = $data['date'] ?? date('Y-m-d H:i:s');

        $commands = self::INIT;

        // Header - Store name (centered, bold, large)
        $commands .= self::ALIGN_CENTER;
        $commands .= self::TEXT_LARGE;
        $commands .= $storeName.self::LF;
        $commands .= self::TEXT_NORMAL;
        $commands .= self::LF;

        // Receipt info (centered)
        if ($receiptNumber) {
            $commands .= "Receipt #: {$receiptNumber}".self::LF;
        }
        $commands .= $date.self::LF;
        if ($cashier) {
            $commands .= "Cashier: {$cashier}".self::LF;
        }
        $commands .= self::LF;

        // Separator
        $commands .= self::ALIGN_LEFT;
        $commands .= str_repeat('-', 42).self::LF;

        // Items
        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;
            $lineTotal = $quantity * $price;

            // Item name
            $commands .= $this->truncateText($name, 42).self::LF;

            // Quantity x Price = Total
            $qtyPrice = sprintf('%d x $%.2f', $quantity, $price);
            $totalStr = sprintf($symbol.'%.2f', $lineTotal);
            $commands .= $this->formatLine($qtyPrice, $totalStr, 42).self::LF;
        }

        // Separator
        $commands .= str_repeat('-', 42).self::LF;

        // Totals (right-aligned values)
        $commands .= $this->formatLine('Subtotal:', sprintf($symbol.'%.2f', $subtotal), 42).self::LF;

        if ($tax > 0) {
            $commands .= $this->formatLine('Tax:', sprintf($symbol.'%.2f', $tax), 42).self::LF;
        }

        $commands .= self::TEXT_BOLD;
        $commands .= $this->formatLine('TOTAL:', sprintf($symbol.'%.2f', $total), 42).self::LF;
        if ($showBase) {
            $rateText = rtrim(rtrim(number_format($exchangeRate, 6, '.', ''), '0'), '.');
            $commands .= $this->formatLine('Base total:', sprintf($baseSymbol.'%.2f', $baseTotal), 42).self::LF;
            $commands .= $this->formatLine('Rate:', '1 '.$currency.' = '.$rateText.' '.$baseCurrency, 42).self::LF;
        }
        $commands .= self::TEXT_NORMAL;

        $commands .= self::LF;

        // Payment info
        $commands .= $this->formatLine("Payment ({$paymentMethod}):", sprintf($symbol.'%.2f', $paymentAmount), 42).self::LF;

        if ($change > 0) {
            $commands .= $this->formatLine('Change:', sprintf($symbol.'%.2f', $change), 42).self::LF;
        }

        $commands .= self::LF;

        // Footer (centered)
        $commands .= self::ALIGN_CENTER;
        $commands .= 'Thank you for your business!'.self::LF;
        $commands .= 'Please come again'.self::LF;
        $commands .= self::LF;
        $commands .= self::LF;

        // Cut paper
        $commands .= self::CUT_FULL;

        return $commands;
    }

    /**
     * Generate a test receipt.
     */
    public function generateTestReceipt(): string
    {
        $commands = self::INIT;

        // Header (centered, bold, large) — tenant name, never the ERP brand.
        $companyName = $this->companyName();
        $commands .= self::ALIGN_CENTER;
        $commands .= self::TEXT_LARGE;
        if ($companyName !== '') {
            $commands .= $companyName.self::LF;
        }
        $commands .= self::TEXT_NORMAL;
        $commands .= self::LF;

        // Title (centered, bold)
        $commands .= self::TEXT_BOLD;
        $commands .= 'Receipt Printer Test'.self::LF;
        $commands .= self::TEXT_NORMAL;
        $commands .= self::LF;

        // Date/time (centered)
        $commands .= date('Y-m-d H:i:s').self::LF;
        $commands .= self::LF;

        // Test patterns (left-aligned)
        $commands .= self::ALIGN_LEFT;
        $commands .= str_repeat('-', 42).self::LF;
        $commands .= 'Normal text'.self::LF;
        $commands .= self::TEXT_BOLD.'Bold text'.self::TEXT_NORMAL.self::LF;
        $commands .= self::TEXT_DOUBLE_HEIGHT.'Double height'.self::TEXT_NORMAL.self::LF;
        $commands .= self::TEXT_DOUBLE_WIDTH.'Double width'.self::TEXT_NORMAL.self::LF;
        $commands .= str_repeat('-', 42).self::LF;
        $commands .= self::LF;

        // Footer (centered)
        $commands .= self::ALIGN_CENTER;
        $commands .= 'Test completed successfully!'.self::LF;
        $commands .= self::LF;
        $commands .= self::LF;

        // Cut paper
        $commands .= self::CUT_FULL;

        return $commands;
    }

    /**
     * Format a line with left and right aligned text.
     * Example: "Item name                    $10.00"
     */
    private function formatLine(string $left, string $right, int $width = 42): string
    {
        $left = $this->truncateText($left, $width - strlen($right) - 1);
        $padding = $width - strlen($left) - strlen($right);

        return $left.str_repeat(' ', max(1, $padding)).$right;
    }

    /**
     * Truncate text to fit within specified width.
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3).'...';
    }

    /**
     * Convert ESC/POS commands to a format suitable for JSON transmission.
     * This escapes special characters properly.
     */
    public function encodeForJson(string $commands): string
    {
        return base64_encode($commands);
    }

    /**
     * Decode ESC/POS commands from JSON format.
     */
    public function decodeFromJson(string $encoded): string
    {
        return base64_decode($encoded);
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
}
