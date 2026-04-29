/**
 * Driver Registration
 * 
 * This file registers all available device drivers with the DriverFactory.
 * Import and call registerAllDrivers() during application startup.
 */

import { driverFactory, DriverTypes } from './driverFactory';
import { LabelPrinterDriverRaw } from './drivers/labelDriverRaw';
import { LabelPrinterTsplDriver } from './drivers/labelPrinterTspl';
import { ReceiptPrinterDriverRaw } from './drivers/receiptDriverRaw';
import { MockTerminalDriver } from './drivers/mockTerminalDriver';
import { ScannerDriverHid } from './drivers/scannerDriverHid';
import { QrScannerHidDriver } from './drivers/qrScannerHidDriver';
import { PalmVeinWinUsbDriver } from './drivers/palmVeinWinUsbDriver';
import { logger } from '../utils/logger';

/**
 * Register all available device drivers
 * 
 * This function should be called during application startup
 * to make all drivers available for dynamic loading.
 * 
 * @example
 * ```typescript
 * import { registerAllDrivers } from './devices/registerDrivers';
 * 
 * // During app initialization
 * registerAllDrivers();
 * ```
 */
export function registerAllDrivers(): void {
  logger.info('Registering device drivers...');

  // Register label printer drivers
  driverFactory.registerDriver(
    DriverTypes.LABEL_PRINTER_RAW,
    LabelPrinterDriverRaw,
    'Windows RAW printing for TSPL label printers (Gprinter, etc.)'
  );

  driverFactory.registerDriver(
    'label-printer-tspl',
    LabelPrinterTsplDriver,
    'TSPL protocol label printer with advanced features (Gprinter GP-D320FX, TSC, etc.)'
  );

  // Register receipt printer driver
  driverFactory.registerDriver(
    DriverTypes.RECEIPT_PRINTER_RAW,
    ReceiptPrinterDriverRaw,
    'Windows RAW printing for ESC/POS receipt printers (KPOS, Epson, etc.)'
  );

  // Register mock terminal driver
  driverFactory.registerDriver(
    DriverTypes.TERMINAL_MOCK,
    MockTerminalDriver,
    'Mock payment terminal for testing without hardware'
  );

  // Register HID scanner driver (barcode/QR scanners in keyboard emulation mode)
  driverFactory.registerDriver(
    'scanner-hid',
    ScannerDriverHid,
    'HID barcode/QR scanner in keyboard emulation mode'
  );

  // Register QR scanner HID driver (dedicated QR code scanners with parsing)
  driverFactory.registerDriver(
    'qr-scanner-hid',
    QrScannerHidDriver,
    'HID QR code scanner with JSON/URL/NoovaERP format parsing'
  );

  // Register Palm Vein WinUsb driver (SaintDeem biometric scanner)
  driverFactory.registerDriver(
    'palm-vein-winusb',
    PalmVeinWinUsbDriver,
    'SaintDeem Palm Vein biometric scanner via WinUsb'
  );

  // Register palm-vein-scanner alias for backend compatibility
  driverFactory.registerDriver(
    'palm-vein-scanner',
    PalmVeinWinUsbDriver,
    'SaintDeem Palm Vein biometric scanner (alias for palm-vein-winusb)'
  );

  const registeredDrivers = driverFactory.getRegisteredDriverTypes();
  logger.info('Device drivers registered', {
    count: registeredDrivers.length,
    drivers: registeredDrivers,
  });
}

/**
 * Get information about all registered drivers
 * 
 * Useful for debugging and displaying available drivers.
 */
export function getRegisteredDriversInfo(): Array<{
  driverType: string;
  description: string;
}> {
  return driverFactory.getAllDrivers().map(driver => ({
    driverType: driver.driverType,
    description: driver.description || 'No description',
  }));
}
