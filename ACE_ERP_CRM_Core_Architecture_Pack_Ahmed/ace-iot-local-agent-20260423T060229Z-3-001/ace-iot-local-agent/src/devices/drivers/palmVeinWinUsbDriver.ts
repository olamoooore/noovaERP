/**
 * Palm Vein WinUsb Driver
 * 
 * Driver for SaintDeem PalmVein biometric scanner using WinUsb
 * VID: 0x7985, PID: 0x1001
 */

import { DeviceDriver, DeviceStatus, DeviceCapability } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';
import * as usb from 'usb';

export interface PalmVeinScanResult {
  type: 'palm_vein';
  template: string; // Base64 encoded vein template
  quality: number; // 0-100
  matchScore: number | null; // 0-100 or null if no match
  timestamp: string; // ISO-8601
}

export class PalmVeinWinUsbDriver implements DeviceDriver {
  private config?: DeviceConfiguration;
  private status: DeviceStatus = {
    state: 'offline',
  };
  private device: usb.Device | null = null;
  private isScanning = false;

  async initialize(config: DeviceConfiguration): Promise<void> {
    this.config = config;

    logger.info('Palm Vein WinUsb driver initializing', {
      deviceId: config.id,
      deviceName: config.name,
      vendorId: config.connectionInfo?.vendorId,
      productId: config.connectionInfo?.productId,
    });

    try {
      // Find the SaintDeem PalmVein device
      const vendorId = parseInt(config.connectionInfo?.vendorId || '0x7985', 16);
      const productId = parseInt(config.connectionInfo?.productId || '0x1001', 16);

      const devices = usb.getDeviceList();
      this.device = devices.find(d => 
        d.deviceDescriptor.idVendor === vendorId &&
        d.deviceDescriptor.idProduct === productId
      ) || null;

      if (!this.device) {
        throw new Error('Palm vein scanner not found');
      }

      logger.info('Palm vein scanner found', {
        deviceId: config.id,
        vendorId: `0x${vendorId.toString(16)}`,
        productId: `0x${productId.toString(16)}`,
      });

      this.status = {
        state: 'ready',
        message: 'Palm vein scanner ready',
        lastActivity: new Date(),
      };

    } catch (error: any) {
      logger.error('Failed to initialize palm vein scanner', {
        deviceId: config.id,
        error: error.message,
      });

      this.status = {
        state: 'error',
        message: `Initialization failed: ${error.message}`,
        errorCode: 'INIT_FAILED',
        lastActivity: new Date(),
      };

      throw error;
    }
  }

  async executeCommand(action: string, data?: any): Promise<any> {
    logger.debug('Palm vein scanner command received', {
      deviceId: this.config?.id,
      action,
      data,
    });

    switch (action) {
      case 'get_status':
        return this.getStatus();

      case 'scan_palm_vein':
        return await this.scanPalmVein();

      case 'get_template':
        return await this.getTemplate();

      case 'match_template':
        return await this.matchTemplate(data?.template);

      default:
        logger.warn('Unknown command for palm vein scanner', {
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
   * Scan palm vein and return template
   */
  private async scanPalmVein(): Promise<PalmVeinScanResult> {
    if (this.isScanning) {
      throw new Error('Scanner is already in use');
    }

    if (!this.device) {
      throw new Error('Device not initialized');
    }

    this.isScanning = true;
    this.status.state = 'busy';
    this.status.message = 'Scanning palm vein...';

    try {
      logger.info('Starting palm vein scan', {
        deviceId: this.config?.id,
      });

      // Open device
      try {
        this.device.open();
      } catch (error: any) {
        // Device might already be open or require special permissions
        logger.warn('Could not open device (may require admin rights)', {
          error: error.message,
        });
      }

      // For now, simulate the scan since we don't have the vendor SDK
      // In production, this would use the SaintDeem SDK to:
      // 1. Capture palm vein image
      // 2. Extract vein template
      // 3. Calculate quality score
      // 4. Optionally match against stored templates

      // Simulate scan delay
      await new Promise(resolve => setTimeout(resolve, 2000));

      // Generate mock template (in production, this comes from the SDK)
      const mockTemplate = this.generateMockTemplate();
      const quality = Math.floor(Math.random() * 30) + 70; // 70-100

      const result: PalmVeinScanResult = {
        type: 'palm_vein',
        template: mockTemplate,
        quality,
        matchScore: null, // No match attempted in scan-only mode
        timestamp: new Date().toISOString(),
      };

      logger.info('Palm vein scan completed', {
        deviceId: this.config?.id,
        quality,
      });

      this.status.state = 'ready';
      this.status.message = 'Scan completed';
      this.status.lastActivity = new Date();

      return result;

    } catch (error: any) {
      logger.error('Palm vein scan failed', {
        deviceId: this.config?.id,
        error: error.message,
      });

      this.status.state = 'error';
      this.status.message = `Scan failed: ${error.message}`;
      this.status.errorCode = 'SCAN_FAILED';

      throw error;

    } finally {
      this.isScanning = false;

      // Close device
      try {
        if (this.device) {
          this.device.close();
        }
      } catch (error) {
        // Ignore close errors
      }
    }
  }

  /**
   * Get palm vein template only
   */
  private async getTemplate(): Promise<{ template: string; quality: number }> {
    const result = await this.scanPalmVein();
    return {
      template: result.template,
      quality: result.quality,
    };
  }

  /**
   * Match palm vein against stored template
   */
  private async matchTemplate(storedTemplate?: string): Promise<PalmVeinScanResult> {
    if (!storedTemplate) {
      throw new Error('No template provided for matching');
    }

    const scanResult = await this.scanPalmVein();

    // In production, this would use the SDK to compare templates
    // For now, simulate a match score
    const matchScore = Math.floor(Math.random() * 40) + 60; // 60-100

    return {
      ...scanResult,
      matchScore,
    };
  }

  /**
   * Generate mock palm vein template
   * In production, this comes from the SaintDeem SDK
   */
  private generateMockTemplate(): string {
    // Generate a realistic-looking base64 template
    const buffer = Buffer.alloc(256);
    for (let i = 0; i < buffer.length; i++) {
      buffer[i] = Math.floor(Math.random() * 256);
    }
    return buffer.toString('base64');
  }

  getCapabilities(): DeviceCapability[] {
    return ['scan_palm_vein', 'get_status'];
  }

  getStatus(): DeviceStatus {
    return { ...this.status };
  }

  async close(): Promise<void> {
    logger.info('Palm vein scanner closing', {
      deviceId: this.config?.id,
    });

    if (this.device) {
      try {
        this.device.close();
      } catch (error) {
        // Ignore close errors
      }
      this.device = null;
    }

    this.status = {
      state: 'offline',
      message: 'Scanner closed',
      lastActivity: new Date(),
    };
  }
}
