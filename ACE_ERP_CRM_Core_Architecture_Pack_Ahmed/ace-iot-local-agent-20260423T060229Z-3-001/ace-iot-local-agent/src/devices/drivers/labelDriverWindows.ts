import { exec } from 'child_process';
import { promisify } from 'util';
import * as fs from 'fs';
import * as path from 'path';
import * as os from 'os';

const execAsync = promisify(exec);

export class LabelPrinterDriverWindows {
  private printerName: string;
  private portName: string;
  public type = 'printer';
  public vendor = 'gprinter';
  public status = 'initializing';

  constructor(printerName: string = 'Gprinter GP-D320FX', portName: string = 'USB001') {
    this.printerName = printerName;
    this.portName = portName;
    this.status = 'ready';
  }

  public async executeCommand(action: string, data: any): Promise<any> {
    switch (action) {
      case 'print_label':
        return await this.printLabel(data);
      default:
        throw new Error(`Unknown action: ${action}`);
    }
  }

  public async printLabel(item: any): Promise<any> {
    try {
      this.status = 'busy';
      const cmd = this.generateTSPL(item);
      
      // Log the TSPL commands for debugging
      console.log('[Label] TSPL Commands:');
      console.log(cmd);
      console.log('[Label] Command length:', cmd.length, 'bytes');
      
      await this.sendToPrinter(cmd);
      this.status = 'ready';
      
      return {
        success: true,
        message: 'Label printed successfully'
      };
    } catch (error) {
      this.status = 'error';
      console.error('[Label] Print failed:', error);
      throw error;
    }
  }

  private generateTSPL(item: any): string {
    // TSPL Commands for Gprinter GP-D320FX
    // Based on printer settings: 76mm x 50mm, Tearing mode, 203 DPI
    let cmd = '';
    
    // Set label size (width x height in mm)
    cmd += 'SIZE 76 mm,50 mm\r\n';
    
    // Set gap between labels (3mm gap, 0mm offset)
    cmd += 'GAP 3 mm,0\r\n';
    
    // Set print direction (0 = normal)
    cmd += 'DIRECTION 0\r\n';
    
    // Set reference point
    cmd += 'REFERENCE 0,0\r\n';
    
    // Set print speed (4 = medium speed from settings)
    cmd += 'SPEED 4\r\n';
    
    // Set print density (7 from settings)
    cmd += 'DENSITY 7\r\n';
    
    // Clear image buffer
    cmd += 'CLS\r\n';
    
    // Product Name (X=50, Y=50, Font 3, Rotation 0, X-scale 1, Y-scale 1)
    cmd += `TEXT 50,50,"3",0,1,1,"${item.productName}"\r\n`;
    
    // Price (X=50, Y=120, Font 4, Rotation 0, X-scale 2, Y-scale 2)
    cmd += `TEXT 50,120,"4",0,2,2,"RM ${item.price}"\r\n`;
    
    // Barcode (X=50, Y=220, Type 128, Height 100, Human readable 1, Rotation 0, Narrow 2, Wide 2)
    cmd += `BARCODE 50,220,"128",100,1,0,2,2,"${item.sku}"\r\n`;
    
    // Print command (quantity, copies)
    cmd += `PRINT ${item.quantity},1\r\n`;
    
    return cmd;
  }

  private async sendToPrinter(data: string): Promise<void> {
    // Create a temporary file with TSPL commands
    const tempDir = os.tmpdir();
    const tempFile = path.join(tempDir, `label_${Date.now()}.prn`);
    
    try {
      // Write TSPL commands as binary to ensure raw data
      const buffer = Buffer.from(data, 'utf-8');
      fs.writeFileSync(tempFile, buffer);
      
      console.log(`[Label] Sending to printer: ${this.printerName} (Port: ${this.portName})`);
      console.log(`[Label] Temp file: ${tempFile}`);
      console.log(`[Label] File size: ${buffer.length} bytes`);
      
      // Verify file was written
      if (!fs.existsSync(tempFile)) {
        throw new Error('Failed to create temp file');
      }
      
      // Use copy command to send raw file to printer port
      // /B flag ensures binary mode (no text processing)
      const command = `copy /B "${tempFile}" "${this.portName}"`;
      
      console.log(`[Label] Executing: ${command}`);
      const result = await execAsync(command, { shell: 'cmd.exe' });
      
      console.log('[Label] Copy result:', result.stdout);
      if (result.stderr) {
        console.log('[Label] Copy stderr:', result.stderr);
      }
      
      console.log('[Label] Sent successfully');
      
      // Wait a moment before cleaning up
      await new Promise(resolve => setTimeout(resolve, 500));
      
      // Clean up temp file
      fs.unlinkSync(tempFile);
      
    } catch (error: any) {
      console.error('[Label] Print error:', error);
      
      // Clean up temp file on error
      if (fs.existsSync(tempFile)) {
        fs.unlinkSync(tempFile);
      }
      
      throw new Error(`Failed to print: ${error.message}`);
    }
  }

  public async close(): Promise<void> {
    this.status = 'offline';
  }
}
