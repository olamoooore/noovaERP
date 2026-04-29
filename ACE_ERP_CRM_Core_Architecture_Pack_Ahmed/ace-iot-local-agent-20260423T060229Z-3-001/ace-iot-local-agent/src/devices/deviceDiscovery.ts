/**
 * Device Discovery Service
 * 
 * Scans for connected USB, Serial, and HID devices and reports them to the backend.
 */

import usb from 'usb';
import { SerialPort } from 'serialport';
import HID from 'node-hid';
import { logger } from '../utils/logger';
import { EventEmitter } from 'events';
import { execSync } from 'child_process';

/**
 * Connection type for discovered devices
 */
export type ConnectionType = 'usb' | 'serial' | 'hid' | 'windows_printer';

/**
 * Discovered device information
 */
export interface DiscoveredDevice {
  vendorId: string;           // e.g., "0x0fe6"
  productId: string;          // e.g., "0x811e"
  manufacturer?: string;      // e.g., "Gprinter"
  model?: string;             // e.g., "GP-D320FX"
  serialNumber?: string;      // Device serial number
  port?: string;              // e.g., "USB001", "COM10"
  connectionType: ConnectionType;
  discoveredAt: string;       // ISO timestamp
}

/**
 * Device event types
 */
export interface DeviceEvent {
  type: 'attached' | 'detached';
  device: DiscoveredDevice;
  timestamp: Date;
}

/**
 * Device Discovery Service
 * 
 * Scans for connected devices and monitors for changes.
 * 
 * @example
 * ```typescript
 * const discovery = new DeviceDiscoveryService();
 * 
 * // Scan for devices
 * const devices = await discovery.scanDevices();
 * console.log('Found devices:', devices);
 * 
 * // Watch for changes
 * await discovery.watchDeviceChanges((event) => {
 *   console.log('Device event:', event.type, event.device);
 * });
 * ```
 */
export class DeviceDiscoveryService extends EventEmitter {
  private isWatching = false;
  private knownDevices = new Map<string, DiscoveredDevice>();

  /**
   * Scan for all connected devices
   * 
   * @returns Array of discovered devices
   */
  async scanDevices(): Promise<DiscoveredDevice[]> {
    logger.info('Starting device discovery scan...');

    const devices: DiscoveredDevice[] = [];

    try {
      // Scan USB devices
      const usbDevices = await this.scanUSBDevices();
      devices.push(...usbDevices);

      // Scan Serial ports
      const serialDevices = await this.scanSerialDevices();
      devices.push(...serialDevices);

      // Scan HID devices
      const hidDevices = await this.scanHIDDevices();
      devices.push(...hidDevices);

      // Scan Windows printers
      const printerDevices = await this.scanWindowsPrinters();
      devices.push(...printerDevices);

      logger.info('Device discovery scan complete (before deduplication)', {
        total: devices.length,
        usb: usbDevices.length,
        serial: serialDevices.length,
        hid: hidDevices.length,
        printers: printerDevices.length,
      });

      // Deduplicate devices based on VID/PID
      const deduplicated = this.deduplicateDevices(devices);

      logger.info('Device discovery scan complete (after deduplication)', {
        total: deduplicated.length,
        removed: devices.length - deduplicated.length,
      });

      // Update known devices
      deduplicated.forEach(device => {
        const key = this.getDeviceKey(device);
        this.knownDevices.set(key, device);
      });

      return deduplicated;
    } catch (error: any) {
      logger.error('Device discovery scan failed', { error: error.message });
      throw error;
    }
  }

  /**
   * Scan for USB devices
   */
  private async scanUSBDevices(): Promise<DiscoveredDevice[]> {
    // Try native USB scanning first (requires admin)
    try {
      const devices = await this.scanUSBDevicesNative();
      if (devices.length > 0) {
        logger.info('USB devices detected via native library', { count: devices.length });
        return devices;
      }
    } catch (error: any) {
      logger.debug('Native USB scan failed, trying WMI fallback...', { error: error.message });
    }

    // Fall back to WMI-based scanning (no admin required)
    return await this.scanUSBDevicesWMI();
  }

  /**
   * Scan for USB devices using native library (requires admin)
   */
  private async scanUSBDevicesNative(): Promise<DiscoveredDevice[]> {
    const devices: DiscoveredDevice[] = [];

    const usbDevices = usb.getDeviceList();

    for (const device of usbDevices) {
      try {
        const descriptor = device.deviceDescriptor;
        const vendorId = `0x${descriptor.idVendor.toString(16).padStart(4, '0')}`;
        const productId = `0x${descriptor.idProduct.toString(16).padStart(4, '0')}`;

        // Try to get string descriptors
        let manufacturer: string | undefined;
        let model: string | undefined;
        let serialNumber: string | undefined;

        try {
          device.open();
          
          // Note: getStringDescriptor is async in some versions
          // For now, we'll skip string descriptors to avoid compatibility issues
          // They can be added later if needed
          
          device.close();
        } catch (error) {
          // Ignore errors reading string descriptors
          // Some devices don't allow reading without drivers
        }

        devices.push({
          vendorId,
          productId,
          manufacturer,
          model,
          serialNumber,
          connectionType: 'usb',
          discoveredAt: new Date().toISOString(),
        });

        logger.debug('USB device discovered (native)', { vendorId, productId, manufacturer, model });
      } catch (error: any) {
        logger.warn('Failed to process USB device', { error: error.message });
      }
    }

    return devices;
  }

  /**
   * Scan for USB devices using WMI (no admin required)
   */
  private async scanUSBDevicesWMI(): Promise<DiscoveredDevice[]> {
    const devices: DiscoveredDevice[] = [];

    try {
      // Use PowerShell to query WMI for USB devices
      const command = 'powershell -Command "Get-WmiObject Win32_PnPEntity | Where-Object {$_.DeviceID -like \'USB*\'} | Select-Object Name, DeviceID, Manufacturer | ConvertTo-Json"';
      
      const output = execSync(command, { encoding: 'utf-8', timeout: 10000 });
      
      if (!output || output.trim() === '') {
        logger.debug('No USB devices found via WMI');
        return devices;
      }

      const usbDevices = JSON.parse(output);
      const deviceArray = Array.isArray(usbDevices) ? usbDevices : [usbDevices];

      for (const device of deviceArray) {
        if (!device || !device.DeviceID) continue;

        // Extract VID and PID from DeviceID
        // Format: USB\VID_0FE6&PID_811E\...
        const vidMatch = device.DeviceID.match(/VID_([0-9A-F]{4})/i);
        const pidMatch = device.DeviceID.match(/PID_([0-9A-F]{4})/i);

        if (vidMatch && pidMatch) {
          const vendorId = `0x${vidMatch[1].toLowerCase()}`;
          const productId = `0x${pidMatch[1].toLowerCase()}`;

          devices.push({
            vendorId,
            productId,
            manufacturer: device.Manufacturer || 'Unknown',
            model: device.Name,
            serialNumber: undefined,
            port: undefined,
            connectionType: 'usb',
            discoveredAt: new Date().toISOString(),
          });

          logger.debug('USB device discovered via WMI', {
            vendorId,
            productId,
            name: device.Name,
            manufacturer: device.Manufacturer,
          });
        }
      }

      logger.info('USB devices detected via WMI', { count: devices.length });
    } catch (error: any) {
      logger.error('WMI USB device scan failed', { error: error.message });
    }

    return devices;
  }

  /**
   * Scan for Serial port devices
   */
  private async scanSerialDevices(): Promise<DiscoveredDevice[]> {
    const devices: DiscoveredDevice[] = [];

    try {
      const ports = await SerialPort.list();

      for (const port of ports) {
        // Only include ports with vendor/product IDs
        if (port.vendorId && port.productId) {
          const vendorId = `0x${port.vendorId.toLowerCase()}`;
          const productId = `0x${port.productId.toLowerCase()}`;

          devices.push({
            vendorId,
            productId,
            manufacturer: port.manufacturer,
            model: port.productId,
            serialNumber: port.serialNumber,
            port: port.path,
            connectionType: 'serial',
            discoveredAt: new Date().toISOString(),
          });

          logger.debug('Serial device discovered', {
            vendorId,
            productId,
            port: port.path,
            manufacturer: port.manufacturer,
          });
        }
      }
    } catch (error: any) {
      logger.error('Serial device scan failed', { error: error.message });
    }

    return devices;
  }

  /**
   * Scan for HID devices
   */
  private async scanHIDDevices(): Promise<DiscoveredDevice[]> {
    const devices: DiscoveredDevice[] = [];

    try {
      const hidDevices = HID.devices();

      for (const device of hidDevices) {
        const vendorId = `0x${device.vendorId.toString(16).padStart(4, '0')}`;
        const productId = `0x${device.productId.toString(16).padStart(4, '0')}`;

        devices.push({
          vendorId,
          productId,
          manufacturer: device.manufacturer,
          model: device.product,
          serialNumber: device.serialNumber,
          port: device.path,
          connectionType: 'hid',
          discoveredAt: new Date().toISOString(),
        });

        logger.debug('HID device discovered', {
          vendorId,
          productId,
          manufacturer: device.manufacturer,
          product: device.product,
        });
      }
    } catch (error: any) {
      logger.error('HID device scan failed', { error: error.message });
    }

    return devices;
  }

  /**
   * Scan for Windows printers
   */
  private async scanWindowsPrinters(): Promise<DiscoveredDevice[]> {
    const devices: DiscoveredDevice[] = [];

    try {
      // Use PowerShell to get printer information - only get printers that are available
      const command = 'powershell -Command "Get-Printer | Where-Object {$_.Type -eq \'Local\' -and $_.PrinterStatus -ne \'Offline\'} | Select-Object Name, DriverName, PortName, PrinterStatus | ConvertTo-Json"';
      
      const output = execSync(command, { encoding: 'utf-8' });
      
      // Handle empty result
      if (!output || output.trim() === '') {
        logger.debug('No Windows printers found');
        return devices;
      }

      const printers = JSON.parse(output);

      // Handle single printer (not an array)
      const printerArray = Array.isArray(printers) ? printers : [printers];

      for (const printer of printerArray) {
        // Skip if printer is not available
        if (!printer || !printer.Name) continue;

        // Skip virtual/network printers that aren't physically connected
        if (printer.PortName && (
          printer.PortName.includes('SHRFAX') ||
          printer.PortName.includes('FILE:') ||
          printer.PortName.includes('XPS') ||
          printer.PortName.includes('OneNote') ||
          printer.PortName.includes('PDF')
        )) {
          logger.debug('Skipping virtual printer', { name: printer.Name, port: printer.PortName });
          continue;
        }

        // Try to extract vendor/product info from driver name
        let manufacturer = 'Unknown';
        let model = printer.Name;

        // Common printer vendors
        if (printer.DriverName) {
          if (printer.DriverName.toLowerCase().includes('gprinter')) {
            manufacturer = 'Gprinter';
          } else if (printer.DriverName.toLowerCase().includes('kpos')) {
            manufacturer = 'KPOS';
          } else if (printer.DriverName.toLowerCase().includes('epson')) {
            manufacturer = 'Epson';
          } else if (printer.DriverName.toLowerCase().includes('zebra')) {
            manufacturer = 'Zebra';
          } else if (printer.DriverName.toLowerCase().includes('hp')) {
            manufacturer = 'HP';
          } else if (printer.DriverName.toLowerCase().includes('canon')) {
            manufacturer = 'Canon';
          }
        }

        // Generate pseudo vendor/product IDs based on printer name hash
        const nameHash = this.hashString(printer.Name);
        const vendorId = `0xWIN${nameHash.substring(0, 1)}`;
        const productId = `0x${nameHash.substring(1, 5)}`;

        devices.push({
          vendorId,
          productId,
          manufacturer,
          model,
          serialNumber: printer.PortName,
          port: printer.PortName,
          connectionType: 'windows_printer',
          discoveredAt: new Date().toISOString(),
        });

        logger.debug('Windows printer discovered', {
          name: printer.Name,
          driver: printer.DriverName,
          port: printer.PortName,
          status: printer.PrinterStatus,
        });
      }
    } catch (error: any) {
      logger.error('Windows printer scan failed', { error: error.message });
    }

    return devices;
  }

  /**
   * Simple hash function for generating pseudo IDs
   */
  private hashString(str: string): string {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash; // Convert to 32bit integer
    }
    return Math.abs(hash).toString(16).padStart(8, '0');
  }

  /**
   * Watch for device connection/disconnection events
   * 
   * @param callback - Function to call when device events occur
   */
  async watchDeviceChanges(callback: (event: DeviceEvent) => void): Promise<void> {
    if (this.isWatching) {
      logger.warn('Device watching already active');
      return;
    }

    this.isWatching = true;
    logger.info('Starting device change monitoring...');

    // Listen for USB attach/detach events
    // Note: USB event listeners are not available in all environments
    // We'll rely on periodic scanning instead
    logger.info('Using periodic scanning for device monitoring (USB events not available)');

    // Note: Serial and HID don't have native event listeners
    // We'll need to poll periodically for changes
    this.startPeriodicScan(callback);
  }

  /**
   * Stop watching for device changes
   */
  stopWatching(): void {
    if (!this.isWatching) {
      return;
    }

    this.isWatching = false;
    // Note: USB event listeners not used in this implementation
    
    logger.info('Device change monitoring stopped');
  }

  /**
   * Handle USB device attach event
   */
  private handleUSBAttach(device: usb.Device, callback: (event: DeviceEvent) => void): void {
    try {
      const descriptor = device.deviceDescriptor;
      const vendorId = `0x${descriptor.idVendor.toString(16).padStart(4, '0')}`;
      const productId = `0x${descriptor.idProduct.toString(16).padStart(4, '0')}`;

      const discoveredDevice: DiscoveredDevice = {
        vendorId,
        productId,
        connectionType: 'usb',
        discoveredAt: new Date().toISOString(),
      };

      const key = this.getDeviceKey(discoveredDevice);
      this.knownDevices.set(key, discoveredDevice);

      logger.info('USB device attached', { vendorId, productId });

      callback({
        type: 'attached',
        device: discoveredDevice,
        timestamp: new Date(),
      });

      this.emit('device:attached', discoveredDevice);
    } catch (error: any) {
      logger.error('Failed to handle USB attach', { error: error.message });
    }
  }

  /**
   * Handle USB device detach event
   */
  private handleUSBDetach(device: usb.Device, callback: (event: DeviceEvent) => void): void {
    try {
      const descriptor = device.deviceDescriptor;
      const vendorId = `0x${descriptor.idVendor.toString(16).padStart(4, '0')}`;
      const productId = `0x${descriptor.idProduct.toString(16).padStart(4, '0')}`;

      const discoveredDevice: DiscoveredDevice = {
        vendorId,
        productId,
        connectionType: 'usb',
        discoveredAt: new Date().toISOString(),
      };

      const key = this.getDeviceKey(discoveredDevice);
      this.knownDevices.delete(key);

      logger.info('USB device detached', { vendorId, productId });

      callback({
        type: 'detached',
        device: discoveredDevice,
        timestamp: new Date(),
      });

      this.emit('device:detached', discoveredDevice);
    } catch (error: any) {
      logger.error('Failed to handle USB detach', { error: error.message });
    }
  }

  /**
   * Start periodic scanning for Serial/HID device changes
   */
  private startPeriodicScan(callback: (event: DeviceEvent) => void): void {
    const SCAN_INTERVAL = 10000; // 10 seconds

    const scan = async () => {
      if (!this.isWatching) {
        return;
      }

      try {
        const currentDevices = await this.scanDevices();
        const currentKeys = new Set(currentDevices.map(d => this.getDeviceKey(d)));
        const knownKeys = new Set(this.knownDevices.keys());

        // Find newly attached devices
        for (const device of currentDevices) {
          const key = this.getDeviceKey(device);
          if (!knownKeys.has(key)) {
            logger.info('Device attached (periodic scan)', {
              vendorId: device.vendorId,
              productId: device.productId,
              type: device.connectionType,
            });

            callback({
              type: 'attached',
              device,
              timestamp: new Date(),
            });

            this.emit('device:attached', device);
          }
        }

        // Find detached devices
        for (const [key, device] of this.knownDevices.entries()) {
          if (!currentKeys.has(key)) {
            logger.info('Device detached (periodic scan)', {
              vendorId: device.vendorId,
              productId: device.productId,
              type: device.connectionType,
            });

            callback({
              type: 'detached',
              device,
              timestamp: new Date(),
            });

            this.emit('device:detached', device);
          }
        }
      } catch (error: any) {
        logger.error('Periodic device scan failed', { error: error.message });
      }

      // Schedule next scan
      setTimeout(scan, SCAN_INTERVAL);
    };

    // Start first scan after a delay
    setTimeout(scan, SCAN_INTERVAL);
  }

  /**
   * Deduplicate devices based on VID/PID
   * 
   * Priority order (highest to lowest):
   * 1. Windows Printers - Most useful for printers (has driver, ready to use)
   * 2. HID Devices - More detailed than USB (has manufacturer, model, serial)
   * 3. Serial Devices - For serial-connected devices
   * 4. USB Devices - Basic info only
   * 
   * @param devices - Array of discovered devices
   * @returns Deduplicated array of devices
   */
  private deduplicateDevices(devices: DiscoveredDevice[]): DiscoveredDevice[] {
    const deviceMap = new Map<string, DiscoveredDevice>();

    // Define priority order (lower number = higher priority)
    const priorityOrder: Record<ConnectionType, number> = {
      'windows_printer': 1,
      'hid': 2,
      'serial': 3,
      'usb': 4,
    };

    for (const device of devices) {
      // Create a unique key based on VID/PID only (ignore port for deduplication)
      const key = `${device.vendorId}:${device.productId}`;
      
      const existing = deviceMap.get(key);

      if (!existing) {
        // First time seeing this device
        deviceMap.set(key, device);
        logger.debug('Added device to map', {
          key,
          type: device.connectionType,
          model: device.model,
        });
      } else {
        // Device already exists, check priority
        const existingPriority = priorityOrder[existing.connectionType];
        const newPriority = priorityOrder[device.connectionType];

        if (newPriority < existingPriority) {
          // New device has higher priority, replace
          deviceMap.set(key, device);
          logger.debug('Replaced device with higher priority', {
            key,
            oldType: existing.connectionType,
            newType: device.connectionType,
            model: device.model,
          });
        } else {
          // Keep existing device
          logger.debug('Kept existing device (higher priority)', {
            key,
            type: existing.connectionType,
            model: existing.model,
            skippedType: device.connectionType,
          });
        }
      }
    }

    const deduplicated = Array.from(deviceMap.values());

    logger.info('Deduplication complete', {
      before: devices.length,
      after: deduplicated.length,
      removed: devices.length - deduplicated.length,
    });

    return deduplicated;
  }

  /**
   * Get unique key for a device
   */
  private getDeviceKey(device: DiscoveredDevice): string {
    return `${device.connectionType}:${device.vendorId}:${device.productId}:${device.port || 'default'}`;
  }

  /**
   * Get device information for a specific device
   */
  async getDeviceInfo(vendorId: string, productId: string): Promise<DiscoveredDevice | null> {
    const devices = await this.scanDevices();
    return devices.find(d => d.vendorId === vendorId && d.productId === productId) || null;
  }
}
