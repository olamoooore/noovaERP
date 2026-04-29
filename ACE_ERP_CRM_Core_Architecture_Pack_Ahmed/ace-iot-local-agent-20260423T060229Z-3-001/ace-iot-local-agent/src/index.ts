import os from 'os';
import { logger } from './utils/logger';
import { config, configManager } from './config/config';
import { ApiClient } from './api/client';
import { DeviceManager } from './devices/deviceManager';
import { WebSocketServer } from './websocket/server';
import { HttpServer } from './http/server';
import { DeviceDiscoveryService } from './devices/deviceDiscovery';
import { DeviceConfigurationManager } from './config/deviceConfigManager';
import { registerAllDrivers } from './devices/registerDrivers';
import { ConfigSyncService } from './services/configSyncService';
import { ScannerListenerService } from './services/scannerListenerService';
import { PalmVeinScannerService } from './services/palmVeinScannerService';

class Agent {
  private apiClient: ApiClient;
  private deviceManager: DeviceManager;
  private wsServer: WebSocketServer;
  private httpServer: HttpServer;
  private scannerListener: ScannerListenerService;
  private palmVeinScanner: PalmVeinScannerService;
  private heartbeatInterval: NodeJS.Timeout | null = null;
  private configSyncInterval: NodeJS.Timeout | null = null;
  private discoveryService?: DeviceDiscoveryService;
  private configManager?: DeviceConfigurationManager;
  private configSyncService?: ConfigSyncService;

  constructor() {
    this.apiClient = new ApiClient(config.backend.url);
    this.deviceManager = new DeviceManager();
    this.scannerListener = new ScannerListenerService(
      this.apiClient,
      config.agent.id
    );
    this.palmVeinScanner = new PalmVeinScannerService(
      this.apiClient,
      this.deviceManager,
      config.agent.id
    );
    this.wsServer = new WebSocketServer(
      config.websocket.port,
      this.deviceManager,
      this.apiClient
    );
    this.httpServer = new HttpServer(
      config.http.port,
      this.deviceManager,
      this.scannerListener,
      this.palmVeinScanner
    );

    // Register all device drivers
    registerAllDrivers();
    logger.info('Device drivers registered');

    // Initialize dynamic device management components if not in legacy mode
    if (!config.devices.useLegacyMode) {
      this.discoveryService = new DeviceDiscoveryService();
      this.configManager = new DeviceConfigurationManager(
        this.apiClient,
        config.agent.id
      );
      
      // Set dependencies on device manager
      this.deviceManager.setDependencies(
        this.discoveryService,
        this.configManager,
        this.apiClient
      );
      
      logger.info('Dynamic device management initialized');
    }
  }

  async start(): Promise<void> {
    try {
      logger.info('='.repeat(60));
      logger.info('NoovaERP IoT Local Agent Starting...');
      logger.info('='.repeat(60));
      logger.info('Configuration:', {
        agentId: config.agent.id,
        agentName: config.agent.name,
        version: config.agent.version,
        backendUrl: config.backend.url,
        wsPort: config.websocket.port,
      });

      // Initialize device manager
      logger.info('Initializing device manager...');
      await this.deviceManager.initialize(config.devices.useLegacyMode);
      logger.info('Device manager initialized', {
        devicesFound: this.deviceManager.getDeviceCount(),
        mode: config.devices.useLegacyMode ? 'legacy' : 'dynamic',
      });

      // Register agent with backend
      if (!this.apiClient.agentToken) {
        logger.info('Registering agent with backend...');
        await this.registerAgent();
      } else {
        logger.info('Using existing agent token');
      }

      // Start WebSocket server
      logger.info('Starting WebSocket server...');
      await this.wsServer.start();
      logger.info('WebSocket server started', {
        port: config.websocket.port,
      });

      // Start HTTP server
      logger.info('Starting HTTP server...');
      this.httpServer.setApiClient(this.apiClient);
      await this.httpServer.start();
      logger.info('HTTP server started', {
        port: config.http.port,
      });

      // Start heartbeat
      this.startHeartbeat();

      // Start ConfigSyncService (if not in legacy mode)
      if (!config.devices.useLegacyMode && this.discoveryService) {
        const syncInterval = parseInt(process.env.CONFIG_SYNC_INTERVAL || '60');
        this.configSyncService = new ConfigSyncService(
          this.apiClient,
          this.deviceManager,
          this.discoveryService,
          config.agent.id,
          syncInterval
        );
        
        // Report discovered devices to backend on startup
        try {
          const discoveredDevices = await this.deviceManager.getDiscoveredDevices();
          if (discoveredDevices.length > 0) {
            await this.apiClient.reportDiscoveredDevices(
              config.agent.id,
              discoveredDevices
            );
            logger.info('Reported discovered devices to backend', {
              count: discoveredDevices.length,
            });
          }
        } catch (error: any) {
          logger.warn('Failed to report discovered devices', {
            error: error.message,
          });
        }
        
        await this.configSyncService.start();
      }

      logger.info('='.repeat(60));
      logger.info('✓ Agent started successfully!');
      logger.info('='.repeat(60));
      logger.info('Status:');
      logger.info(`  - Agent ID: ${config.agent.id}`);
      logger.info(`  - WebSocket: ws://${config.websocket.host}:${config.websocket.port}`);
      logger.info(`  - HTTP API: http://${config.http.host}:${config.http.port}`);
      logger.info(`  - Devices: ${this.deviceManager.getDeviceCount()}`);
      logger.info(`  - Backend: ${config.backend.url}`);
      logger.info('='.repeat(60));

    } catch (error: any) {
      logger.error('Failed to start agent', { error: error.message });
      throw error;
    }
  }

  private async registerAgent(): Promise<void> {
    try {
      const devices = this.deviceManager.getDevices();

      const registration = await this.apiClient.register({
        agent_id: config.agent.id,
        agent_name: config.agent.name,
        agent_version: config.agent.version,
        machine_id: this.getMachineId(),
        os_version: this.getOSVersion(),
        devices: devices.map(d => ({
          type: d.type,
          vendor: d.vendor,
          model: d.model,
          serial: d.serial,
        })),
      });

      logger.info('Agent registered successfully', {
        agentId: registration.agent_id,
        devicesRegistered: registration.devices?.length || 0,
      });

    } catch (error: any) {
      logger.error('Agent registration failed', { error: error.message });
      logger.warn('Continuing without backend registration (offline mode)');
      // Don't throw - allow agent to run in offline mode
    }
  }

  private startHeartbeat(): void {
    const interval = config.agent.heartbeatInterval * 1000;

    this.heartbeatInterval = setInterval(async () => {
      try {
        const devices = this.deviceManager.getDevices();
        
        await this.apiClient.sendHeartbeat({
          tenant: config.backend.tenant,
          agent_id: config.agent.id,
          uptime: Math.floor(process.uptime()),
          devices: devices.map(d => ({
            device_id: d.backendId || d.id,  // Use backend ID if mapped, otherwise local ID
            status: d.status,
            type: d.type,
            vendor: d.vendor,
            model: d.model,
            capabilities: d.driver?.getCapabilities ? d.driver.getCapabilities() : [],
            last_activity: d.driver?.getStatus ? d.driver.getStatus().lastActivity : undefined,
            error_code: d.driver?.getStatus ? d.driver.getStatus().errorCode : undefined,
          })),
          device_count: devices.length,
        });
      } catch (error: any) {
        logger.debug('Heartbeat failed', { error: error.message });
        // Don't log error - heartbeat failures are expected when offline
      }
    }, interval);

    logger.info('Heartbeat started', {
      interval: `${config.agent.heartbeatInterval}s`,
    });
  }

  private getMachineId(): string {
    // Use hostname as machine ID
    return os.hostname();
  }

  private getOSVersion(): string {
    return `${os.type()} ${os.release()}`;
  }

  async stop(): Promise<void> {
    logger.info('Stopping agent...');

    // Stop scanner listener
    if (this.scannerListener) {
      this.scannerListener.stopAll();
    }

    // Stop palm vein scanner
    if (this.palmVeinScanner) {
      this.palmVeinScanner.stopAll();
    }

    // Stop heartbeat
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    }

    // Stop config sync service
    if (this.configSyncService) {
      this.configSyncService.stop();
    }

    // Stop old config sync (if still running)
    if (this.configSyncInterval) {
      clearInterval(this.configSyncInterval);
      this.configSyncInterval = null;
    }

    // Unregister agent
    try {
      await this.apiClient.unregister(config.agent.id);
    } catch (error: any) {
      logger.warn('Failed to unregister agent', { error: error.message });
    }

    // Close HTTP server
    await this.httpServer.close();

    // Close WebSocket server
    await this.wsServer.close();

    // Close devices
    await this.deviceManager.close();

    logger.info('Agent stopped');
  }
}

// Main execution
const agent = new Agent();

// Handle graceful shutdown
process.on('SIGINT', async () => {
  logger.info('Received SIGINT, shutting down gracefully...');
  await agent.stop();
  process.exit(0);
});

process.on('SIGTERM', async () => {
  logger.info('Received SIGTERM, shutting down gracefully...');
  await agent.stop();
  process.exit(0);
});

// Handle uncaught errors
process.on('uncaughtException', (error) => {
  logger.error('Uncaught exception', { error: error.message, stack: error.stack });
  process.exit(1);
});

process.on('unhandledRejection', (reason, promise) => {
  logger.error('Unhandled rejection', { reason, promise });
  process.exit(1);
});

// Start agent
agent.start().catch((error) => {
  logger.error('Failed to start agent', { error: error.message });
  process.exit(1);
});
