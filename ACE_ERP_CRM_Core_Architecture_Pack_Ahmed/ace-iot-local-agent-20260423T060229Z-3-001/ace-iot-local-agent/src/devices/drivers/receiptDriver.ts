// @ts-ignore - No type definitions available for escpos
import escpos from 'escpos';
// @ts-ignore - No type definitions available for escpos-usb
import escposUsb from 'escpos-usb';

escpos.USB = escposUsb;

export class ReceiptPrinterDriver {
  private device: any;
  private printer: any;
  private vid: string; // e.g., '0x0416'
  private pid: string; // e.g., '0x5011'
  public type = 'printer';
  public vendor = 'csn-80l';
  public status = 'initializing';

  constructor(vid: string, pid: string) {
    this.vid = vid;
    this.pid = pid;
  }

  public async connect(): Promise<boolean> {
    try {
      // Hex strings to decimal for the library
      const v = parseInt(this.vid, 16);
      const p = parseInt(this.pid, 16);
      
      this.device = new escpos.USB(v, p);
      this.printer = new escpos.Printer(this.device);
      
      this.status = 'ready';
      console.log(`[Receipt] Connected to ${this.vid}:${this.pid}`);
      return true;
    } catch (error) {
      this.status = 'error';
      console.error(`[Receipt] Connection failed:`, error);
      return false;
    }
  }

  public async executeCommand(action: string, data: any): Promise<any> {
    switch (action) {
      case 'print_receipt':
        return await this.print(data);
      default:
        throw new Error(`Unknown action: ${action}`);
    }
  }

  public async print(data: any): Promise<any> {
    if (!this.device) await this.connect();

    return new Promise((resolve, reject) => {
      this.device.open((error: any) => {
        if (error) {
          console.error("Printer Error:", error);
          this.status = 'error';
          reject(error);
          return;
        }

        try {
          this.status = 'busy';

          this.printer
            .font('a')
            .align('ct')
            .style('bu')
            .size(1, 1)
            .text(data.storeName || "ACE STORE")
            .text('--------------------------------')
            .align('lt')
            .style('normal')
            .text(`Date: ${new Date().toLocaleString()}`)
            .text('--------------------------------');

          // Loop items
          data.items.forEach((item: any) => {
            this.printer.text(`${item.name} x${item.qty}   ${item.price}`);
          });

          this.printer
            .text('--------------------------------')
            .align('rt')
            .size(2, 2)
            .text(`TOTAL: ${data.total}`)
            .cut()
            .close();

          this.status = 'ready';
          resolve({
            success: true,
            message: 'Receipt printed successfully'
          });
        } catch (err) {
          this.status = 'error';
          reject(err);
        }
      });
    });
  }

  public async close(): Promise<void> {
    if (this.device) {
      this.device.close();
    }
    this.status = 'offline';
  }
}
