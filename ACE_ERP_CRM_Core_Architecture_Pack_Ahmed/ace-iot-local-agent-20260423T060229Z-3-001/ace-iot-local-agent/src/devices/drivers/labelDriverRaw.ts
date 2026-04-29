import * as edge from 'edge-js';
import { BaseDeviceDriver, DeviceCapability, DeviceStatus } from '../interfaces/deviceDriver';
import { DeviceConfiguration } from '../../config/deviceConfigManager';
import { logger } from '../../utils/logger';

export class LabelPrinterDriverRaw extends BaseDeviceDriver {
  private printerName: string = '';
  private printRaw: any;
  private checkPrinterAvailable: any;
  private config?: DeviceConfiguration;

  constructor() {
    super();
  }

  async initialize(config: DeviceConfiguration): Promise<void> {
    logger.info('Initializing label printer driver', {
      deviceId: config.id,
      deviceName: config.name,
    });

    this.config = config;
    this.printerName = config.connectionInfo?.printerName || 'Gprinter GP-D320FX';

    try {
      this.initializePrinter();
      this.updateStatus('ready', 'Label printer initialized successfully');
      logger.info('Label printer driver initialized', {
        deviceId: config.id,
        printerName: this.printerName,
      });
    } catch (error: any) {
      this.updateStatus('error', 'Failed to initialize printer', 'INIT_FAILED');
      logger.error('Failed to initialize label printer driver', {
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
                  di.pDocName = "Label Print";
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
    return ['print_label', 'print_raw_tspl', 'get_status'];
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
    logger.debug('Executing command on label printer', {
      deviceId: this.config?.id,
      action,
    });

    switch (action) {
      case 'print_label':
        return await this.printLabel(data);
      case 'print_raw_tspl':
        return await this.printRawTspl(data);
      case 'get_status':
        return this.getStatus();
      default:
        throw new Error(`Unknown action: ${action}`);
    }
  }

  /**
   * Print raw TSPL commands directly to the printer
   */
  private async printRawTspl(commands: string): Promise<any> {
    try {
      this.updateStatus('busy', 'Printing raw TSPL...');
      
      logger.info('Printing raw TSPL commands', {
        deviceId: this.config?.id,
        commandLength: commands.length,
      });
      
      await this.sendToPrinterRaw(commands);
      
      this.updateStatus('ready', 'Raw TSPL printed successfully');
      
      return {
        success: true,
        message: 'Raw TSPL printed successfully'
      };
    } catch (error: any) {
      this.updateStatus('error', 'Print failed', 'PRINT_FAILED');
      logger.error('Raw TSPL print failed', {
        deviceId: this.config?.id,
        error: error.message,
      });
      return {
        success: false,
        error: error.message
      };
    }
  }

  private async printLabel(item: any): Promise<any> {
    try {
      this.updateStatus('busy', 'Printing label...');
      
      const cmd = this.generateTSPL(item);
      
      logger.debug('Generated TSPL commands', {
        deviceId: this.config?.id,
        commandLength: cmd.length,
      });
      
      await this.sendToPrinterRaw(cmd);
      
      this.updateStatus('ready', 'Label printed successfully');
      
      return {
        success: true,
        message: 'Label printed successfully'
      };
    } catch (error: any) {
      this.updateStatus('error', 'Print failed', 'PRINT_FAILED');
      logger.error('Label print failed', {
        deviceId: this.config?.id,
        error: error.message,
      });
      throw error;
    }
  }

  private generateTSPL(item: any): string {
    // TSPL Commands for 76mm x 50mm label (608 x 400 dots at 203dpi)
    let cmd = '';
    
    // Set label size (76mm width x 50mm height)
    cmd += 'SIZE 76 mm,50 mm\r\n';
    
    // Set gap between labels
    cmd += 'GAP 3 mm,0 mm\r\n';
    
    // Clear buffer
    cmd += 'CLS\r\n';
    
    // Layout - everything slightly larger and moved down
    
    // Product Name - larger font (3 instead of 2)
    cmd += `TEXT 150,170,"3",0,1,1,"${item.productName}"\r\n`;
    
    // SKU/Code - larger font (2 instead of 1)
    cmd += `TEXT 150,210,"2",0,1,1,"${item.sku}"\r\n`;
    
    // Price - larger (font 4 instead of 3)
    cmd += `TEXT 150,270,"4",0,1,1,"RM ${item.price}"\r\n`;
    
    // QR Code - larger (cell size 4 instead of 3), moved down
    cmd += `QRCODE 420,170,H,4,A,0,"${item.sku}"\r\n`;
    
    // Print the label
    cmd += 'PRINT 1\r\n';
    
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
    logger.info('Closing label printer driver', {
      deviceId: this.config?.id,
    });
    this.updateStatus('offline', 'Driver closed');
    await super.close();
  }
}
