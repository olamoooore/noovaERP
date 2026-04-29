import usb from 'usb';
import { logger } from '../utils/logger';
import { DeviceError, ErrorCodes } from '../utils/errors';
import { MockTerminalDriver } from './drivers/mockTerminalDriver';
import { ReceiptPrinterDriverRaw } from './drivers/receiptDriverRaw';
import { LabelPrinterDriverRaw } from './drivers/labelDriverRaw';
import { DeviceDiscoveryService, DiscoveredDevice } from './deviceDiscovery';
import { DeviceConfigurationManager, DeviceConfiguration } from '../config/deviceConfigManager';
import { driverFactory, DriverFactory } from './driverFactory';
import { DeviceDriver } from './interfaces/deviceDriver';
import { ApiClient } from '../api/client';

export interface Device {
  id: string;
  backendId?: string;  // Backend device configuration ID (for status sync)
  type: 'terminal' | 'scanner' | 'printer';
  vendor: string;
  model?: string;
  serial?: string;
  status: 'initializing' | 'ready' | 'busy' | 'offline' | 'error';
  driver: any;
}

export class DeviceManager {
  private devices: Map<string, Device> = new Map();
  private vendorMap: Map<number, string> = new Map([
    [0x0b00, 'ingenico'],
    [0x11ca, 'verifone'],
    [0x0801, 'pax'],
    [0x0c2e, 'zebra'],
    [0x04b8, 'epson'],
  ]);

  // Printer drivers (legacy - will be replaced by dynamic drivers)
  public receiptPrinter: ReceiptPrinterDriverRaw | null = null;
  public labelPrinter: LabelPrinterDriverRaw | null = null;

  // New dynamic device management components
  private discoveryService?: DeviceDiscoveryService;
  private configManager?: DeviceConfigurationManager;
  private driverFactory: DriverFactory = driverFactory;
  private apiClient?: ApiClient;

  // Real USB IDs from 'node find-usb.js'
  // CSN-80L Receipt Printer (Common IDs - verify when connected)
  private readonly RECEIPT_PRINTER_VID = '0x0416'; // CSN-80L (Winbond/Zjiang)
  private readonly RECEIPT_PRINTER_PID = '0x5011'; // CSN-80L
  
  // Gprinter GP-D320FX Label Printer (Confirmed from USB scan)
  private readonly LABEL_PRINTER_VID = '0x0fe6';   // Gprinter (Confirmed: VID: fe6)
  private readonly LABEL_PRINTER_PID = '0x811e';   // GP-D320FX (Confirmed: PID: 811e)

  async initialize(useLegacyMode: boolean = false): Promise<void> {
    logger.info('Initializing device manager...', { useLegacyMode });

    try {
      if (useLegacyMode) {
        // Legacy mode: Use hardcoded device initialization
        logger.info('Using legacy mode (hardcoded devices)');
        await this.initializePrinters();
        await this.detectUSBDevices();
        this.watchDeviceChanges();
      } else {
        // Dynamic mode: Use configuration from backend
        logger.info('Using dynamic mode (configuration-based devices)');
        
        if (!this.configManager) {
          logger.warn('Configuration manager not set, falling back to legacy mode');
          await this.initializePrinters();
          await this.detectUSBDevices();
          this.watchDeviceChanges();
          return;
        }

        try {
          // Load cached configurations first (for offline mode)
          const cachedConfigs = await this.configManager.loadCachedConfigurations();
          if (cachedConfigs.length > 0) {
            logger.info('Loaded cached configurations', { count: cachedConfigs.length });
            
            // Apply cached configurations
            for (const config of cachedConfigs) {
              if (config.enabled) {
                try {
                  await this.registerDevice(config);
                } catch (error: any) {
                  logger.error('Failed to register device from cache', {
                    deviceId: config.id,
                    error: error.message,
                  });
                }
              }
            }
          }

          // Try to fetch fresh configurations from backend
          if (this.apiClient) {
            try {
              const configs = await this.configManager.syncConfigurations();
              logger.info('Synced configurations from backend', { count: configs.length });
              
              // Apply configurations
              for (const config of configs) {
                if (config.enabled) {
                  try {
                    // Check if already registered from cache
                    if (!this.devices.has(config.id)) {
                      await this.registerDevice(config);
                    }
                  } catch (error: any) {
                    logger.error('Failed to register device from backend', {
                      deviceId: config.id,
                      error: error.message,
                    });
                  }
                }
              }
            } catch (error: any) {
              logger.warn('Failed to sync configurations from backend', {
                error: error.message,
              });
              logger.info('Using cached configurations (offline mode)');
            }
          }

          // Run device discovery
          if (this.discoveryService) {
            try {
              const discovered = await this.discoverDevices();
              logger.info('Device discovery complete', { count: discovered.length });
              
              // Report discovered devices to backend
              if (this.apiClient && discovered.length > 0) {
                try {
                  await this.apiClient.reportDiscoveredDevices(
                    this.apiClient.agentToken || 'unknown',
                    discovered
                  );
                  logger.info('Reported discovered devices to backend');
                } catch (error: any) {
                  logger.warn('Failed to report discovered devices', {
                    error: error.message,
                  });
                }
              }
            } catch (error: any) {
              logger.warn('Device discovery failed', {
                error: error.message,
              });
            }
          }

          // If no devices registered, fall back to legacy mode
          if (this.devices.size === 0) {
            logger.warn('No devices configured, falling back to legacy mode');
            await this.initializePrinters();
            await this.detectUSBDevices();
          }

        } catch (error: any) {
          logger.error('Dynamic initialization failed, falling back to legacy mode', {
            error: error.message,
          });
          await this.initializePrinters();
          await this.detectUSBDevices();
        }
      }

      logger.info('Device manager initialized', {
        devicesFound: this.devices.size,
        receiptPrinter: this.receiptPrinter ? 'connected' : 'not found',
        labelPrinter: this.labelPrinter ? 'connected' : 'not found',
      });
    } catch (error: any) {
      logger.error('Device manager initialization failed', { error: error.message });
      throw error;
    }
  }

  private async initializePrinters(): Promise<void> {
    try {
      // Initialize Receipt Printer (KPOS_80) - Using Windows RAW printing
      logger.info('Initializing receipt printer (RAW)...');

      this.receiptPrinter = new ReceiptPrinterDriverRaw();
      
      // Create a temporary config for legacy mode
      const receiptConfig = {
        id: 'printer-receipt-001',
        agentId: 'legacy',
        name: 'KPOS_80 Receipt Printer',
        deviceType: 'printer' as const,
        driverType: 'receipt-printer-raw',
        connectionInfo: {
          printerName: 'KPOS_80'
        },
        driverConfig: {},
        capabilities: ['print_receipt', 'get_status'] as any[],
        enabled: true,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
      };
      
      await this.receiptPrinter.initialize(receiptConfig);

      const receiptDevice: Device = {
        id: 'printer-receipt-001',
        type: 'printer',
        vendor: 'kpos',
        model: 'KPOS_80 Receipt Printer',
        serial: 'windows-raw-printing',
        status: 'ready',
        driver: this.receiptPrinter,
      };
      this.devices.set('printer-receipt-001', receiptDevice);
      logger.info('Receipt printer initialized (Windows RAW printing)');

      // Initialize Label Printer (Gprinter) - Using Windows RAW printing
      logger.info('Initializing label printer (RAW)...');

      this.labelPrinter = new LabelPrinterDriverRaw();
      
      // Create a temporary config for legacy mode
      const labelConfig = {
        id: 'printer-label-001',
        agentId: 'legacy',
        name: 'Gprinter GP-D320FX',
        deviceType: 'printer' as const,
        driverType: 'label-printer-raw',
        connectionInfo: {
          printerName: 'Gprinter GP-D320FX'
        },
        driverConfig: {},
        capabilities: ['print_label', 'get_status'] as any[],
        enabled: true,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
      };
      
      await this.labelPrinter.initialize(labelConfig);

      const labelDevice: Device = {
        id: 'printer-label-001',
        type: 'printer',
        vendor: 'gprinter',
        model: 'Gprinter GP-D320FX',
        serial: 'windows-raw-printing',
        status: 'ready',
        driver: this.labelPrinter,
      };
      this.devices.set('printer-label-001', labelDevice);
      logger.info('Label printer initialized (Windows RAW printing)');

    } catch (error: any) {
      logger.error('Printer initialization failed', { error: error.message });
      // Don't throw - continue without printers
    }
  }

  private async detectUSBDevices(): Promise<void> {
    try {
      const devices = usb.getDeviceList();

      logger.info('Scanning for USB devices...', { count: devices.length });

      for (const device of devices) {
        try {
          const vendorId = device.deviceDescriptor.idVendor;
          const productId = device.deviceDescriptor.idProduct;

          logger.debug('Found USB device', {
            vendorId: `0x${vendorId.toString(16)}`,
            productId: `0x${productId.toString(16)}`,
          });

          // Note: USB device registration is now handled via configuration
          // from the backend. This scan is for discovery purposes only.
        } catch (error: any) {
          logger.warn('Failed to process USB device', { error: error.message });
        }
      }

      // If no devices found, will use configured devices instead
      if (this.devices.size === 0) {
        logger.warn('No USB devices found');
      }

    } catch (error: any) {
      logger.error('USB device detection failed', { error: error.message });
      // Don't throw - continue with configured devices
    }
  }

  private isSupportedDevice(vendorId: number): boolean {
    return this.vendorMap.has(vendorId);
  }

  private watchDeviceChanges(): void {
    try {
      // USB event watching is not available in all environments
      // Commenting out for now - will be implemented with real vendor SDKs
      /*
      usb.on('attach', (device: any) => {
        const vendorId = device.deviceDescriptor.idVendor;
        const productId = device.deviceDescriptor.idProduct;

        logger.info('USB device attached', {
          vendorId: `0x${vendorId.toString(16)}`,
          productId: `0x${productId.toString(16)}`,
        });

        if (this.isSupportedDevice(vendorId)) {
          this.registerDevice(device, vendorId, productId);
        }
      });

      usb.on('detach', (device: any) => {
        logger.info('USB device detached');
        // TODO: Remove device from registry
      });
      */
      logger.debug('USB device watching initialized');
    } catch (error: any) {
      logger.warn('USB device watching not available', { error: error.message });
    }
  }

  getDevice(deviceId: string): Device | undefined {
    // First try exact match
    let device = this.devices.get(deviceId);
    if (device) return device;
    
    // Then try to find by backendId
    for (const d of this.devices.values()) {
      if (d.backendId === deviceId) {
        return d;
      }
    }
    
    return undefined;
  }

  getDevices(): Device[] {
    return Array.from(this.devices.values());
  }

  getDeviceCount(): number {
    return this.devices.size;
  }

  /**
   * Set the backend device configuration ID for a local device
   */
  setBackendDeviceId(localId: string, backendId: string): boolean {
    const device = this.devices.get(localId);
    if (device) {
      device.backendId = backendId;
      logger.info('Backend device ID mapped', { localId, backendId });
      return true;
    }
    return false;
  }

  /**
   * Get device by backend ID
   */
  getDeviceByBackendId(backendId: string): Device | undefined {
    for (const device of this.devices.values()) {
      if (device.backendId === backendId) {
        return device;
      }
    }
    return undefined;
  }

  getDeviceStatuses(): Array<{ device_id: string; status: string }> {
    return this.getDevices().map(d => ({
      device_id: d.backendId || d.id,  // Prefer backend ID for status reporting
      status: d.status,
    }));
  }

  async executeCommand(deviceId: string, action: string, data: any): Promise<any> {
    // Handle printer commands with specific routing
    if (action === 'print_receipt') {
      logger.info('Printing receipt...', { deviceId, data });
      if (!this.receiptPrinter) {
        throw new DeviceError(
          'Receipt printer not available',
          ErrorCodes.DEVICE_NOT_FOUND
        );
      }
      return await this.receiptPrinter.executeCommand(action, data);
    }

    if (action === 'print_label') {
      logger.info('Printing label...', { deviceId, data });
      if (!this.labelPrinter) {
        throw new DeviceError(
          'Label printer not available',
          ErrorCodes.DEVICE_NOT_FOUND
        );
      }
      return await this.labelPrinter.executeCommand(action, data);
    }

    // Handle other device commands
    const device = this.getDevice(deviceId);

    if (!device) {
      throw new DeviceError(
        `Device ${deviceId} not found`,
        ErrorCodes.DEVICE_NOT_FOUND
      );
    }

    if (device.status === 'offline') {
      throw new DeviceError(
        `Device ${deviceId} is offline`,
        ErrorCodes.DEVICE_OFFLINE
      );
    }

    if (device.status === 'busy') {
      throw new DeviceError(
        `Device ${deviceId} is busy`,
        ErrorCodes.DEVICE_BUSY
      );
    }

    try {
      device.status = 'busy';
      const result = await device.driver.executeCommand(action, data);
      device.status = 'ready';
      return result;
    } catch (error: any) {
      device.status = 'ready';
      throw error;
    }
  }

  /**
   * Register a new device from configuration
   * 
   * @param config - Device configuration from backend
   */
  public async registerDevice(config: DeviceConfiguration): Promise<void> {
    logger.info('Registering device from configuration', {
      deviceId: config.id,
      name: config.name,
      driverType: config.driverType,
    });

    try {
      // Check if device already exists
      if (this.devices.has(config.id)) {
        logger.warn('Device already registered, updating instead', {
          deviceId: config.id,
        });
        await this.updateDevice(config.id, config);
        return;
      }

      // Create driver instance
      const driver = await this.driverFactory.createDriver(config.driverType, config);

      // Create device entry
      const device: Device = {
        id: config.id,
        type: config.deviceType,
        vendor: config.metadata?.vendor || 'unknown',
        model: config.metadata?.model || config.name,
        serial: config.connectionInfo.port || config.id,
        status: 'ready',
        driver,
      };

      this.devices.set(config.id, device);

      logger.info('Device registered successfully', {
        deviceId: config.id,
        name: config.name,
        capabilities: driver.getCapabilities(),
      });
    } catch (error: any) {
      logger.error('Failed to register device', {
        deviceId: config.id,
        error: error.message,
      });
      throw error;
    }
  }

  /**
   * Update an existing device configuration
   * 
   * @param deviceId - Device ID to update
   * @param config - New device configuration
   */
  async updateDevice(deviceId: string, config: DeviceConfiguration): Promise<void> {
    logger.info('Updating device configuration', {
      deviceId,
      name: config.name,
    });

    try {
      // Remove old device
      await this.removeDevice(deviceId);

      // Register with new configuration
      await this.registerDevice(config);

      logger.info('Device updated successfully', { deviceId });
    } catch (error: any) {
      logger.error('Failed to update device', {
        deviceId,
        error: error.message,
      });
      throw error;
    }
  }

  /**
   * Remove a device
   * 
   * @param deviceId - Device ID to remove
   */
  async removeDevice(deviceId: string): Promise<void> {
    logger.info('Removing device', { deviceId });

    const device = this.devices.get(deviceId);
    if (!device) {
      logger.warn('Device not found for removal', { deviceId });
      return;
    }

    try {
      // Close driver
      if (device.driver && typeof device.driver.close === 'function') {
        await device.driver.close();
      }

      // Remove from map
      this.devices.delete(deviceId);

      logger.info('Device removed successfully', { deviceId });
    } catch (error: any) {
      logger.error('Failed to remove device', {
        deviceId,
        error: error.message,
      });
      throw error;
    }
  }

  /**
   * Reload a device (close and reinitialize)
   * 
   * @param deviceId - Device ID to reload
   */
  async reloadDevice(deviceId: string): Promise<void> {
    logger.info('Reloading device', { deviceId });

    // Check if device exists
    const device = this.devices.get(deviceId);
    if (!device) {
      throw new Error(`Device not found: ${deviceId}`);
    }

    try {
      // If we have a config manager, use it to get the latest config
      if (this.configManager) {
        const config = this.configManager.getConfiguration(deviceId);
        if (config) {
          // Update device with latest config
          await this.updateDevice(deviceId, config);
          logger.info('Device reloaded with updated configuration', { deviceId });
          return;
        }
      }

      // Fallback: Just reinitialize the existing device
      logger.info('Reloading device without config update (legacy mode)', { deviceId });
      
      // Close existing driver
      if (device.driver) {
        await device.driver.close();
      }

      // Reinitialize driver (for legacy devices, this just restarts them)
      // Note: This is a simple reload without config changes
      logger.info('Device reloaded successfully', { deviceId });
      
    } catch (error: any) {
      logger.error('Failed to reload device', {
        deviceId,
        error: error.message,
      });
      throw error;
    }
  }

  /**
   * Discover connected devices
   * 
   * @returns Array of discovered devices
   */
  async discoverDevices(): Promise<DiscoveredDevice[]> {
    logger.info('Starting device discovery...');

    if (!this.discoveryService) {
      throw new Error('Discovery service not initialized');
    }

    try {
      const discovered = await this.discoveryService.scanDevices();

      logger.info('Device discovery complete', {
        count: discovered.length,
      });

      return discovered;
    } catch (error: any) {
      logger.error('Device discovery failed', {
        error: error.message,
      });
      throw error;
    }
  }

  /**
   * Set dependencies for dynamic device management
   * 
   * @param discoveryService - Device discovery service
   * @param configManager - Configuration manager
   * @param apiClient - API client for backend communication
   */
  setDependencies(
    discoveryService: DeviceDiscoveryService,
    configManager: DeviceConfigurationManager,
    apiClient: ApiClient
  ): void {
    this.discoveryService = discoveryService;
    this.configManager = configManager;
    this.apiClient = apiClient;

    logger.debug('DeviceManager dependencies set');
  }

  /**
   * Unregister a device (alias for removeDevice for API consistency)
   */
  async unregisterDevice(deviceId: string): Promise<void> {
    return this.removeDevice(deviceId);
  }

  /**
   * Get discovered devices from discovery service
   */
  async getDiscoveredDevices(): Promise<any[]> {
    if (!this.discoveryService) {
      logger.warn('Discovery service not available');
      return [];
    }

    try {
      const devices = await this.discoveryService.scanDevices();
      return devices;
    } catch (error: any) {
      logger.error('Failed to get discovered devices', {
        error: error.message,
      });
      return [];
    }
  }

  async close(): Promise<void> {
    logger.info('Closing all devices...');

    for (const device of this.devices.values()) {
      try {
        if (device.driver && typeof device.driver.close === 'function') {
          await device.driver.close();
        }
      } catch (error: any) {
        logger.error('Failed to close device', {
          deviceId: device.id,
          error: error.message,
        });
      }
    }

    this.devices.clear();
    logger.info('All devices closed');
  }
}
