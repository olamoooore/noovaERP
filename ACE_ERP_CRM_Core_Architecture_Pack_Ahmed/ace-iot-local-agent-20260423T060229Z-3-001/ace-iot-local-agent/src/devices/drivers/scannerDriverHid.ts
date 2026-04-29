/**
 * HID Scanner Driver
 * 
 * Driver for HID barcode/QR scanners that work in keyboard emulation mode.
 * These scanners send scanned data as keyboard input, so no special initialization
 * is required. The driver simply marks the device as ready.
 */

import { DeviceDriver, DeviceStatus, DeviceCapability } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';

export class ScannerDriverHid implements DeviceDriver {
  private config?: DeviceConfiguration;
  private status: DeviceStatus = {
    state: 'offline',
  };

  async initialize(config: DeviceConfiguration): Promise<void> {
    this.config = config;

    logger.info('HID Scanner initialized (keyboard emulation mode)', {
      deviceId: config.id,
      deviceName: config.name,
      vendorId: config.connectionInfo?.vendorId,
      productId: config.connectionInfo?.productId,
    });

    // HID scanners work in keyboard emulation mode
    // No special initialization required
    this.status = {
      state: 'ready',
      message: 'Scanner ready (keyboard emulation mode)',
      lastActivity: new Date(),
    };
  }

  async executeCommand(action: string, data?: any): Promise<any> {
    logger.debug('HID Scanner command received', {
      deviceId: this.config?.id,
      action,
      data,
    });

    switch (action) {
      case 'get_status':
        return this.getStatus();

      case 'scan_barcode':
      case 'scan_qr':
        // HID scanners send data as keyboard input
        // The application should listen for keyboard events
        logger.info('Scan command received - waiting for keyboard input', {
          deviceId: this.config?.id,
          action,
        });
        return {
          success: true,
          message: 'Scanner ready - waiting for scan input',
        };

      default:
        logger.warn('Unknown command for HID scanner', {
          deviceId: this.config?.id,
          action,
        });
        return {
          success: false,
          error: `Unknown command: ${action}`,
        };
    }
  }

  getCapabilities(): DeviceCapability[] {
    return ['scan_barcode', 'scan_qr', 'get_status'];
  }

  getStatus(): DeviceStatus {
    return { ...this.status };
  }

  async close(): Promise<void> {
    logger.info('HID Scanner closed', {
      deviceId: this.config?.id,
    });

    this.status = {
      state: 'offline',
      message: 'Scanner closed',
      lastActivity: new Date(),
    };
  }
}
