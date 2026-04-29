import { logger } from '../../utils/logger';
import { DeviceError, ErrorCodes } from '../../utils/errors';
import { v4 as uuidv4 } from 'uuid';
import { BaseDeviceDriver, DeviceCapability } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';

/**
 * Mock Terminal Driver
 * 
 * Simulates a payment terminal for testing without real hardware.
 * Replace with real vendor drivers (Ingenico, Verifone, PAX) in production.
 */
export class MockTerminalDriver extends BaseDeviceDriver {
  private config?: DeviceConfiguration;
  private vendor: string = 'mock';

  constructor() {
    super();
  }

  async initialize(config: DeviceConfiguration): Promise<void> {
    logger.info('Initializing mock terminal', {
      deviceId: config.id,
      deviceName: config.name,
    });

    this.config = config;
    this.vendor = config.metadata?.vendor || 'mock';

    // Simulate initialization delay
    await this.delay(500);

    this.updateStatus('ready', 'Mock terminal initialized successfully');
    logger.info('Mock terminal initialized', {
      deviceId: config.id,
      vendor: this.vendor,
    });
  }

  getCapabilities(): DeviceCapability[] {
    return ['create_payment', 'check_payment_status', 'cancel_payment', 'get_status'];
  }

  async executeCommand(action: string, data: any): Promise<any> {
    logger.info('Executing command on mock terminal', {
      deviceId: this.config?.id,
      action,
      data,
    });

    // Validate action is supported
    this.validateAction(action);

    switch (action) {
      case 'create_payment':
        return await this.createPayment(data);
      case 'cancel_payment':
        return await this.cancelPayment(data);
      case 'check_payment_status':
        return await this.checkStatus(data);
      case 'get_status':
        return this.getStatus();
      default:
        throw new DeviceError(
          `Unknown action: ${action}`,
          ErrorCodes.COMMAND_FAILED
        );
    }
  }

  private async createPayment(data: any): Promise<any> {
    const { amount, currency, orderId } = data;

    logger.info('Creating mock payment', {
      deviceId: this.config?.id,
      amount,
      currency,
      orderId,
    });

    this.updateStatus('busy', 'Processing payment...');

    // Simulate payment processing
    await this.delay(2000);

    // Simulate success (90% success rate)
    const success = Math.random() > 0.1;

    if (!success) {
      this.updateStatus('ready', 'Payment declined');
      throw new DeviceError(
        'Payment declined by card issuer',
        ErrorCodes.PAYMENT_DECLINED,
        { reason: 'insufficient_funds' }
      );
    }

    const transactionId = `txn-mock-${uuidv4()}`;

    this.updateStatus('ready', 'Payment successful');
    logger.info('Mock payment successful', {
      deviceId: this.config?.id,
      transactionId,
      amount,
    });

    return {
      success: true,
      transactionId,
      amount,
      currency,
      cardType: 'visa',
      last4: '4242',
      authCode: '123456',
      timestamp: new Date().toISOString(),
    };
  }

  private async cancelPayment(data: any): Promise<any> {
    const { transactionId } = data;

    logger.info('Cancelling mock payment', {
      deviceId: this.config?.id,
      transactionId,
    });

    this.updateStatus('busy', 'Cancelling payment...');

    // Simulate cancellation delay
    await this.delay(500);

    this.updateStatus('ready', 'Payment cancelled');

    return {
      success: true,
      transactionId,
      cancelled: true,
    };
  }

  private async checkStatus(data: any): Promise<any> {
    const { transactionId } = data;

    logger.info('Checking mock payment status', {
      deviceId: this.config?.id,
      transactionId,
    });

    return {
      transactionId,
      status: 'completed',
      amount: 150.00,
      currency: 'SGD',
    };
  }

  async close(): Promise<void> {
    logger.info('Closing mock terminal', {
      deviceId: this.config?.id,
    });
    this.updateStatus('offline', 'Driver closed');
    await super.close();
  }

  private delay(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
  }
}
