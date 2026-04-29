/**
 * TSPL Label Printer Driver
 * 
 * Supports TSPL protocol printers (Gprinter, TSC, etc.)
 * Uses Windows RAW printing for USB connectivity
 */

import { DeviceDriver, DeviceStatus, DeviceCapability } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';
import { exec } from 'child_process';
import { promisify } from 'util';
import * as fs from 'fs';
import * as path from 'path';
import { v4 as uuidv4 } from 'uuid';

const execAsync = promisify(exec);

export interface ProductLabelData {
  productName: string;
  barcode: string;
  price?: string;
  sku?: string;
  quantity?: number;
}

export interface BarcodeLabelData {
  barcode: string;
  barcodeType?: 'CODE128' | 'EAN13' | 'QR';
  text?: string;
  quantity?: number;
}

export interface PrintResult {
  success: boolean;
  jobId?: string;
  error?: string;
}

export interface PrinterStatus {
  state: 'ready' | 'busy' | 'offline' | 'error';
  message?: string;
  paperStatus?: 'ok' | 'low' | 'out';
  errorCode?: string;
}

export interface TsplConfig {
  labelWidth: number;  // mm
  labelHeight: number; // mm
  gap: number;         // mm
  dpi: number;
  protocol: string;
}

export class LabelPrinterTsplDriver implements DeviceDriver {
  private config?: DeviceConfiguration;
  private status: DeviceStatus = {
    state: 'offline',
  };
  private printerName: string = '';
  private tsplConfig: TsplConfig = {
    labelWidth: 76,
    labelHeight: 50,
    gap: 2,
    dpi: 203,
    protocol: 'tspl',
  };
  private tempDir: string;
  private isPrinting: boolean = false;

  constructor() {
    this.tempDir = path.join(process.cwd(), 'temp');
    
    // Ensure temp directory exists
    if (!fs.existsSync(this.tempDir)) {
      fs.mkdirSync(this.tempDir, { recursive: true });
    }
  }

  async initialize(config: DeviceConfiguration): Promise<void> {
    this.config = config;

    logger.info('TSPL Label Printer driver initializing', {
      deviceId: config.id,
      deviceName: config.name,
    });

    try {
      // Get printer name from config
      this.printerName = config.connectionInfo?.printerName || config.name;

      // Load TSPL configuration from device template
      if (config.driverConfig) {
        this.tsplConfig = {
          labelWidth: config.driverConfig.labelWidth || 76,
          labelHeight: config.driverConfig.labelHeight || 50,
          gap: config.driverConfig.gap || 2,
          dpi: config.driverConfig.dpi || 203,
          protocol: config.driverConfig.protocol || 'tspl',
        };
      }

      // Verify printer exists
      await this.verifyPrinter();

      this.status = {
        state: 'ready',
        message: 'TSPL label printer ready',
        lastActivity: new Date(),
      };

      logger.info('TSPL Label Printer initialized', {
        deviceId: config.id,
        printerName: this.printerName,
        labelSize: `${this.tsplConfig.labelWidth}x${this.tsplConfig.labelHeight}mm`,
      });

    } catch (error: any) {
      logger.error('Failed to initialize TSPL label printer', {
        deviceId: config.id,
        error: error.message,
      });

      this.status = {
        state: 'error',
        message: `Initialization failed: ${error.message}`,
        errorCode: 'INIT_FAILED',
        lastActivity: new Date(),
      };

      throw error;
    }
  }

  async executeCommand(action: string, data?: any): Promise<any> {
    logger.debug('TSPL printer command received', {
      deviceId: this.config?.id,
      action,
    });

    switch (action) {
      case 'get_status':
        return this.getStatus();

      case 'print_product_label':
        return await this.printProductLabel(data);

      case 'print_barcode_label':
        return await this.printBarcodeLabel(data);

      case 'print_raw_tspl':
        return await this.printRawTspl(data?.commands || data);

      case 'test_print':
        return await this.testPrint();

      default:
        logger.warn('Unknown command for TSPL printer', {
          deviceId: this.config?.id,
          action,
        });
        return {
          success: false,
          error: `Unknown command: ${action}`,
        };
    }
  }

  /**
   * Print product label with name, barcode, price, and SKU
   */
  async printProductLabel(data: ProductLabelData): Promise<PrintResult> {
    if (this.isPrinting) {
      return {
        success: false,
        error: 'Printer is busy',
      };
    }

    try {
      this.isPrinting = true;
      this.status.state = 'busy';
      this.status.message = 'Printing product label...';

      logger.info('Printing product label', {
        deviceId: this.config?.id,
        productName: data.productName,
        barcode: data.barcode,
      });

      // Generate TSPL commands for product label
      const commands = this.generateProductLabelTspl(data);

      // Print the label
      const result = await this.sendToPrinter(commands);

      logger.info('Product label printed', {
        deviceId: this.config?.id,
        jobId: result.jobId,
      });

      return result;

    } catch (error: any) {
      logger.error('Failed to print product label', {
        deviceId: this.config?.id,
        error: error.message,
      });

      return {
        success: false,
        error: error.message,
      };

    } finally {
      this.isPrinting = false;
      this.status.state = 'ready';
      this.status.message = 'Ready';
      this.status.lastActivity = new Date();
    }
  }

  /**
   * Print barcode-only label
   */
  async printBarcodeLabel(data: BarcodeLabelData): Promise<PrintResult> {
    if (this.isPrinting) {
      return {
        success: false,
        error: 'Printer is busy',
      };
    }

    try {
      this.isPrinting = true;
      this.status.state = 'busy';
      this.status.message = 'Printing barcode label...';

      logger.info('Printing barcode label', {
        deviceId: this.config?.id,
        barcode: data.barcode,
        type: data.barcodeType,
      });

      // Generate TSPL commands for barcode label
      const commands = this.generateBarcodeLabelTspl(data);

      // Print the label
      const result = await this.sendToPrinter(commands);

      logger.info('Barcode label printed', {
        deviceId: this.config?.id,
        jobId: result.jobId,
      });

      return result;

    } catch (error: any) {
      logger.error('Failed to print barcode label', {
        deviceId: this.config?.id,
        error: error.message,
      });

      return {
        success: false,
        error: error.message,
      };

    } finally {
      this.isPrinting = false;
      this.status.state = 'ready';
      this.status.message = 'Ready';
      this.status.lastActivity = new Date();
    }
  }

  /**
   * Print raw TSPL commands
   */
  async printRawTspl(commands: string): Promise<PrintResult> {
    if (this.isPrinting) {
      return {
        success: false,
        error: 'Printer is busy',
      };
    }

    try {
      this.isPrinting = true;
      this.status.state = 'busy';
      this.status.message = 'Printing raw TSPL...';

      logger.info('Printing raw TSPL commands', {
        deviceId: this.config?.id,
      });

      // Print the commands
      const result = await this.sendToPrinter(commands);

      logger.info('Raw TSPL printed', {
        deviceId: this.config?.id,
        jobId: result.jobId,
      });

      return result;

    } catch (error: any) {
      logger.error('Failed to print raw TSPL', {
        deviceId: this.config?.id,
        error: error.message,
      });

      return {
        success: false,
        error: error.message,
      };

    } finally {
      this.isPrinting = false;
      this.status.state = 'ready';
      this.status.message = 'Ready';
      this.status.lastActivity = new Date();
    }
  }

  /**
   * Generate TSPL commands for product label
   */
  private generateProductLabelTspl(data: ProductLabelData): string {
    const { labelWidth, labelHeight, gap } = this.tsplConfig;
    
    // Calculate center position (label width in dots at 203 DPI)
    // 76mm = ~607 dots, center at ~180 dots from left
    const centerX = 180;
    
    // Calculate total content height and center vertically
    // 50mm label = ~400 dots at 203 DPI
    const labelHeightDots = Math.floor(labelHeight * this.tsplConfig.dpi / 25.4);
    
    // Estimate content height: name(40) + sku(35) + price(40) + barcode(100) = ~215 dots
    let contentHeight = 40; // Product name
    if (data.sku) contentHeight += 35;
    if (data.price) contentHeight += 40;
    contentHeight += 100; // Barcode with text
    
    // Start Y position to center content vertically (add 50 dots offset to push down)
    let currentY = Math.floor((labelHeightDots - contentHeight) / 2) + 50;
    if (currentY < 20) currentY = 20; // Minimum margin
    
    let tspl = '';
    
    // Label setup
    tspl += `SIZE ${labelWidth} mm, ${labelHeight} mm\n`;
    tspl += `GAP ${gap} mm, 0 mm\n`;
    tspl += `DIRECTION 1\n`;
    tspl += `REFERENCE 0,0\n`;
    tspl += `CLS\n`;
    
    // Product name (medium font - font 3 fits better on 76mm label)
    tspl += `TEXT ${centerX},${currentY},"3",0,1,1,"${this.escapeText(data.productName)}"\n`;
    currentY += 40;
    
    // SKU (if provided)
    if (data.sku) {
      tspl += `TEXT ${centerX},${currentY},"2",0,1,1,"SKU: ${this.escapeText(data.sku)}"\n`;
      currentY += 35;
    }
    
    // Price (if provided)
    if (data.price) {
      tspl += `TEXT ${centerX},${currentY},"3",0,1,1,"${this.escapeText(data.price)}"\n`;
      currentY += 40;
    }
    
    // Barcode
    tspl += `BARCODE ${centerX},${currentY},"128",80,1,0,2,2,"${data.barcode}"\n`;
    
    // Print quantity
    const quantity = data.quantity || 1;
    tspl += `PRINT ${quantity},1\n`;
    
    return tspl;
  }

  /**
   * Generate TSPL commands for barcode-only label
   */
  private generateBarcodeLabelTspl(data: BarcodeLabelData): string {
    const { labelWidth, labelHeight, gap } = this.tsplConfig;
    const barcodeType = this.mapBarcodeType(data.barcodeType || 'CODE128');
    
    // Center position - 76mm = ~607 dots, center at ~180 dots from left
    const centerX = 180;
    
    // 50mm label = ~400 dots at 203 DPI
    const labelHeightDots = Math.floor(labelHeight * this.tsplConfig.dpi / 25.4);
    
    let tspl = '';
    
    // Label setup
    tspl += `SIZE ${labelWidth} mm, ${labelHeight} mm\n`;
    tspl += `GAP ${gap} mm, 0 mm\n`;
    tspl += `DIRECTION 1\n`;
    tspl += `REFERENCE 0,0\n`;
    tspl += `CLS\n`;
    
    // Optional text above barcode (add 30 dots offset to push down)
    if (data.text) {
      // Text + barcode = ~40 + 100 = 140 dots total
      const startY = Math.floor((labelHeightDots - 140) / 2) + 30;
      tspl += `TEXT ${centerX},${startY},"3",0,1,1,"${this.escapeText(data.text)}"\n`;
      tspl += `BARCODE ${centerX},${startY + 40},"${barcodeType}",100,1,0,2,2,"${data.barcode}"\n`;
    } else {
      // Barcode only = ~100 dots, center vertically (add 30 dots offset)
      const barcodeY = Math.floor((labelHeightDots - 100) / 2) + 30;
      tspl += `BARCODE ${centerX},${barcodeY},"${barcodeType}",100,1,0,2,2,"${data.barcode}"\n`;
    }
    
    // Print quantity
    const quantity = data.quantity || 1;
    tspl += `PRINT ${quantity},1\n`;
    
    return tspl;
  }

  /**
   * Map barcode type to TSPL format
   */
  private mapBarcodeType(type: string): string {
    const typeMap: { [key: string]: string } = {
      'CODE128': '128',
      'EAN13': 'EAN13',
      'QR': 'QR',
    };
    
    return typeMap[type] || '128';
  }

  /**
   * Escape special characters in text
   */
  private escapeText(text: string): string {
    return text
      .replace(/\\/g, '\\\\')
      .replace(/"/g, '\\"')
      .replace(/\n/g, ' ')
      .replace(/\r/g, '');
  }

  /**
   * Send TSPL commands to printer
   */
  private async sendToPrinter(commands: string): Promise<PrintResult> {
    const jobId = uuidv4();
    const tempFile = path.join(this.tempDir, `label-${jobId}.prn`);

    try {
      // Write commands to temp file
      fs.writeFileSync(tempFile, commands);

      // Send to printer using RAW printing PowerShell script
      const scriptPath = path.join(process.cwd(), 'Send-RawToPrinter.ps1');
      const psCommand = `powershell -ExecutionPolicy Bypass -File "${scriptPath}" -PrinterName "${this.printerName}" -FilePath "${tempFile}"`;
      
      await execAsync(psCommand);

      // Clean up temp file
      try {
        fs.unlinkSync(tempFile);
      } catch (e) {
        // Ignore cleanup errors
      }

      return {
        success: true,
        jobId,
      };

    } catch (error: any) {
      // Clean up temp file on error
      try {
        if (fs.existsSync(tempFile)) {
          fs.unlinkSync(tempFile);
        }
      } catch (e) {
        // Ignore cleanup errors
      }

      // Parse error message
      const errorMsg = this.parseError(error.message);
      
      throw new Error(errorMsg);
    }
  }

  /**
   * Parse Windows printer error messages
   */
  private parseError(errorMessage: string): string {
    if (errorMessage.includes('InvalidPrinterException')) {
      return 'Printer not found or not accessible';
    }
    if (errorMessage.includes('offline')) {
      return 'Printer is offline';
    }
    if (errorMessage.includes('paper')) {
      return 'Paper out or paper jam';
    }
    if (errorMessage.includes('access denied')) {
      return 'Access denied - check printer permissions';
    }
    
    return errorMessage;
  }

  /**
   * Verify printer exists and is accessible
   */
  private async verifyPrinter(): Promise<void> {
    try {
      const { stdout } = await execAsync(
        `powershell -Command "Get-Printer -Name '${this.printerName}' | Select-Object Name, PrinterStatus | ConvertTo-Json"`
      );

      const printerInfo = JSON.parse(stdout);
      
      if (!printerInfo || !printerInfo.Name) {
        throw new Error(`Printer '${this.printerName}' not found`);
      }

      logger.debug('Printer verified', {
        deviceId: this.config?.id,
        printerName: printerInfo.Name,
        status: printerInfo.PrinterStatus,
      });

    } catch (error: any) {
      throw new Error(`Failed to verify printer: ${error.message}`);
    }
  }

  /**
   * Test print
   */
  private async testPrint(): Promise<PrintResult> {
    const testData: ProductLabelData = {
      productName: 'Test Product',
      barcode: 'TEST123456',
      price: '$9.99',
      sku: 'TEST-001',
      quantity: 1,
    };

    return await this.printProductLabel(testData);
  }

  getCapabilities(): DeviceCapability[] {
    return ['print_label', 'get_status'];
  }

  getStatus(): DeviceStatus {
    return { ...this.status };
  }

  async close(): Promise<void> {
    logger.info('TSPL label printer closing', {
      deviceId: this.config?.id,
    });

    // Clean up temp directory
    try {
      const files = fs.readdirSync(this.tempDir);
      for (const file of files) {
        if (file.startsWith('label-') && file.endsWith('.prn')) {
          fs.unlinkSync(path.join(this.tempDir, file));
        }
      }
    } catch (error) {
      // Ignore cleanup errors
    }

    this.status = {
      state: 'offline',
      message: 'Printer closed',
      lastActivity: new Date(),
    };
  }
}
