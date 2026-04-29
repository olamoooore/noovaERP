import axios, { AxiosInstance } from 'axios';
import { logger } from '../utils/logger';
import { config, configManager } from '../config/config';
import { ConnectionError } from '../utils/errors';

export interface RegistrationData {
  agent_id: string;
  agent_name: string;
  agent_version: string;
  machine_id: string;
  os_version?: string;
  devices?: Array<{
    type: string;
    vendor: string;
    model?: string;
    serial?: string;
  }>;
}

export interface HeartbeatData {
  tenant: string;
  agent_id: string;
  uptime: number;
  devices: Array<{
    device_id: string;
    status: string;
    type?: string;
    vendor?: string;
    model?: string;
    capabilities?: string[];
    last_activity?: Date;
    error_code?: string;
  }>;
  device_count?: number;
}

export class ApiClient {
  private client: AxiosInstance;
  public agentToken: string = '';
  private readonly MAX_RETRIES = 3;
  private readonly RETRY_DELAY = 1000; // 1 second

  constructor(private baseUrl: string) {
    this.client = axios.create({
      baseURL: baseUrl,
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json',
      },
    });

    // Load existing token if available
    if (config.backend.apiKey) {
      this.agentToken = config.backend.apiKey;
    }
  }

  async register(data: RegistrationData): Promise<any> {
    try {
      logger.info('Registering agent with backend...', {
        agentId: data.agent_id,
        agentName: data.agent_name,
        tenant: config.backend.tenant,
      });

      const response = await this.client.post('/api/v1/iot/agent/register', {
        ...data,
        tenant: config.backend.tenant,
      });

      this.agentToken = response.data.agent_token;
      
      // Save token to config
      configManager.setToken(this.agentToken);

      logger.info('Agent registered successfully', {
        agentId: response.data.agent_id,
        devicesRegistered: response.data.devices?.length || 0,
      });

      return response.data;
    } catch (error: any) {
      logger.error('Agent registration failed', {
        error: error.message,
        response: error.response?.data,
      });
      throw new ConnectionError('Failed to register agent', error);
    }
  }

  async sendHeartbeat(data: HeartbeatData): Promise<void> {
    try {
      await this.client.post(`/api/v1/iot/agents/${data.agent_id}/heartbeat`, {
        tenant: config.backend.tenant,
        uptime: data.uptime,
        devices: data.devices,
        device_count: data.device_count,
      }, {
        headers: {
          'Authorization': `Bearer ${this.agentToken}`,
        },
      });

      logger.debug('Heartbeat sent successfully');
    } catch (error: any) {
      logger.error('Heartbeat failed', {
        error: error.message,
        agentId: data.agent_id,
      });
      // Don't throw - heartbeat failures shouldn't crash the agent
    }
  }

  async unregister(agentId: string): Promise<void> {
    try {
      await this.client.post('/api/v1/iot/agent/unregister', {
        tenant: config.backend.tenant,
        agent_id: agentId,
      }, {
        headers: {
          'Authorization': `Bearer ${this.agentToken}`,
        },
      });

      logger.info('Agent unregistered successfully');
    } catch (error: any) {
      logger.error('Agent unregistration failed', { error: error.message });
      throw error;
    }
  }

  async sendWebhook(endpoint: string, payload: any): Promise<void> {
    try {
      await this.client.post(endpoint, payload, {
        headers: {
          'Authorization': `Bearer ${this.agentToken}`,
        },
      });

      logger.debug('Webhook sent successfully', { endpoint });
    } catch (error: any) {
      logger.error('Webhook failed', {
        error: error.message,
        endpoint,
      });
      throw error;
    }
  }

  validateToken(token: string): boolean {
    // Validate token against agent's API key
    if (!token || token.length === 0) {
      return false;
    }
    
    // Accept the agent's own token
    if (this.agentToken && token === this.agentToken) {
      return true;
    }
    
    // For development/testing, accept any non-empty token
    // TODO: Implement proper JWT validation for production
    return true;
  }

  // ============================================================================
  // Device Configuration Management
  // ============================================================================

  /**
   * Fetch device configurations for an agent
   */
  async fetchDeviceConfigurations(agentId: string): Promise<any> {
    return this.retryRequest(async () => {
      logger.debug('Fetching device configurations', { agentId });

      const response = await this.client.get(
        `/api/v1/iot/agents/${agentId}/device-configs`,
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
            'X-Tenant': config.backend.tenant,
          },
        }
      );

      // Backend returns { success: true, data: [...] }
      return response.data.data || [];
    }, 'fetchDeviceConfigurations');
  }

  /**
   * Report discovered devices to backend
   */
  async reportDiscoveredDevices(agentId: string, devices: any[]): Promise<any> {
    return this.retryRequest(async () => {
      logger.debug('Reporting discovered devices', {
        agentId,
        count: devices.length,
      });

      const response = await this.client.post(
        `/api/v1/iot/agents/${agentId}/discovered-devices`,
        { devices },
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
            'X-Tenant': config.backend.tenant,
          },
        }
      );

      return response.data;
    }, 'reportDiscoveredDevices');
  }

  /**
   * Update device status
   */
  async updateDeviceStatus(
    agentId: string,
    deviceId: string,
    status: string,
    message?: string
  ): Promise<void> {
    try {
      await this.client.post(
        `/api/v1/iot/agents/${agentId}/device-status`,
        {
          device_id: deviceId,
          status,
          message,
          timestamp: Date.now(),
        },
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
            'X-Tenant': config.backend.tenant,
          },
        }
      );

      logger.debug('Device status updated', { agentId, deviceId, status });
    } catch (error: any) {
      logger.error('Failed to update device status', {
        error: error.message,
        agentId,
        deviceId,
      });
      // Don't throw - status updates shouldn't crash the agent
    }
  }

  /**
   * Fetch device templates
   */
  async fetchDeviceTemplates(filters?: {
    device_type?: string;
    vendor_id?: string;
    product_id?: string;
  }): Promise<any> {
    return this.retryRequest(async () => {
      const params = new URLSearchParams();
      if (filters?.device_type) params.append('device_type', filters.device_type);
      if (filters?.vendor_id) params.append('vendor_id', filters.vendor_id);
      if (filters?.product_id) params.append('product_id', filters.product_id);

      const response = await this.client.get(
        `/api/v1/iot/device-templates?${params.toString()}`,
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
          },
        }
      );

      return response.data;
    }, 'fetchDeviceTemplates');
  }

  /**
   * Report print job result to backend
   */
  async reportPrintJobResult(
    agentId: string,
    data: {
      job_id: string;
      device_id: string;
      status: 'completed' | 'failed';
      error?: string;
    }
  ): Promise<void> {
    try {
      logger.info('Reporting print job result to backend', {
        agentId,
        jobId: data.job_id,
        deviceId: data.device_id,
        status: data.status,
      });

      await this.client.post(
        `/api/v1/iot/agents/${agentId}/printer/job-result`,
        data,
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
            'X-Tenant': config.backend.tenant,
          },
        }
      );

      logger.debug('Print job result reported successfully', {
        agentId,
        jobId: data.job_id,
        status: data.status,
      });
    } catch (error: any) {
      logger.error('Failed to report print job result', {
        error: error.message,
        agentId,
        jobId: data.job_id,
        status: error.response?.status,
        responseData: error.response?.data,
      });
      // Don't throw - job result reporting shouldn't crash the agent
    }
  }

  /**
   * Report scan result to backend
   */
  async reportScanResult(
    agentId: string,
    data: {
      device_id: string;
      barcode: string;
      format: string;
      timestamp: string; // ISO string format
      parsed?: {
        type: string;
        data: any;
      };
    }
  ): Promise<void> {
    try {
      logger.info('Reporting scan result to backend', {
        agentId,
        deviceId: data.device_id,
        barcode: data.barcode.substring(0, 100),
        format: data.format,
        hasParsed: !!data.parsed,
        url: `${this.baseUrl}/api/v1/iot/agents/${agentId}/scanner/scan-result`,
      });

      const response = await this.client.post(
        `/api/v1/iot/agents/${agentId}/scanner/scan-result`,
        data,
        {
          headers: {
            'Authorization': `Bearer ${this.agentToken}`,
            'X-Tenant': config.backend.tenant,
          },
        }
      );

      logger.debug('Scan result reported successfully', {
        agentId,
        deviceId: data.device_id,
        response: response.data,
      });
    } catch (error: any) {
      logger.error('Failed to report scan result', {
        error: error.message,
        agentId,
        deviceId: data.device_id,
        status: error.response?.status,
        statusText: error.response?.statusText,
        responseData: error.response?.data,
        requestData: {
          ...data,
          barcode: data.barcode.substring(0, 100),
        },
      });
      // Don't throw - scan result reporting shouldn't crash the agent
    }
  }

  /**
   * Retry a request with exponential backoff
   * 
   * @param requestFn - Function that makes the request
   * @param operationName - Name of the operation for logging
   * @returns Response data
   */
  private async retryRequest<T>(
    requestFn: () => Promise<T>,
    operationName: string
  ): Promise<T> {
    let lastError: any;

    for (let attempt = 1; attempt <= this.MAX_RETRIES; attempt++) {
      try {
        return await requestFn();
      } catch (error: any) {
        lastError = error;

        // Check if error is retryable
        const isRetryable = this.isRetryableError(error);

        if (!isRetryable || attempt === this.MAX_RETRIES) {
          logger.error(`${operationName} failed after ${attempt} attempt(s)`, {
            error: error.message,
            response: error.response?.data,
          });
          throw error;
        }

        // Calculate delay with exponential backoff
        const delay = this.RETRY_DELAY * Math.pow(2, attempt - 1);

        logger.warn(`${operationName} failed, retrying in ${delay}ms`, {
          attempt,
          maxRetries: this.MAX_RETRIES,
          error: error.message,
        });

        // Wait before retrying
        await new Promise(resolve => setTimeout(resolve, delay));
      }
    }

    throw lastError;
  }

  /**
   * Check if an error is retryable
   * 
   * @param error - Error to check
   * @returns True if error is retryable
   */
  private isRetryableError(error: any): boolean {
    // Network errors are retryable
    if (error.code === 'ECONNREFUSED' || error.code === 'ETIMEDOUT') {
      return true;
    }

    // 5xx server errors are retryable
    if (error.response?.status >= 500) {
      return true;
    }

    // 429 Too Many Requests is retryable
    if (error.response?.status === 429) {
      return true;
    }

    // 4xx client errors are not retryable (except 429)
    if (error.response?.status >= 400 && error.response?.status < 500) {
      return false;
    }

    // Default to not retryable
    return false;
  }
}
