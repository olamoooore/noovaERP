import * as usb from 'usb';

export class LabelPrinterDriver {
  private vid: number;
  private pid: number;
  public type = 'printer';
  public vendor = 'gprinter';
  public status = 'initializing';

  constructor(vid: string, pid: string) {
    this.vid = parseInt(vid, 16);
    this.pid = parseInt(pid, 16);
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
      await this.sendToUsb(cmd);
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
    // TSPL Commands for 76mm x 50mm Label
    let cmd = `SIZE 76 mm, 50 mm\r\n`;
    cmd += `GAP 2 mm, 0 mm\r\n`;
    cmd += `CLS\r\n`;
    
    // Product Name
    cmd += `TEXT 30,30,"3",0,1,1,"${item.productName}"\r\n`;
    
    // Price
    cmd += `TEXT 30,80,"3",0,2,2,"RM ${item.price}"\r\n`;
    
    // Barcode
    cmd += `BARCODE 30,180,"128",80,1,0,2,2,"${item.sku}"\r\n`;
    
    cmd += `PRINT ${item.quantity}\r\n`;
    
    return cmd;
  }

  private async sendToUsb(data: string): Promise<void> {
    return new Promise((resolve, reject) => {
      // Find device using the 'usb' library natively
      const device = usb.findByIds(this.vid, this.pid);
      
      if (!device) {
        console.error("[Label] Printer not found!");
        reject(new Error('Printer not found'));
        return;
      }

      try {
        device.open();
        
        // Usually Interface 0, Endpoint 1 is OUT for printers.
        // We might need to claim interface.
        const interface0 = device.interfaces?.[0];
        
        if (!interface0) {
          reject(new Error('No USB interface found'));
          return;
        }
        
        if (interface0.isKernelDriverActive()) {
          interface0.detachKernelDriver();
        }
        
        interface0.claim();
        
        const outEndpoint = interface0.endpoints.find((e: any) => e.direction === 'out');
        
        if (outEndpoint) {
          (outEndpoint as any).transfer(Buffer.from(data, 'utf-8'), (err: any) => {
            if (err) {
              console.error("[Label] Print Error", err);
              reject(err);
            } else {
              console.log("[Label] Sent successfully");
              resolve();
            }
            // Clean up
            // interface0.release(); // Optional: keep open if printing frequently
          });
        } else {
          reject(new Error('OUT endpoint not found'));
        }
      } catch (error) {
        console.error('[Label] USB Error:', error);
        reject(error);
      }
    });
  }

  public async close(): Promise<void> {
    const device = usb.findByIds(this.vid, this.pid);
    if (device) {
      try {
        const interface0 = device.interfaces?.[0];
        if (interface0) {
          interface0.release(() => {
            device.close();
          });
        }
      } catch (error) {
        console.error('[Label] Close error:', error);
      }
    }
    this.status = 'offline';
  }
}
