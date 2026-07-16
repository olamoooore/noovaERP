<?php

namespace Modules\IoT\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\IoT\Models\DeviceTemplate;

class DeviceTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds 4 default device templates for common hardware.
     */
    public function run(): void
    {
        $templates = [
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Gprinter GP-D320FX Label Printer',
                'device_type' => 'printer',
                'driver_type' => 'label-printer-raw',
                'vendor_id' => '0x0fe6',
                'product_id' => '0x811e',
                'manufacturer' => 'Gprinter',
                'model' => 'GP-D320FX',
                'default_config' => [
                    'labelWidth' => 76,
                    'labelHeight' => 50,
                    'dpi' => 203,
                    'protocol' => 'tspl',
                ],
                'capabilities' => ['print_label', 'get_status'],
                'description' => 'Gprinter GP-D320FX thermal label printer with TSPL protocol support. Ideal for shipping labels and product tags.',
                'icon' => 'printer-label',
                'is_active' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'KPOS_80 Receipt Printer',
                'device_type' => 'printer',
                'driver_type' => 'receipt-printer-raw',
                'vendor_id' => null,
                'product_id' => null,
                'manufacturer' => 'KPOS',
                'model' => 'KPOS_80',
                'default_config' => [
                    'paperWidth' => 80,
                    'protocol' => 'escpos',
                ],
                'capabilities' => ['print_receipt', 'get_status'],
                'description' => 'KPOS_80 thermal receipt printer with ESC/POS protocol. Standard 80mm paper width for receipts.',
                'icon' => 'printer-receipt',
                'is_active' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Generic ESC/POS Receipt Printer',
                'device_type' => 'printer',
                'driver_type' => 'receipt-printer-raw',
                'vendor_id' => null,
                'product_id' => null,
                'manufacturer' => 'Generic',
                'model' => 'ESC/POS',
                'default_config' => [
                    'paperWidth' => 80,
                    'protocol' => 'escpos',
                ],
                'capabilities' => ['print_receipt', 'get_status'],
                'description' => 'Generic ESC/POS compatible thermal receipt printer. Works with most ESC/POS printers from various manufacturers.',
                'icon' => 'printer-generic',
                'is_active' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Generic TSPL Label Printer',
                'device_type' => 'printer',
                'driver_type' => 'label-printer-raw',
                'vendor_id' => null,
                'product_id' => null,
                'manufacturer' => 'Generic',
                'model' => 'TSPL',
                'default_config' => [
                    'labelWidth' => 76,
                    'labelHeight' => 50,
                    'dpi' => 203,
                    'protocol' => 'tspl',
                ],
                'capabilities' => ['print_label', 'get_status'],
                'description' => 'Generic TSPL compatible thermal label printer. Works with most TSPL printers including TSC, Zebra, and others.',
                'icon' => 'printer-label-generic',
                'is_active' => true,
            ],
            // Scanner Templates
            [
                'id' => Str::uuid()->toString(),
                'name' => 'SM SM-2D QR Code Scanner',
                'device_type' => 'scanner',
                'driver_type' => 'qr-scanner-hid',
                'vendor_id' => '0xac90',
                'product_id' => '0x3002',
                'manufacturer' => 'SM',
                'model' => 'SM-2D PRODUCT HID KBW',
                'default_config' => [
                    'mode' => 'keyboard_wedge',
                    'terminator' => 'enter',
                    'parseQR' => true,
                    'maxLength' => 5000,
                ],
                'capabilities' => ['scan_qr', 'scan_barcode', 'parse_json'],
                'description' => 'SM SM-2D 2D barcode and QR code scanner with HID keyboard emulation. Supports JSON, URL, and NoovaERP product format parsing.',
                'icon' => 'scanner-qr',
                'is_active' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Generic HID Barcode Scanner',
                'device_type' => 'scanner',
                'driver_type' => 'barcode-scanner-hid',
                'vendor_id' => null,
                'product_id' => null,
                'manufacturer' => 'Generic',
                'model' => 'HID Barcode Scanner',
                'default_config' => [
                    'mode' => 'keyboard_wedge',
                    'terminator' => 'enter',
                ],
                'capabilities' => ['scan_barcode'],
                'description' => 'Generic HID barcode scanner with keyboard emulation. Works with most USB barcode scanners.',
                'icon' => 'scanner-barcode',
                'is_active' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'SaintDeem PalmVein Biometric Scanner',
                'device_type' => 'biometric',
                'driver_type' => 'palm-vein-scanner',
                'vendor_id' => null,
                'product_id' => null,
                'manufacturer' => 'SaintDeem',
                'model' => 'PalmVein',
                'default_config' => [
                    'mode' => 'biometric',
                    'captureTimeout' => 30,
                    'qualityThreshold' => 40,
                    'matchThreshold' => 70,
                ],
                'capabilities' => ['scan_palm_vein', 'get_template', 'match_template', 'get_status'],
                'description' => 'SaintDeem PalmVein biometric scanner using WinUsb. Captures palm vein templates for identity verification and authentication.',
                'icon' => 'biometric',
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            DeviceTemplate::updateOrCreate(
                ['name' => $template['name']], // Match by name
                $template
            );
        }

        $this->command->info('✅ Seeded 7 device templates successfully (4 printers, 3 scanners)!');
    }
}
