/**
 * Driver Factory
 * 
 * Factory for dynamic driver loading and instantiation.
 * Manages driver registration and creates driver instances based on configuration.
 */

import { logger } from '../utils/logger';
import { DeviceDriver } from './interfaces/deviceDriver';
import { DeviceConfiguration } from '../config/deviceConfigManager';

/**
 * Driver constructor type
 */
type DriverConstructor = new (...args: any[]) => DeviceDriver;

/**
 * Registered driver information
 */
interface RegisteredDriver {
  driverType: string;
  driverClass: DriverConstructor;
  description?: string;
}

/**
 * Driver Factory
 * 
 * Manages driver registration and creates driver instances dynamically.
 * 
 * @example
 * ```typescript
 * const factory = new DriverFactory();
 * 
 * // Register a driver
 * factory.registerDriver('label-printer-raw', LabelPrinterDriverRaw, 'Windows RAW label printer');
 * 
 * // Create a driver instance
 * const driver = await factory.createDriver('label-printer-raw', config);
 * 
 * // Check if driver is available
 * if (factory.isDriverAvailable('label-printer-raw')) {
 *   // Driver is registered
 * }
 * ```
 */
export class DriverFactory {
  private drivers: Map<string, RegisteredDriver> = new Map();

  /**
   * Register a driver
   * 
   * @param driverType - Unique driver type identifier (e.g., 'label-printer-raw')
   * @param driverClass - Driver class constructor
   * @param description - Optional description of the driver
   * 
   * @example
   * ```typescript
   * factory.registerDriver(
   *   'label-printer-raw',
   *   LabelPrinterDriverRaw,
   *   'Windows RAW printing for label printers'
   * );
   * ```
   */
  registerDriver(
    driverType: string,
    driverClass: DriverConstructor,
    description?: string
  ): void {
    if (this.drivers.has(driverType)) {
      logger.warn('Driver already registered, overwriting', { driverType });
    }

    this.drivers.set(driverType, {
      driverType,
      driverClass,
      description,
    });

    logger.info('Driver registered', {
      driverType,
      description: description || 'No description',
    });
  }

  /**
   * Unregister a driver
   * 
   * @param driverType - Driver type to unregister
   */
  unregisterDriver(driverType: string): void {
    if (!this.drivers.has(driverType)) {
      logger.warn('Driver not registered', { driverType });
      return;
    }

    this.drivers.delete(driverType);
    logger.info('Driver unregistered', { driverType });
  }

  /**
   * Create a driver instance
   * 
   * @param driverType - Type of driver to create
   * @param config - Device configuration
   * @returns Initialized driver instance
   * @throws Error if driver not found or initialization fails
   * 
   * @example
   * ```typescript
   * const config = {
   *   id: 'device-001',
   *   driverType: 'label-printer-raw',
   *   connectionInfo: { printerName: 'Gprinter GP-D320FX' },
   *   driverConfig: { labelWidth: 76, labelHeight: 50 }
   * };
   * 
   * const driver = await factory.createDriver('label-printer-raw', config);
   * ```
   */
  async createDriver(
    driverType: string,
    config: DeviceConfiguration
  ): Promise<DeviceDriver> {
    logger.info('Creating driver instance', {
      driverType,
      deviceId: config.id,
      deviceName: config.name,
    });

    // Check if driver is registered
    const registeredDriver = this.drivers.get(driverType);
    if (!registeredDriver) {
      const error = `Driver not found: ${driverType}`;
      logger.error(error, {
        driverType,
        availableDrivers: Array.from(this.drivers.keys()),
      });
      throw new Error(error);
    }

    try {
      // Create driver instance
      const driver = new registeredDriver.driverClass();

      // Validate driver implements required interface
      this.validateDriverInterface(driver, driverType);

      // Initialize driver with configuration
      logger.debug('Initializing driver', {
        driverType,
        deviceId: config.id,
      });

      await driver.initialize(config);

      logger.info('Driver instance created successfully', {
        driverType,
        deviceId: config.id,
        capabilities: driver.getCapabilities(),
      });

      return driver;
    } catch (error: any) {
      logger.error('Failed to create driver instance', {
        driverType,
        deviceId: config.id,
        error: error.message,
      });
      throw new Error(`Failed to create driver ${driverType}: ${error.message}`);
    }
  }

  /**
   * Check if a driver is available
   * 
   * @param driverType - Driver type to check
   * @returns True if driver is registered
   */
  isDriverAvailable(driverType: string): boolean {
    return this.drivers.has(driverType);
  }

  /**
   * Get list of registered driver types
   * 
   * @returns Array of registered driver type identifiers
   */
  getRegisteredDriverTypes(): string[] {
    return Array.from(this.drivers.keys());
  }

  /**
   * Get information about a registered driver
   * 
   * @param driverType - Driver type
   * @returns Driver information or undefined if not found
   */
  getDriverInfo(driverType: string): RegisteredDriver | undefined {
    return this.drivers.get(driverType);
  }

  /**
   * Get all registered drivers
   * 
   * @returns Array of registered driver information
   */
  getAllDrivers(): RegisteredDriver[] {
    return Array.from(this.drivers.values());
  }

  /**
   * Validate that a driver implements the required interface
   * 
   * @param driver - Driver instance to validate
   * @param driverType - Driver type for error messages
   * @throws Error if driver doesn't implement required methods
   */
  private validateDriverInterface(driver: any, driverType: string): void {
    const requiredMethods = [
      'initialize',
      'executeCommand',
      'getCapabilities',
      'getStatus',
      'close',
    ];

    const missingMethods: string[] = [];

    for (const method of requiredMethods) {
      if (typeof driver[method] !== 'function') {
        missingMethods.push(method);
      }
    }

    if (missingMethods.length > 0) {
      const error = `Driver ${driverType} does not implement required methods: ${missingMethods.join(', ')}`;
      logger.error(error, { driverType, missingMethods });
      throw new Error(error);
    }

    logger.debug('Driver interface validated', { driverType });
  }

  /**
   * Clear all registered drivers (for testing)
   */
  clearDrivers(): void {
    this.drivers.clear();
    logger.debug('All drivers cleared');
  }

  /**
   * Get driver count (for testing/monitoring)
   */
  getDriverCount(): number {
    return this.drivers.size;
  }
}

/**
 * Global driver factory instance
 * 
 * This is a singleton instance that can be used throughout the application.
 * Drivers should be registered during application startup.
 */
export const driverFactory = new DriverFactory();

/**
 * Driver type constants
 * 
 * These constants define the standard driver types used in the system.
 */
export const DriverTypes = {
  LABEL_PRINTER_RAW: 'label-printer-raw',
  RECEIPT_PRINTER_RAW: 'receipt-printer-raw',
  TERMINAL_MOCK: 'terminal-mock',
  BARCODE_SCANNER_HID: 'barcode-scanner-hid',
  QR_SCANNER_HID: 'qr-scanner-hid',
  TERMINAL_INGENICO: 'terminal-ingenico',
  TERMINAL_VERIFONE: 'terminal-verifone',
  TERMINAL_PAX: 'terminal-pax',
} as const;

export type DriverType = typeof DriverTypes[keyof typeof DriverTypes];
