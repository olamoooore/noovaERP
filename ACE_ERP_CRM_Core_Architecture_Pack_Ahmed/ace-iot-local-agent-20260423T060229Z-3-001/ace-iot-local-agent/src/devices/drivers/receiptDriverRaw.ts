import * as edge from 'edge-js';
import { BaseDeviceDriver, DeviceCapability, DeviceStatus } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';

export class ReceiptPrinterDriverRaw extends BaseDeviceDriver {
  private printerName: string = '';
  private printRaw: any;
  private checkPrinterAvailable: any;
  private config?: DeviceConfiguration;

  constructor() {
    super();
  }

  async initialize(config: DeviceConfiguration): Promise<void> {
    logger.info('Initializing receipt printer driver', {
      deviceId: config.id,
      deviceName: config.name,
    });

    this.config = config;
    this.printerName = config.connectionInfo?.printerName || 'KPOS_80';

    try {
      this.initializePrinter();
      this.updateStatus('ready', 'Receipt printer initialized successfully');
      logger.info('Receipt printer driver initialized', {
        deviceId: config.id,
        printerName: this.printerName,
      });
    } catch (error: any) {
      this.updateStatus('error', 'Failed to initialize printer', 'INIT_FAILED');
      logger.error('Failed to initialize receipt printer driver', {
        deviceId: config.id,
        error: error.message,
      });
      throw error;
    }
  }

  private initializePrinter(): void {
    // Initialize edge-js function for checking printer availability
    this.checkPrinterAvailable = edge.func(`
      using System;
      using System.Runtime.InteropServices;
      using System.Threading.Tasks;

      public class Startup
      {
          [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool OpenPrinter([MarshalAs(UnmanagedType.LPStr)] string szPrinter, out IntPtr hPrinter, IntPtr pd);

          [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool ClosePrinter(IntPtr hPrinter);

          public async Task<object> Invoke(dynamic input)
          {
              string printerName = (string)input.printerName;
              IntPtr hPrinter = IntPtr.Zero;
              
              try
              {
                  bool canOpen = OpenPrinter(printerName, out hPrinter, IntPtr.Zero);
                  
                  if (canOpen && hPrinter != IntPtr.Zero)
                  {
                      ClosePrinter(hPrinter);
                      return new { available = true };
                  }
                  
                  return new { available = false };
              }
              catch (Exception ex)
              {
                  if (hPrinter != IntPtr.Zero)
                  {
                      ClosePrinter(hPrinter);
                  }
                  return new { available = false, error = ex.Message };
              }
          }
      }
    `);

    // Initialize edge-js function for raw printing using Windows API
    this.printRaw = edge.func(`
      using System;
      using System.IO;
      using System.Runtime.InteropServices;
      using System.Threading.Tasks;

      public class Startup
      {
          [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
          public class DOCINFOA
          {
              [MarshalAs(UnmanagedType.LPStr)]
              public string pDocName;
              [MarshalAs(UnmanagedType.LPStr)]
              public string pOutputFile;
              [MarshalAs(UnmanagedType.LPStr)]
              public string pDataType;
          }

          [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool OpenPrinter([MarshalAs(UnmanagedType.LPStr)] string szPrinter, out IntPtr hPrinter, IntPtr pd);

          [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool ClosePrinter(IntPtr hPrinter);

          [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool StartDocPrinter(IntPtr hPrinter, Int32 level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

          [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool EndDocPrinter(IntPtr hPrinter);

          [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool StartPagePrinter(IntPtr hPrinter);

          [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool EndPagePrinter(IntPtr hPrinter);

          [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
          public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, Int32 dwCount, out Int32 dwWritten);

          public async Task<object> Invoke(dynamic input)
          {
              string printerName = (string)input.printerName;
              string data = (string)input.data;
              
              IntPtr hPrinter = IntPtr.Zero;
              
              try
              {
                  if (!OpenPrinter(printerName, out hPrinter, IntPtr.Zero))
                  {
                      return new { success = false, error = "Failed to open printer" };
                  }
                  
                  DOCINFOA di = new DOCINFOA();
                  di.pDocName = "Receipt Print";
                  di.pDataType = "RAW";
                  
                  if (!StartDocPrinter(hPrinter, 1, di))
                  {
                      ClosePrinter(hPrinter);
                      return new { success = false, error = "Failed to start document" };
                  }
                  
                  if (!StartPagePrinter(hPrinter))
                  {
                      EndDocPrinter(hPrinter);
                      ClosePrinter(hPrinter);
                      return new { success = false, error = "Failed to start page" };
                  }
                  
                  byte[] bytes = System.Text.Encoding.UTF8.GetBytes(data);
                  IntPtr pUnmanagedBytes = Marshal.AllocCoTaskMem(bytes.Length);
                  Marshal.Copy(bytes, 0, pUnmanagedBytes, bytes.Length);
                  
                  int written = 0;
                  bool success = WritePrinter(hPrinter, pUnmanagedBytes, bytes.Length, out written);
                  
                  Marshal.FreeCoTaskMem(pUnmanagedBytes);
                  
                  EndPagePrinter(hPrinter);
                  EndDocPrinter(hPrinter);
                  ClosePrinter(hPrinter);
                  
                  if (!success)
                  {
                      return new { success = false, error = "Failed to write to printer" };
                  }
                  
                  return new { success = true, bytesWritten = written };
              }
              catch (Exception ex)
              {
                  if (hPrinter != IntPtr.Zero)
                  {
                      ClosePrinter(hPrinter);
                  }
                  return new { success = false, error = ex.Message };
              }
          }
      }
    `);
  }

  getCapabilities(): DeviceCapability[] {
    return ['print_receipt', 'print_raw_escpos', 'get_status'];
  }

  /**
   * Override getStatus to check actual printer availability
   */
  getStatus(): DeviceStatus {
    // Check printer availability asynchronously (non-blocking)
    this.checkPrinterStatus().catch(err => {
      logger.debug('Printer status check failed', {
        deviceId: this.config?.id,
        error: err.message,
      });
    });
    
    return super.getStatus();
  }

  /**
   * Check if printer is available in Windows
   */
  private async checkPrinterStatus(): Promise<void> {
    try {
      const result: any = await new Promise((resolve, reject) => {
        this.checkPrinterAvailable({
          printerName: this.printerName
        }, (error: any, result: any) => {
          if (error) {
            reject(error);
          } else {
            resolve(result);
          }
        });
      });

      if (result.available) {
        // Only update to ready if not currently busy
        if (this.status.state !== 'busy') {
          this.updateStatus('ready', 'Printer is available');
        }
      } else {
        this.updateStatus('offline', 'Printer not available in Windows');
      }
    } catch (error: any) {
      logger.debug('Failed to check printer availability', {
        deviceId: this.config?.id,
        error: error.message,
      });
      // Don't change status on check failure - keep current status
    }
  }

  async executeCommand(action: string, data: any): Promise<any> {
    logger.debug('Executing command on receipt printer', {
      deviceId: this.config?.id,
      action,
    });

    switch (action) {
      case 'print_receipt':
        return await this.printReceipt(data);
      case 'print_raw_escpos':
        return await this.printRawEscpos(data);
      case 'get_status':
        return this.getStatus();
      default:
        throw new Error(`Unknown action: ${action}`);
    }
  }

  /**
   * Print raw ESC/POS commands directly to the printer
   */
  private async printRawEscpos(commands: string): Promise<any> {
    try {
      this.updateStatus('busy', 'Printing raw ESC/POS...');
      
      logger.info('Printing raw ESC/POS commands', {
        deviceId: this.config?.id,
        commandLength: commands.length,
      });
      
      await this.sendToPrinterRaw(commands);
      
      this.updateStatus('ready', 'Raw ESC/POS printed successfully');
      
      return {
        success: true,
        message: 'Raw ESC/POS printed successfully'
      };
    } catch (error: any) {
      this.updateStatus('error', 'Print failed', 'PRINT_FAILED');
      logger.error('Raw ESC/POS print failed', {
        deviceId: this.config?.id,
        error: error.message,
      });
      return {
        success: false,
        error: error.message
      };
    }
  }

  private async printReceipt(data: any): Promise<any> {
    try {
      this.updateStatus('busy', 'Printing receipt...');
      
      const cmd = this.generateESCPOS(data);
      
      logger.debug('Generated ESC/POS commands', {
        deviceId: this.config?.id,
        commandLength: cmd.length,
      });
      
      await this.sendToPrinterRaw(cmd);
      
      this.updateStatus('ready', 'Receipt printed successfully');
      
      return {
        success: true,
        message: 'Receipt printed successfully'
      };
    } catch (error: any) {
      this.updateStatus('error', 'Print failed', 'PRINT_FAILED');
      logger.error('Receipt print failed', {
        deviceId: this.config?.id,
        error: error.message,
      });
      throw error;
    }
  }

  private generateESCPOS(data: any): string {
    // ESC/POS Commands for thermal receipt printer
    const ESC = '\x1B';
    const GS = '\x1D';
    
    let cmd = '';
    
    // Initialize printer
    cmd += ESC + '@';
    
    // Center align
    cmd += ESC + 'a' + '\x01';
    
    // Bold + Underline
    cmd += ESC + 'E' + '\x01'; // Bold on
    cmd += ESC + '-' + '\x01'; // Underline on
    
    // Store name
    cmd += (data.storeName || 'ACE STORE') + '\n';
    
    // Bold + Underline off
    cmd += ESC + 'E' + '\x00'; // Bold off
    cmd += ESC + '-' + '\x00'; // Underline off
    
    // Separator
    cmd += '--------------------------------\n';
    
    // Left align
    cmd += ESC + 'a' + '\x00';
    
    // Date
    cmd += `Date: ${new Date().toLocaleString()}\n`;
    cmd += '--------------------------------\n';
    
    // Items
    if (data.items && Array.isArray(data.items)) {
      data.items.forEach((item: any) => {
        const name = item.name || '';
        const qty = item.qty || 1;
        const price = item.price || '0.00';
        cmd += `${name} x${qty}   ${price}\n`;
      });
    }
    
    // Separator
    cmd += '--------------------------------\n';
    
    // Right align
    cmd += ESC + 'a' + '\x02';
    
    // Double size
    cmd += GS + '!' + '\x11'; // Double width + height
    
    // Total
    cmd += `TOTAL: ${data.total || '0.00'}\n`;
    
    // Normal size
    cmd += GS + '!' + '\x00';
    
    // Feed and cut
    cmd += '\n\n\n';
    cmd += GS + 'V' + '\x41' + '\x03'; // Partial cut
    
    return cmd;
  }

  private async sendToPrinterRaw(data: string): Promise<void> {
    return new Promise((resolve, reject) => {
      logger.debug('Sending data to printer via Windows API', {
        deviceId: this.config?.id,
        printerName: this.printerName,
        dataLength: data.length,
      });
      
      this.printRaw({
        printerName: this.printerName,
        data: data
      }, (error: any, result: any) => {
        if (error) {
          logger.error('Print error', {
            deviceId: this.config?.id,
            error: error.message || error,
          });
          reject(new Error(`Failed to print: ${error.message || error}`));
        } else if (!result.success) {
          logger.error('Print failed', {
            deviceId: this.config?.id,
            error: result.error,
          });
          reject(new Error(`Failed to print: ${result.error}`));
        } else {
          logger.info('Print successful', {
            deviceId: this.config?.id,
            bytesWritten: result.bytesWritten,
          });
          resolve();
        }
      });
    });
  }

  async close(): Promise<void> {
    logger.info('Closing receipt printer driver', {
      deviceId: this.config?.id,
    });
    this.updateStatus('offline', 'Driver closed');
    await super.close();
  }
}
