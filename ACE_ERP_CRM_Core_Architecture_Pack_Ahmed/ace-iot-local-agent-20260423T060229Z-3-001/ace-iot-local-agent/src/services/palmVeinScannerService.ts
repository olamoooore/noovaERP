/**
 * Palm Vein Scanner Service
 * 
 * Handles palm vein biometric scanning jobs
 */

import { logger } from '../utils/logger';
import { ApiClient } from '../api/client';
import { DeviceManager } from '../devices/deviceManager';

export type ScanMode = 'single' | 'continuous';

export interface PalmVeinJob {
  deviceId: string;
  mode: ScanMode;
  timeout: number;
  startTime: number;
  isActive: boolean;
}

export class PalmVeinScannerService {
  private activeJobs = new Map<string, PalmVeinJob>();

  constructor(
    private apiClient: ApiClient,
    private deviceManager: DeviceManager,
    private agentId: string
  ) {}

  /**
   * Start palm vein scanning job
   */
  async startScanning(
    deviceId: string,
    mode: ScanMode,
    timeout: number
  ): Promise<void> {
    // Check if already scanning for this device
    if (this.activeJobs.has(deviceId)) {
      logger.warn('Palm vein scanner already active', { deviceId });
      throw new Error('Palm vein scanner already active');
    }

    console.log(`🖐️  STARTING PALM VEIN SCAN | Device: ${deviceId} | Mode: ${mode} | Timeout: ${timeout}s`);
    logger.info('Starting palm vein scan', { deviceId, mode, timeout });

    // Create job
    const job: PalmVeinJob = {
      deviceId,
      mode,
      timeout,
      startTime: Date.now(),
      isActive: true,
    };

    this.activeJobs.set(deviceId, job);

    // Start scanning process
    this.performScan(deviceId, mode, timeout);
  }

  /**
   * Perform palm vein scan
   */
  private async performScan(
    deviceId: string,
    mode: ScanMode,
    timeout: number
  ): Promise<void> {
    const job = this.activeJobs.get(deviceId);
    if (!job) return;

    try {
      console.log('🔍 Capturing palm vein...');
      
      // Get device info for debugging
      const devices = this.deviceManager.getDevices();
      const device = devices.find(d => d.id === deviceId);
      
      if (device) {
        const capabilities = device.driver?.getCapabilities ? device.driver.getCapabilities() : [];
        console.log(`📋 Device: ${device.model}, Capabilities: ${capabilities.join(', ')}`);
        
        if (!capabilities.includes('scan_palm_vein')) {
          throw new Error(`Device is using wrong driver. Expected palm-vein-winusb driver with scan_palm_vein capability, but device has: ${capabilities.join(', ')}`);
        }
      }
      
      // Execute scan command on the device
      const result = await this.deviceManager.executeCommand(
        deviceId,
        'scan_palm_vein',
        {}
      );

      console.log('✅ Palm vein captured');
      logger.info('Palm vein captured', {
        deviceId,
        quality: result.quality,
      });

      // Report to backend
      await this.reportScanResult(deviceId, result);

      // If single mode, stop after one scan
      if (mode === 'single') {
        console.log('Single mode - stopping after scan');
        this.stopScanning(deviceId);
      } else {
        // Continuous mode - scan again if still active
        if (this.activeJobs.has(deviceId)) {
          const elapsed = Date.now() - job.startTime;
          if (elapsed < timeout * 1000) {
            // Wait a bit before next scan
            setTimeout(() => {
              if (this.activeJobs.has(deviceId)) {
                this.performScan(deviceId, mode, timeout);
              }
            }, 1000);
          } else {
            this.stopScanning(deviceId);
          }
        }
      }

    } catch (error: any) {
      console.error('❌ Palm vein scan failed:', error.message);
      logger.error('Palm vein scan failed', {
        deviceId,
        error: error.message,
      });

      // Report error to backend
      await this.reportScanError(deviceId, error.message);

      // Stop on error
      this.stopScanning(deviceId);
    }
  }

  /**
   * Report scan result to backend
   */
  private async reportScanResult(deviceId: string, scanData: any): Promise<void> {
    try {
      console.log('📤 Reporting palm vein scan to backend...');

      // Check if scan data is valid
      if (!scanData || !scanData.template) {
        throw new Error('Invalid scan data: template is missing. Device may be using wrong driver.');
      }

      // Generate a short hash for the barcode field (to fit in VARCHAR(255))
      // The full template is preserved in parsed.data.template
      const crypto = require('crypto');
      const templateHash = crypto
        .createHash('sha256')
        .update(scanData.template)
        .digest('hex')
        .substring(0, 64); // Use first 64 chars of hash

      // Format the data for backend
      const payload: any = {
        device_id: deviceId,
        barcode: `PALM_VEIN_${templateHash}`, // Short hash that fits in VARCHAR(255)
        format: 'PALM_VEIN',
        timestamp: scanData.timestamp || new Date().toISOString(),
        parsed: {
          type: 'palm_vein',
          data: {
            template: scanData.template, // Full template stored here
            quality: scanData.quality,
            matchScore: scanData.matchScore,
            templateHash, // Also include hash for quick lookups
          },
        },
      };

      await this.apiClient.reportScanResult(this.agentId, payload);

      console.log('✅ Palm vein scan reported to backend successfully');
      logger.info('Palm vein scan reported to backend', {
        deviceId,
        quality: scanData.quality,
        templateHash,
      });

    } catch (error: any) {
      console.error('❌ Failed to report palm vein scan:', error.message);
      logger.error('Failed to report palm vein scan', {
        deviceId,
        error: error.message,
      });
    }
  }

  /**
   * Report scan error to backend
   */
  private async reportScanError(deviceId: string, errorMessage: string): Promise<void> {
    try {
      const payload: any = {
        device_id: deviceId,
        barcode: 'PALM_VEIN_ERROR',
        format: 'PALM_VEIN',
        timestamp: new Date().toISOString(),
        parsed: {
          type: 'palm_vein',
          data: {
            error: errorMessage,
            quality: 0,
            matchScore: null,
          },
        },
      };

      await this.apiClient.reportScanResult(this.agentId, payload);

    } catch (error: any) {
      logger.error('Failed to report palm vein error', {
        deviceId,
        error: error.message,
      });
    }
  }

  /**
   * Stop palm vein scanning
   */
  stopScanning(deviceId: string): void {
    const job = this.activeJobs.get(deviceId);
    
    if (!job) {
      logger.warn('No active palm vein scan job found', { deviceId });
      return;
    }

    console.log('🛑 Stopping palm vein scan');
    logger.info('Stopping palm vein scan', { deviceId });

    job.isActive = false;
    this.activeJobs.delete(deviceId);
  }

  /**
   * Get active jobs
   */
  getActiveJobs(): Map<string, PalmVeinJob> {
    return new Map(this.activeJobs);
  }

  /**
   * Check if device is scanning
   */
  isDeviceScanning(deviceId: string): boolean {
    return this.activeJobs.has(deviceId);
  }

  /**
   * Stop all scanning jobs
   */
  stopAll(): void {
    logger.info('Stopping all palm vein scan jobs');

    for (const deviceId of this.activeJobs.keys()) {
      this.stopScanning(deviceId);
    }
  }
}
