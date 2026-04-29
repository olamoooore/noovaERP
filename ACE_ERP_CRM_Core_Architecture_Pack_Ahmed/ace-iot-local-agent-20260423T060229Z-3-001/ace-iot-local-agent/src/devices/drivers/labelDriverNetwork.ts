import * as net from 'net';

export class LabelPrinterDriverNetwork {
  private printerIp: string;
  private printerPort: number;
  public type = 'printer';
  public vendor = 'gprinter';
  public status = 'initializing';

  constructor(printerIp: string = '192.168.123.100', printerPort: number = 9100) {
    this.printerIp = printerIp;
    this.printerPort = printerPort;
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
      
      console.log('[Label] TSPL Commands:');
      console.log(cmd);
      console.log('[Label] Command length:', cmd.length, 'bytes');
      
      await this.sendToNetwork(cmd);
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
    let cmd = '';
    
    cmd += 'SIZE 76 mm,50 mm\r\n';
    cmd += 'GAP 3 mm,0\r\n';
    cmd += 'DIRECTION 0\r\n';
    cmd += 'REFERENCE 0,0\r\n';
    cmd += 'SPEED 4\r\n';
    cmd += 'DENSITY 7\r\n';
    cmd += 'CLS\r\n';
    cmd += `TEXT 50,50,"3",0,1,1,"${item.productName}"\r\n`;
    cmd += `TEXT 50,120,"4",0,2,2,"RM ${item.price}"\r\n`;
    cmd += `BARCODE 50,220,"128",100,1,0,2,2,"${item.sku}"\r\n`;
    cmd += `PRINT ${item.quantity},1\r\n`;
    
    return cmd;
  }

  private async sendToNetwork(data: string): Promise<void> {
    return new Promise((resolve, reject) => {
      console.log(`[Label] Connecting to ${this.printerIp}:${this.printerPort}`);
      
      const client = new net.Socket();
      let timeout: NodeJS.Timeout;

      timeout = setTimeout(() => {
        client.destroy();
        reject(new Error('Connection timeout'));
      }, 5000);

      client.connect(this.printerPort, this.printerIp, () => {
        clearTimeout(timeout);
        console.log('[Label] Connected to printer');
        
        // Send TSPL commands
        client.write(data, 'utf-8', (err) => {
          if (err) {
            client.destroy();
            reject(err);
          } else {
            console.log('[Label] Data sent successfully');
            client.end();
          }
        });
      });

      client.on('close', () => {
        console.log('[Label] Connection closed');
        resolve();
      });

      client.on('error', (err) => {
        clearTimeout(timeout);
        console.error('[Label] Network error:', err);
        reject(err);
      });
    });
  }

  public async close(): Promise<void> {
    this.status = 'offline';
  }
}
