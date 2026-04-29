import WebSocket from 'ws';
import { v4 as uuidv4 } from 'uuid';
import { logger } from '../utils/logger';
import { DeviceManager } from '../devices/deviceManager';
import { ApiClient } from '../api/client';
import { AgentError } from '../utils/errors';

export interface AgentMessage {
  id: string;
  type: 'auth' | 'auth_response' | 'command' | 'event' | 'response' | 'error' | 'ping' | 'pong' | 'device_config_update' | 'device_config_remove' | 'device_reload' | 'devices_reload_all' | 'list_devices' | 'list_discovered';
  deviceId?: string;
  action?: string;
  data?: any;
  timestamp: number;
  correlationId?: string;
}

export class WebSocketServer {
  private wss: WebSocket.Server | null = null;
  private clients: Set<WebSocket> = new Set();

  constructor(
    private port: number,
    private deviceManager: DeviceManager,
    private apiClient: ApiClient
  ) {}

  async start(): Promise<void> {
    this.wss = new WebSocket.Server({ port: this.port });

    this.wss.on('connection', (ws: WebSocket) => {
      logger.info('Client connected to WebSocket');
      this.clients.add(ws);

      ws.on('message', async (data: Buffer) => {
        try {
          const message: AgentMessage = JSON.parse(data.toString());
          await this.handleMessage(ws, message);
        } catch (error: any) {
          logger.error('Message handling error', { error: error.message });
          this.sendError(ws, 'INVALID_MESSAGE', 'Failed to process message');
        }
      });

      ws.on('close', () => {
        logger.info('Client disconnected from WebSocket');
        this.clients.delete(ws);
      });

      ws.on('error', (error) => {
        logger.error('WebSocket error', { error: error.message });
        this.clients.delete(ws);
      });
    });

    logger.info('WebSocket server started', { port: this.port });
  }

  private async handleMessage(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { type, deviceId, action, data, id } = message;

    logger.debug('Received message', { type, action, deviceId });

    try {
      switch (type) {
        case 'auth':
          await this.handleAuth(ws, message);
          break;

        case 'command':
          await this.handleCommand(ws, message);
          break;

        case 'ping':
          this.handlePing(ws, message);
          break;

        case 'device_config_update':
          await this.handleDeviceConfigUpdate(ws, message);
          break;

        case 'device_config_remove':
          await this.handleDeviceConfigRemove(ws, message);
          break;

        case 'device_reload':
          await this.handleDeviceReload(ws, message);
          break;

        case 'devices_reload_all':
          await this.handleDevicesReloadAll(ws, message);
          break;

        case 'list_devices':
          await this.handleListDevices(ws, message);
          break;

        case 'list_discovered':
          await this.handleListDiscovered(ws, message);
          break;

        default:
          this.sendError(ws, 'UNKNOWN_MESSAGE_TYPE', `Unknown message type: ${type}`, id);
      }
    } catch (error: any) {
      logger.error('Message handler error', {
        type,
        action,
        error: error.message,
      });
      this.sendError(ws, error.code || 'HANDLER_ERROR', error.message, id);
    }
  }

  private async handleAuth(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { data, id } = message;

    // Validate token
    const valid = this.apiClient.validateToken(data?.token);

    if (valid) {
      logger.info('Client authenticated successfully');
      
      // Send auth_response message (not generic response)
      this.send(ws, {
        id: uuidv4(),
        type: 'auth_response',
        data: {
          authenticated: true,
          agentId: this.apiClient.agentToken ? 'authenticated' : 'guest',
          devices: this.deviceManager.getDevices().map(d => ({
            id: d.id,
            type: d.type,
            vendor: d.vendor,
            status: d.status,
          })),
        },
        timestamp: Date.now(),
        correlationId: id,
      });
    } else {
      logger.warn('Client authentication failed');
      this.sendError(ws, 'AUTH_FAILED', 'Invalid token', id);
    }
  }

  private async handleCommand(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { deviceId, action, data, id } = message;

    if (!deviceId || !action) {
      this.sendError(ws, 'INVALID_COMMAND', 'Missing deviceId or action', id);
      return;
    }

    try {
      // Send status event
      this.sendEvent(ws, deviceId, 'command_received', {
        action,
        status: 'processing',
      }, id);

      // Execute command
      const result = await this.deviceManager.executeCommand(deviceId, action, data);

      // Send success response
      this.sendResponse(ws, id!, action, result);

      // Broadcast event to all clients
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        deviceId,
        action: `${action}_success`,
        data: result,
        timestamp: Date.now(),
        correlationId: id,
      });

    } catch (error: any) {
      logger.error('Command execution failed', {
        deviceId,
        action,
        error: error.message,
      });

      this.sendError(ws, error.code || 'COMMAND_FAILED', error.message, id);

      // Broadcast error event
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        deviceId,
        action: `${action}_failed`,
        data: {
          error: error.message,
          code: error.code,
        },
        timestamp: Date.now(),
        correlationId: id,
      });
    }
  }

  private handlePing(ws: WebSocket, message: AgentMessage): void {
    this.send(ws, {
      id: uuidv4(),
      type: 'pong',
      data: {
        agentId: this.apiClient.agentToken ? 'authenticated' : 'guest',
        uptime: process.uptime(),
        devices: this.deviceManager.getDeviceCount(),
      },
      timestamp: Date.now(),
      correlationId: message.id,
    });
  }

  /**
   * Handle device configuration update
   */
  private async handleDeviceConfigUpdate(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { data, id } = message;

    // Extract config from data (it might be nested as data.config or directly in data)
    const config = data?.config || data;

    logger.info('Received device configuration update', {
      deviceId: config?.id,
      name: config?.name,
    });

    try {
      // Validate configuration
      if (!config || !config.id) {
        throw new Error('Invalid configuration: missing device ID');
      }

      // Register or update device
      await this.deviceManager.registerDevice(config);

      // Send success response
      this.sendResponse(ws, id!, 'device_config_updated', {
        deviceId: config.id,
        success: true,
      });

      // Broadcast event to all clients
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        deviceId: config.id,
        action: 'device_config_updated',
        data: {
          deviceId: config.id,
          name: config.name,
        },
        timestamp: Date.now(),
        correlationId: id,
      });

    } catch (error: any) {
      logger.error('Failed to update device configuration', {
        error: error.message,
      });
      this.sendError(ws, 'CONFIG_UPDATE_FAILED', error.message, id);
    }
  }

  /**
   * Handle device configuration removal
   */
  private async handleDeviceConfigRemove(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { data, id } = message;

    logger.info('Received device configuration removal', {
      deviceId: data?.deviceId,
    });

    try {
      // Validate request
      if (!data || !data.deviceId) {
        throw new Error('Invalid request: missing device ID');
      }

      // Remove device
      await this.deviceManager.removeDevice(data.deviceId);

      // Send success response
      this.sendResponse(ws, id!, 'device_config_removed', {
        deviceId: data.deviceId,
        success: true,
      });

      // Broadcast event to all clients
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        deviceId: data.deviceId,
        action: 'device_config_removed',
        data: {
          deviceId: data.deviceId,
        },
        timestamp: Date.now(),
        correlationId: id,
      });

    } catch (error: any) {
      logger.error('Failed to remove device configuration', {
        error: error.message,
      });
      this.sendError(ws, 'CONFIG_REMOVE_FAILED', error.message, id);
    }
  }

  /**
   * Handle device reload
   */
  private async handleDeviceReload(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { data, id } = message;

    logger.info('Received device reload request', {
      deviceId: data?.deviceId,
    });

    try {
      // Validate request
      if (!data || !data.deviceId) {
        throw new Error('Invalid request: missing device ID');
      }

      // Reload device
      await this.deviceManager.reloadDevice(data.deviceId);

      // Send success response
      this.sendResponse(ws, id!, 'device_reloaded', {
        deviceId: data.deviceId,
        success: true,
      });

      // Broadcast event to all clients
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        deviceId: data.deviceId,
        action: 'device_reloaded',
        data: {
          deviceId: data.deviceId,
        },
        timestamp: Date.now(),
        correlationId: id,
      });

    } catch (error: any) {
      logger.error('Failed to reload device', {
        error: error.message,
      });
      this.sendError(ws, 'DEVICE_RELOAD_FAILED', error.message, id);
    }
  }

  /**
   * Handle reload all devices
   */
  private async handleDevicesReloadAll(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { id } = message;

    logger.info('Received reload all devices request');

    try {
      const devices = this.deviceManager.getDevices();
      const results = [];

      for (const device of devices) {
        try {
          await this.deviceManager.reloadDevice(device.id);
          results.push({ deviceId: device.id, success: true });
        } catch (error: any) {
          logger.error('Failed to reload device', {
            deviceId: device.id,
            error: error.message,
          });
          results.push({
            deviceId: device.id,
            success: false,
            error: error.message,
          });
        }
      }

      // Send success response
      this.sendResponse(ws, id!, 'devices_reloaded_all', {
        success: true,
        results,
        total: devices.length,
        reloaded: results.filter(r => r.success).length,
        failed: results.filter(r => !r.success).length,
      });

      // Broadcast event to all clients
      this.broadcast({
        id: uuidv4(),
        type: 'event',
        action: 'devices_reloaded_all',
        data: {
          total: devices.length,
          successful: results.filter(r => r.success).length,
        },
        timestamp: Date.now(),
        correlationId: id,
      });

    } catch (error: any) {
      logger.error('Failed to reload all devices', {
        error: error.message,
      });
      this.sendError(ws, 'DEVICES_RELOAD_ALL_FAILED', error.message, id);
    }
  }

  /**
   * Handle list devices request
   */
  private async handleListDevices(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { id } = message;

    logger.info('Received list devices request');

    try {
      const devices = this.deviceManager.getDevices();

      // Send response with device list
      this.sendResponse(ws, id!, 'devices_list', {
        success: true,
        devices: devices.map(d => ({
          device_id: d.id,
          type: d.type,
          vendor: d.vendor,
          model: d.model,
          serial: d.serial,
          status: d.status,
        })),
        count: devices.length,
      });

    } catch (error: any) {
      logger.error('Failed to list devices', {
        error: error.message,
      });
      this.sendError(ws, 'LIST_DEVICES_FAILED', error.message, id);
    }
  }

  /**
   * Handle list discovered devices request
   */
  private async handleListDiscovered(ws: WebSocket, message: AgentMessage): Promise<void> {
    const { id } = message;

    logger.info('Received list discovered devices request');

    try {
      const discovered = await this.deviceManager.discoverDevices();

      // Send response with discovered devices
      this.sendResponse(ws, id!, 'discovered_devices_list', {
        success: true,
        discovered: discovered,
        count: discovered.length,
      });

    } catch (error: any) {
      logger.error('Failed to list discovered devices', {
        error: error.message,
      });
      this.sendError(ws, 'LIST_DISCOVERED_FAILED', error.message, id);
    }
  }

  private sendResponse(ws: WebSocket, correlationId: string, action: string, data: any): void {
    this.send(ws, {
      id: uuidv4(),
      type: 'response',
      action,
      data,
      timestamp: Date.now(),
      correlationId,
    });
  }

  private sendEvent(ws: WebSocket, deviceId: string, action: string, data: any, correlationId?: string): void {
    this.send(ws, {
      id: uuidv4(),
      type: 'event',
      deviceId,
      action,
      data,
      timestamp: Date.now(),
      correlationId,
    });
  }

  private sendError(ws: WebSocket, code: string, message: string, correlationId?: string): void {
    this.send(ws, {
      id: uuidv4(),
      type: 'error',
      data: { code, message },
      timestamp: Date.now(),
      correlationId,
    });
  }

  private send(ws: WebSocket, message: AgentMessage): void {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify(message));
    }
  }

  broadcast(message: AgentMessage): void {
    const data = JSON.stringify(message);
    this.clients.forEach((client) => {
      if (client.readyState === WebSocket.OPEN) {
        client.send(data);
      }
    });
  }

  async close(): Promise<void> {
    logger.info('Closing WebSocket server...');

    if (this.wss) {
      this.wss.close();
      this.wss = null;
    }

    this.clients.clear();
    logger.info('WebSocket server closed');
  }
}
