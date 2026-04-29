/**
 * Configuration Sync Service
 * 
 * Periodically fetches device configurations from backend and manages device lifecycle
 */

import { logger } from '../utils/logger';
import { ApiClient } from '../api/client';
import { DeviceManager } from '../devices/deviceManager';
import { DeviceDiscoveryService, DiscoveredDevice } from '../devices/deviceDiscovery';
import { DeviceConfiguration } from '../config/deviceConfigManager';

export class ConfigSyncService {
  private syncInterval: NodeJS.Timeout | null = null;
  private isRunning = false;

  constructor(
    private apiClient: ApiClient,
    private deviceManager: DeviceManager,
    private discoveryService: DeviceDiscoveryService,
    private agentId: string,
    private intervalSeconds: number = 60
  ) {}

  /**
   * Start the configuration sync service
   */
  async start(): Promise<void> {
    if (this.isRunning) {
      logger.warn('ConfigSyncService already running');
      return;
    }

    this.isRunning = true;
    logger.info('Starting ConfigSyncService', {
      interval: `${this.intervalSeconds}s`,
    });

    // Initial sync
    await this.syncConfigurations();

    // Start periodic sync
    this.syncInterval = setInterval(async () => {
      await this.syncConfigurations();
    }, this.intervalSeconds * 1000);

    logger.info('ConfigSyncService started');
  }

  /**
   * Stop the configuration sync service
   */
  stop(): void {
    if (!this.isRunning) {
      return;
    }

    if (this.syncInterval) {
      clearInterval(this.syncInterval);
      this.syncInterval = null;
    }

    this.isRunning = false;
    logger.info('ConfigSyncService stopped');
  }

  /**
   * Sync configurations from backend
   */
  private async syncConfigurations(): Promise<void> {
    try {
      logger.debug('Starting configuration sync...');

      // Fetch configurations from backend
      const configs = await this.apiClient.fetchDeviceConfigurations(this.agentId);

      if (!Array.isArray(configs)) {
        logger.warn('Invalid configs response from backend', { configs });
        return;
      }

      logger.info('Fetched device configurations', { count: configs.length });

      // Get discovered devices
      const discoveredDevices = await this.discoveryService.scanDevices();
      const discoveredMap = this.buildDiscoveredMap(discoveredDevices);

      // Process each configuration
      for (const config of configs) {
        await this.processConfiguration(config, discoveredMap);
      }

      // Check for devices that should be removed
      await this.removeUnusedDevices(configs);

      logger.debug('Configuration sync complete');
    } catch (error: any) {
      logger.error('Configuration sync failed', {
        error: error.message,
        stack: error.stack,
      });
    }
  }

  /**
   * Build a map of discovered devices by vendorId:productId
   */
  private buildDiscoveredMap(devices: DiscoveredDevice[]): Map<string, DiscoveredDevice> {
    const map = new Map<string, DiscoveredDevice>();

    for (const device of devices) {
      const key = `${device.vendorId}:${device.productId}`;
      map.set(key, device);
    }

    return map;
  }

  /**
   * Process a single device configuration
   */
  private async processConfiguration(
    config: any,
    discoveredMap: Map<string, DiscoveredDevice>
  ): Promise<void> {
    try {
      const deviceId = config.id;
      const vendorId = config.connectionInfo?.vendorId;
      const productId = config.connectionInfo?.productId;

      if (!deviceId) {
        logger.warn('Config missing device ID', { config });
        return;
      }

      if (!config.enabled) {
        logger.debug('Device is disabled, skipping', { deviceId, name: config.name });
        
        // If device exists, unregister it
        const existingDevice = this.deviceManager.getDevice(deviceId);
        if (existingDevice) {
          await this.deviceManager.unregisterDevice(deviceId);
          await this.reportDeviceStatus(deviceId, 'offline', 'Device disabled');
        }
        return;
      }

      // Check if hardware is connected
      const hardwareKey = `${vendorId}:${productId}`;
      const discoveredDevice = discoveredMap.get(hardwareKey);

      if (!discoveredDevice) {
        logger.warn('Hardware not found for device config', {
          deviceId,
          name: config.name,
          vendorId,
          productId,
          hardwareKey,
          availableKeys: Array.from(discoveredMap.keys()),
        });

        // Report as disconnected
        await this.reportDeviceStatus(deviceId, 'offline', 'Hardware not connected');
        
        // If device was previously registered, unregister it
        const existingDevice = this.deviceManager.getDevice(deviceId);
        if (existingDevice) {
          await this.deviceManager.unregisterDevice(deviceId);
        }
        
        return;
      }

      // Hardware is connected, check if device is already registered
      const existingDevice = this.deviceManager.getDevice(deviceId);

      if (existingDevice) {
        logger.debug('Device already registered', {
          deviceId,
          name: config.name,
          status: existingDevice.status,
        });

        // Check if device is ready
        if (existingDevice.status === 'ready') {
          await this.reportDeviceStatus(deviceId, 'ready', 'Device operational');
        } else if (existingDevice.status === 'error') {
          await this.reportDeviceStatus(deviceId, 'error', 'Device error');
        }

        return;
      }

      // Register new device
      logger.info('Registering new device from config', {
        deviceId,
        name: config.name,
        driverType: config.driverType,
      });

      const deviceConfig: DeviceConfiguration = {
        id: config.id,
        agentId: this.agentId,
        deviceType: config.deviceType,
        driverType: config.driverType,
        name: config.name,
        enabled: config.enabled,
        connectionInfo: config.connectionInfo,
        driverConfig: config.driverConfig || {},
        capabilities: config.capabilities || [],
        metadata: config.metadata,
        createdAt: config.createdAt || new Date().toISOString(),
        updatedAt: config.updatedAt || new Date().toISOString(),
      };

      await this.deviceManager.registerDevice(deviceConfig);

      // Check device status after registration
      const registeredDevice = this.deviceManager.getDevice(deviceId);
      if (registeredDevice) {
        if (registeredDevice.status === 'ready') {
          await this.reportDeviceStatus(deviceId, 'ready', 'Device initialized successfully');
        } else if (registeredDevice.status === 'error') {
          await this.reportDeviceStatus(deviceId, 'error', 'Device initialization failed');
        }
      }

    } catch (error: any) {
      logger.error('Failed to process device configuration', {
        error: error.message,
        config,
      });

      // Report error status
      if (config.id) {
        await this.reportDeviceStatus(config.id, 'error', error.message);
      }
    }
  }

  /**
   * Remove devices that are no longer in the configuration
   */
  private async removeUnusedDevices(configs: any[]): Promise<void> {
    const configIds = new Set(configs.map(c => c.id));
    const registeredDevices = this.deviceManager.getDevices();

    for (const device of registeredDevices) {
      // Skip legacy devices (they don't have UUIDs)
      if (device.id.startsWith('printer-')) {
        continue;
      }

      if (!configIds.has(device.id)) {
        logger.info('Removing device no longer in configuration', {
          deviceId: device.id,
          name: device.model,
        });

        await this.deviceManager.unregisterDevice(device.id);
        await this.reportDeviceStatus(device.id, 'offline', 'Device removed from configuration');
      }
    }
  }

  /**
   * Report device status to backend
   * 
   * NOTE: Disabled until backend endpoint is implemented
   * See BACKEND_DEVICE_STATUS_ENDPOINT_NEEDED.md for implementation guide
   */
  private async reportDeviceStatus(
    deviceId: string,
    status: string,
    message?: string
  ): Promise<void> {
    // Disabled - backend endpoint not implemented yet
    // The agent works perfectly without this feature
    // Uncomment when backend implements POST /api/v1/iot/agents/{agentId}/device-status
    return;
    
    /* Original implementation:
    try {
      await this.apiClient.updateDeviceStatus(
        this.agentId,
        deviceId,
        status,
        message
      );
    } catch (error: any) {
      logger.error('Failed to report device status', {
        error: error.message,
        deviceId,
        status,
      });
    }
    */
  }

  /**
   * Trigger an immediate sync (for testing/manual trigger)
   */
  async triggerSync(): Promise<void> {
    logger.info('Manual configuration sync triggered');
    await this.syncConfigurations();
  }
}
