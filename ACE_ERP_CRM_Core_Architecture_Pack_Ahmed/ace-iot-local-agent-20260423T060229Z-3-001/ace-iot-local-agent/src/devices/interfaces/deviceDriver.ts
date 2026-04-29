/**
 * Device Driver Interface
 * 
 * All device drivers must implement this interface to ensure consistent
 * behavior and enable dynamic driver loading.
 */

/**
 * Device status states
 */
export type DeviceState = 'ready' | 'busy' | 'offline' | 'error';

/**
 * Device status information
 */
export interface DeviceStatus {
  state: DeviceState;
  message?: string;
  lastActivity?: Date;
  errorCode?: string;
}

/**
 * Device capability - actions that a device can perform
 */
export type DeviceCapability = 
  | 'print_label'
  | 'print_raw_tspl'
  | 'print_receipt'
  | 'print_raw_escpos'
  | 'scan_barcode'
  | 'scan_qr'
  | 'scan_palm_vein'
  | 'parse_json'
  | 'create_payment'
  | 'check_payment_status'
  | 'cancel_payment'
  | 'get_status'
  | 'open_cash_drawer';

/**
 * Device Driver Interface
 * 
 * All device drivers must implement this interface.
 * 
 * @example
 * ```typescript
 * class MyPrinterDriver implements DeviceDriver {
 *   async initialize(config: any): Promise<void> {
 *     // Initialize printer connection
 *   }
 *   
 *   async executeCommand(action: string, data: any): Promise<any> {
 *     if (action === 'print_label') {
 *       return await this.printLabel(data);
 *     }
 *     throw new Error(`Unsupported action: ${action}`);
 *   }
 *   
 *   getCapabilities(): DeviceCapability[] {
 *     return ['print_label', 'get_status'];
 *   }
 *   
 *   getStatus(): DeviceStatus {
 *     return { state: 'ready' };
 *   }
 *   
 *   async close(): Promise<void> {
 *     // Cleanup resources
 *   }
 * }
 * ```
 */
export interface DeviceDriver {
  /**
   * Initialize the device driver with configuration
   * 
   * This method is called once when the device is registered.
   * Use this to establish connections, load settings, etc.
   * 
   * @param config - Driver-specific configuration object
   * @throws Error if initialization fails
   */
  initialize(config: any): Promise<void>;

  /**
   * Execute a command on the device
   * 
   * This is the main method for device operations.
   * The action parameter determines what operation to perform.
   * 
   * @param action - The action to perform (e.g., 'print_label', 'scan_barcode')
   * @param data - Action-specific data
   * @returns Action-specific result
   * @throws Error if command fails or action is not supported
   * 
   * @example
   * ```typescript
   * // Print a label
   * await driver.executeCommand('print_label', {
   *   productName: 'Widget',
   *   sku: 'WDG-001',
   *   price: '19.99'
   * });
   * 
   * // Get device status
   * const status = await driver.executeCommand('get_status', {});
   * ```
   */
  executeCommand(action: string, data: any): Promise<any>;

  /**
   * Get the list of capabilities this device supports
   * 
   * Capabilities are used by the backend to:
   * - Validate commands before sending them
   * - Display available actions in the UI
   * - Route commands to appropriate devices
   * 
   * @returns Array of capability strings
   * 
   * @example
   * ```typescript
   * // Label printer capabilities
   * getCapabilities() {
   *   return ['print_label', 'get_status'];
   * }
   * 
   * // Payment terminal capabilities
   * getCapabilities() {
   *   return ['create_payment', 'check_payment_status', 'cancel_payment'];
   * }
   * ```
   */
  getCapabilities(): DeviceCapability[];

  /**
   * Get the current status of the device
   * 
   * This method should be fast and non-blocking.
   * It's called frequently for monitoring purposes.
   * 
   * @returns Current device status
   * 
   * @example
   * ```typescript
   * getStatus() {
   *   return {
   *     state: 'ready',
   *     message: 'Printer is ready',
   *     lastActivity: new Date()
   *   };
   * }
   * ```
   */
  getStatus(): DeviceStatus;

  /**
   * Close the device driver and cleanup resources
   * 
   * This method is called when:
   * - The device is being removed
   * - The device configuration is being updated
   * - The agent is shutting down
   * 
   * Use this to:
   * - Close connections
   * - Release hardware resources
   * - Save state if needed
   * 
   * @throws Error if cleanup fails (will be logged but not propagated)
   */
  close(): Promise<void>;
}

/**
 * Base class for device drivers (optional)
 * 
 * Provides common functionality that most drivers need.
 * Drivers can extend this class or implement DeviceDriver directly.
 */
export abstract class BaseDeviceDriver implements DeviceDriver {
  protected status: DeviceStatus = {
    state: 'offline',
  };

  abstract initialize(config: any): Promise<void>;
  abstract executeCommand(action: string, data: any): Promise<any>;
  abstract getCapabilities(): DeviceCapability[];

  getStatus(): DeviceStatus {
    return { ...this.status };
  }

  async close(): Promise<void> {
    this.status.state = 'offline';
  }

  /**
   * Helper method to update device status
   */
  protected updateStatus(state: DeviceState, message?: string, errorCode?: string): void {
    this.status = {
      state,
      message,
      errorCode,
      lastActivity: new Date(),
    };
  }

  /**
   * Helper method to validate that an action is supported
   */
  protected validateAction(action: string): void {
    const capabilities = this.getCapabilities();
    if (!capabilities.includes(action as DeviceCapability)) {
      throw new Error(`Unsupported action: ${action}. Supported actions: ${capabilities.join(', ')}`);
    }
  }
}
