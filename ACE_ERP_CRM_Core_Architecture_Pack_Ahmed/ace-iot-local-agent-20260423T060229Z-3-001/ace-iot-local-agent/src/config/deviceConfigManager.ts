/**
 * Device Configuration Manager
 * 
 * Manages device configurations from the backend, including:
 * - Fetching configurations from backend
 * - Caching configurations locally
 * - Applying configuration updates
 * - Handling offline mode with cached configs
 */

import fs from 'fs';
import path from 'path';
import { logger } from '../utils/logger';
import { DeviceCapability } from '../devices/interfaces/deviceDriver';

/**
 * Device type
 */
export type DeviceType = 'printer' | 'scanner' | 'terminal';

/**
 * Device configuration from backend
 */
export interface DeviceConfiguration {
  id: string;                 // Backend-assigned device ID
  agentId: string;            // Agent this device belongs to
  deviceType: DeviceType;
  driverType: string;         // e.g., "label-printer-raw"
  name: string;               // Human-readable name
  enabled: boolean;           // Whether device is active
  connectionInfo: {
    vendorId?: string;
    productId?: string;
    port?: string;
    printerName?: string;     // Windows printer name
  };
  driverConfig: {             // Driver-specific configuration
    [key: string]: any;
  };
  capabilities: DeviceCapability[];
  metadata?: {                // Optional metadata
    location?: string;
    installDate?: string;
    [key: string]: any;
  };
  createdAt: string;          // ISO timestamp
  updatedAt: string;          // ISO timestamp
}

/**
 * Cached configuration file structure
 */
interface CachedConfigurations {
  version: string;
  agentId: string;
  lastSync: string;           // ISO timestamp
  devices: DeviceConfiguration[];
}

/**
 * Configuration validation result
 */
interface ValidationResult {
  valid: boolean;
  errors: string[];
}

/**
 * Device Configuration Manager
 * 
 * @example
 * ```typescript
 * const configManager = new DeviceConfigurationManager(apiClient, agentId);
 * 
 * // Fetch configurations from backend
 * const configs = await configManager.fetchConfigurations();
 * 
 * // Sync configurations (fetch + cache)
 * await configManager.syncConfigurations();
 * 
 * // Apply a configuration
 * await configManager.applyConfiguration(config);
 * ```
 */
export class DeviceConfigurationManager {
  private cacheFilePath: string;
  private configurations: Map<string, DeviceConfiguration> = new Map();

  constructor(
    private apiClient: any,  // Will be typed properly when we extend ApiClient
    private agentId: string,
    cacheDir?: string
  ) {
    // Default cache location
    const defaultCacheDir = path.join(process.cwd(), 'config');
    this.cacheFilePath = path.join(
      cacheDir || defaultCacheDir,
      'device-configs.json'
    );
  }

  /**
   * Fetch device configurations from backend
   * 
   * @returns Array of device configurations
   * @throws Error if fetch fails
   */
  async fetchConfigurations(): Promise<DeviceConfiguration[]> {
    try {
      logger.info('Fetching device configurations from backend...', {
        agentId: this.agentId,
      });

      const response = await this.apiClient.fetchDeviceConfigurations(this.agentId);

      if (!response || !response.configs) {
        throw new Error('Invalid response from backend');
      }

      const configs = response.configs as DeviceConfiguration[];

      logger.info('Device configurations fetched successfully', {
        count: configs.length,
      });

      return configs;
    } catch (error: any) {
      logger.error('Failed to fetch device configurations', {
        error: error.message,
        agentId: this.agentId,
      });
      throw error;
    }
  }

  /**
   * Sync configurations from backend and cache locally
   * 
   * This method:
   * 1. Fetches configurations from backend
   * 2. Validates each configuration
   * 3. Caches configurations locally
   * 4. Updates in-memory configuration map
   * 
   * @returns Array of synced configurations
   */
  async syncConfigurations(): Promise<DeviceConfiguration[]> {
    try {
      logger.info('Starting configuration sync...', { agentId: this.agentId });

      // Fetch from backend
      const configs = await this.fetchConfigurations();

      // Validate configurations
      const validConfigs: DeviceConfiguration[] = [];
      for (const config of configs) {
        const validation = this.validateConfiguration(config);
        if (validation.valid) {
          validConfigs.push(config);
        } else {
          logger.warn('Invalid configuration skipped', {
            deviceId: config.id,
            errors: validation.errors,
          });
        }
      }

      // Cache configurations
      await this.cacheConfigurations(validConfigs);

      // Update in-memory map
      this.configurations.clear();
      validConfigs.forEach(config => {
        this.configurations.set(config.id, config);
      });

      logger.info('Configuration sync complete', {
        total: configs.length,
        valid: validConfigs.length,
        invalid: configs.length - validConfigs.length,
      });

      return validConfigs;
    } catch (error: any) {
      logger.error('Configuration sync failed', {
        error: error.message,
      });

      // Try to load from cache
      logger.info('Attempting to load configurations from cache...');
      const cachedConfigs = await this.loadCachedConfigurations();

      if (cachedConfigs.length > 0) {
        logger.info('Loaded configurations from cache', {
          count: cachedConfigs.length,
        });

        // Update in-memory map
        this.configurations.clear();
        cachedConfigs.forEach(config => {
          this.configurations.set(config.id, config);
        });

        return cachedConfigs;
      }

      throw error;
    }
  }

  /**
   * Cache configurations to local file
   * 
   * @param configs - Configurations to cache
   */
  async cacheConfigurations(configs: DeviceConfiguration[]): Promise<void> {
    try {
      const cached: CachedConfigurations = {
        version: '1.0',
        agentId: this.agentId,
        lastSync: new Date().toISOString(),
        devices: configs,
      };

      // Ensure directory exists
      const dir = path.dirname(this.cacheFilePath);
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }

      // Write to file
      fs.writeFileSync(
        this.cacheFilePath,
        JSON.stringify(cached, null, 2),
        'utf-8'
      );

      logger.debug('Configurations cached successfully', {
        path: this.cacheFilePath,
        count: configs.length,
      });
    } catch (error: any) {
      logger.error('Failed to cache configurations', {
        error: error.message,
        path: this.cacheFilePath,
      });
      // Don't throw - caching failure shouldn't break the sync
    }
  }

  /**
   * Load configurations from local cache
   * 
   * @returns Array of cached configurations
   */
  async loadCachedConfigurations(): Promise<DeviceConfiguration[]> {
    try {
      if (!fs.existsSync(this.cacheFilePath)) {
        logger.debug('No cache file found', { path: this.cacheFilePath });
        return [];
      }

      const data = fs.readFileSync(this.cacheFilePath, 'utf-8');
      const cached: CachedConfigurations = JSON.parse(data);

      // Check cache staleness
      const lastSync = new Date(cached.lastSync);
      const now = new Date();
      const ageHours = (now.getTime() - lastSync.getTime()) / (1000 * 60 * 60);

      if (ageHours > 24) {
        logger.warn('Cached configurations are stale', {
          lastSync: cached.lastSync,
          ageHours: ageHours.toFixed(1),
        });
      }

      logger.info('Loaded configurations from cache', {
        count: cached.devices.length,
        lastSync: cached.lastSync,
        ageHours: ageHours.toFixed(1),
      });

      return cached.devices;
    } catch (error: any) {
      logger.error('Failed to load cached configurations', {
        error: error.message,
        path: this.cacheFilePath,
      });
      return [];
    }
  }

  /**
   * Apply a single configuration
   * 
   * This method validates and stores the configuration.
   * The actual device initialization is handled by DeviceManager.
   * 
   * @param config - Configuration to apply
   */
  async applyConfiguration(config: DeviceConfiguration): Promise<void> {
    // Validate configuration
    const validation = this.validateConfiguration(config);
    if (!validation.valid) {
      throw new Error(`Invalid configuration: ${validation.errors.join(', ')}`);
    }

    // Store in memory
    this.configurations.set(config.id, config);

    logger.info('Configuration applied', {
      deviceId: config.id,
      name: config.name,
      driverType: config.driverType,
    });

    // Update cache
    const allConfigs = Array.from(this.configurations.values());
    await this.cacheConfigurations(allConfigs);
  }

  /**
   * Remove a configuration
   * 
   * @param deviceId - Device ID to remove
   */
  async removeConfiguration(deviceId: string): Promise<void> {
    if (!this.configurations.has(deviceId)) {
      logger.warn('Configuration not found for removal', { deviceId });
      return;
    }

    this.configurations.delete(deviceId);

    logger.info('Configuration removed', { deviceId });

    // Update cache
    const allConfigs = Array.from(this.configurations.values());
    await this.cacheConfigurations(allConfigs);
  }

  /**
   * Get a specific configuration
   * 
   * @param deviceId - Device ID
   * @returns Configuration or undefined
   */
  getConfiguration(deviceId: string): DeviceConfiguration | undefined {
    return this.configurations.get(deviceId);
  }

  /**
   * Get all configurations
   * 
   * @returns Array of all configurations
   */
  getAllConfigurations(): DeviceConfiguration[] {
    return Array.from(this.configurations.values());
  }

  /**
   * Get configurations by type
   * 
   * @param deviceType - Device type to filter by
   * @returns Array of matching configurations
   */
  getConfigurationsByType(deviceType: DeviceType): DeviceConfiguration[] {
    return this.getAllConfigurations().filter(c => c.deviceType === deviceType);
  }

  /**
   * Get enabled configurations
   * 
   * @returns Array of enabled configurations
   */
  getEnabledConfigurations(): DeviceConfiguration[] {
    return this.getAllConfigurations().filter(c => c.enabled);
  }

  /**
   * Validate a device configuration
   * 
   * @param config - Configuration to validate
   * @returns Validation result
   */
  private validateConfiguration(config: DeviceConfiguration): ValidationResult {
    const errors: string[] = [];

    // Required fields
    if (!config.id) errors.push('Missing device ID');
    if (!config.agentId) errors.push('Missing agent ID');
    if (!config.deviceType) errors.push('Missing device type');
    if (!config.driverType) errors.push('Missing driver type');
    if (!config.name) errors.push('Missing device name');
    if (config.enabled === undefined) errors.push('Missing enabled flag');

    // Validate device type
    const validTypes: DeviceType[] = ['printer', 'scanner', 'terminal'];
    if (config.deviceType && !validTypes.includes(config.deviceType)) {
      errors.push(`Invalid device type: ${config.deviceType}`);
    }

    // Validate connection info
    if (!config.connectionInfo) {
      errors.push('Missing connection info');
    }

    // Validate driver config
    if (!config.driverConfig) {
      errors.push('Missing driver config');
    }

    // Validate capabilities
    if (!config.capabilities || !Array.isArray(config.capabilities)) {
      errors.push('Missing or invalid capabilities');
    }

    return {
      valid: errors.length === 0,
      errors,
    };
  }

  /**
   * Clear all configurations (for testing)
   */
  clearConfigurations(): void {
    this.configurations.clear();
    logger.debug('All configurations cleared');
  }

  /**
   * Get cache file path (for testing)
   */
  getCacheFilePath(): string {
    return this.cacheFilePath;
  }
}
