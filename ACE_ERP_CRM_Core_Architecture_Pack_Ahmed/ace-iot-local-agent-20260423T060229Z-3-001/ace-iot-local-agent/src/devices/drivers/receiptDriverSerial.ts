import { SerialPort } from 'serialport';

export class ReceiptPrinterDriverSerial {
  private port: SerialPort | null = null;
  private portPath: string;
  public type = 'printer';
  public vendor = 'csn-80l';
  public status = 'initializing';

  constructor(portPath: string = 'COM10') {
    this.portPath = portPath;
  }

  public async connect(): Promise<boolean> {
    try {
      this.port = new SerialPort({
        path: this.portPath,
        baudRate: 9600,
        dataBits: 8,
        stopBits: 1,
        parity: 'none',
      });

      return new Promise((resolve, reject) => {
        this.port!.on('open', () => {
          this.status = 'ready';
          console.log(`[Receipt] Connected to ${this.portPath}`);
          resolve(true);
        });

        this.port!.on('error', (err) => {
          this.status = 'error';
          console.error(`[Receipt] Connection failed:`, err);
          reject(err);
        });
      });
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
    if (!this.port || !this.port.isOpen) {
      await this.connect();
    }

    return new Promise((resolve, reject) => {
      try {
        this.status = 'busy';

        // ESC/POS Commands
        const ESC = '\x1B';
        const GS = '\x1D';
        
        // Initialize printer
        let commands = ESC + '@';
        
        // Center align
        commands += ESC + 'a' + '\x01';
        
        // Bold + Underline
        commands += ESC + 'E' + '\x01'; // Bold on
        commands += ESC + '-' + '\x01'; // Underline on
        
        // Store name
        commands += (data.storeName || 'ACE STORE') + '\n';
        
        // Bold + Underline off
        commands += ESC + 'E' + '\x00'; // Bold off
        commands += ESC + '-' + '\x00'; // Underline off
        
        // Separator
        commands += '--------------------------------\n';
        
        // Left align
        commands += ESC + 'a' + '\x00';
        
        // Date
        commands += `Date: ${new Date().toLocaleString()}\n`;
        commands += '--------------------------------\n';
        
        // Items
        if (data.items && Array.isArray(data.items)) {
          data.items.forEach((item: any) => {
            const name = item.name || '';
            const qty = item.qty || 1;
            const price = item.price || '0.00';
            commands += `${name} x${qty}   ${price}\n`;
          });
        }
        
        // Separator
        commands += '--------------------------------\n';
        
        // Right align
        commands += ESC + 'a' + '\x02';
        
        // Double size
        commands += GS + '!' + '\x11'; // Double width + height
        
        // Total
        commands += `TOTAL: ${data.total || '0.00'}\n`;
        
        // Normal size
        commands += GS + '!' + '\x00';
        
        // Feed and cut
        commands += '\n\n\n';
        commands += GS + 'V' + '\x41' + '\x03'; // Partial cut
        
        // Write to serial port
        this.port!.write(commands, (err) => {
          if (err) {
            this.status = 'error';
            console.error('[Receipt] Print error:', err);
            reject(err);
          } else {
            this.status = 'ready';
            console.log('[Receipt] Print successful');
            resolve({
              success: true,
              message: 'Receipt printed successfully'
            });
          }
        });
      } catch (err) {
        this.status = 'error';
        console.error('[Receipt] Print error:', err);
        reject(err);
      }
    });
  }

  public async close(): Promise<void> {
    if (this.port && this.port.isOpen) {
      return new Promise((resolve) => {
        this.port!.close(() => {
          this.status = 'offline';
          console.log('[Receipt] Port closed');
          resolve();
        });
      });
    }
    this.status = 'offline';
  }
}
