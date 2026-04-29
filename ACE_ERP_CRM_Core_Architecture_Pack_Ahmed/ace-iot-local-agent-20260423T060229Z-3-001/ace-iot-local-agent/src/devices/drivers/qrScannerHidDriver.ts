/**
 * QR Scanner HID Driver
 * 
 * Driver for HID QR code scanners that work in keyboard emulation mode.
 * These scanners send scanned data as keyboard input, so no special initialization
 * is required. The driver marks the device as ready and provides QR data parsing.
 * 
 * Supports parsing:
 * - JSON data
 * - URLs
 * - NoovaERP format (NOOVAERP:PROD:SKU:QTY:N)
 * - Plain text
 */

import { DeviceDriver, DeviceStatus, DeviceCapability } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';

export interface QRParsedData {
  type: 'json' | 'url' | 'noovaerp_product' | 'text';
  data: any;
}

export class QrScannerHidDriver implements DeviceDriver {
  private config?: DeviceConfiguration;
  private status: DeviceStatus = {
    state: 'offline',
  };

  async initialize(config: DeviceConfiguration): Promise<void> {
    this.config = config;

    logger.info('QR Scanner HID initialized (keyboard emulation mode)', {
      deviceId: config.id,
      deviceName: config.name,
      vendorId: config.connectionInfo?.vendorId,
      productId: config.connectionInfo?.productId,
    });

    // HID QR scanners work in keyboard emulation mode
    // No special initialization required
    this.status = {
      state: 'ready',
      message: 'QR Scanner ready (keyboard emulation mode)',
      lastActivity: new Date(),
    };
  }

  async executeCommand(action: string, data?: any): Promise<any> {
    logger.debug('QR Scanner HID command received', {
      deviceId: this.config?.id,
      action,
      data,
    });

    switch (action) {
      case 'get_status':
        return this.getStatus();

      case 'scan_qr':
      case 'scan_barcode':
        // HID scanners send data as keyboard input
        // The application should listen for keyboard events
        logger.info('Scan command received - waiting for keyboard input', {
          deviceId: this.config?.id,
          action,
        });
        return {
          success: true,
          message: 'QR Scanner ready - waiting for scan input',
        };

      case 'parse_json':
        // Parse QR code data
        if (!data || !data.qrData) {
          return {
            success: false,
            error: 'Missing qrData parameter',
          };
        }
        return {
          success: true,
          parsed: this.parseQRData(data.qrData),
        };

      default:
        logger.warn('Unknown command for QR Scanner HID', {
          deviceId: this.config?.id,
          action,
        });
        return {
          success: false,
          error: `Unknown command: ${action}`,
        };
    }
  }

  /**
   * Parse QR code data and determine its type
   */
  parseQRData(qrData: string): QRParsedData {
    // Try JSON parsing
    if (qrData.trim().startsWith('{') || qrData.trim().startsWith('[')) {
      try {
        const parsed = JSON.parse(qrData);
        return {
          type: 'json',
          data: parsed,
        };
      } catch (e) {
        // Not valid JSON, continue to other checks
      }
    }

    // Check for URL
    if (qrData.match(/^https?:\/\//i)) {
      try {
        const url = new URL(qrData);
        return {
          type: 'url',
          data: {
            url: qrData,
            protocol: url.protocol,
            hostname: url.hostname,
            pathname: url.pathname,
            search: url.search,
            hash: url.hash,
          },
        };
      } catch (e) {
        // Not a valid URL, continue
      }
    }

    // Check for NoovaERP product format: NOOVAERP:PROD:SKU:QTY:N
    if (qrData.toUpperCase().startsWith('NOOVAERP:PROD:')) {
      const parts = qrData.split(':');
      if (parts.length >= 5) {
        return {
          type: 'noovaerp_product',
          data: {
            sku: parts[2],
            quantity: parseInt(parts[3], 10) || 1,
            variant: parts[4],
            raw: qrData,
          },
        };
      }
    }

    // Default to plain text
    return {
      type: 'text',
      data: {
        text: qrData,
        length: qrData.length,
      },
    };
  }

  getCapabilities(): DeviceCapability[] {
    return ['scan_qr', 'scan_barcode', 'get_status'];
  }

  getStatus(): DeviceStatus {
    return { ...this.status };
  }

  async close(): Promise<void> {
    logger.info('QR Scanner HID closed', {
      deviceId: this.config?.id,
    });

    this.status = {
      state: 'offline',
      message: 'QR Scanner closed',
      lastActivity: new Date(),
    };
  }
}
