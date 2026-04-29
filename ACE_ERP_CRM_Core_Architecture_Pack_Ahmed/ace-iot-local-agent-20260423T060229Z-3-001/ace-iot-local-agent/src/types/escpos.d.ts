declare module 'escpos' {
  export class USB {
    constructor(vid: number, pid: number);
    open(callback: (error?: any) => void): void;
    close(): void;
  }

  export class Printer {
    constructor(device: any);
    font(type: string): this;
    align(alignment: string): this;
    style(style: string): this;
    size(width: number, height: number): this;
    text(text: string): this;
    cut(): this;
    close(): this;
  }

  export = {
    USB,
    Printer
  };
}

declare module 'escpos-usb' {
  const USB: any;
  export = USB;
}
