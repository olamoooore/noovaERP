/**
 * Scanner Listener Service
 * 
 * Captures keyboard input from HID barcode scanners and reports results to backend
 */

import { GlobalKeyboardListener } from 'node-global-key-listener';
import { logger } from '../utils/logger';
import { ApiClient } from '../api/client';

export type ScanMode = 'single' | 'continuous';

export interface ScannerJob {
  deviceId: string;
  mode: ScanMode;
  timeout: number;
  startTime: number;
  buffer: string;
  isActive: boolean;
}

export class ScannerListenerService {
  private keyboardListener: GlobalKeyboardListener | null = null;
  private activeJobs = new Map<string, ScannerJob>();
  private isListening = false;

  constructor(private apiClient: ApiClient, private agentId: string) {}

  /**
   * Start listening for scanner input
   */
  async startListening(
    deviceId: string,
    mode: ScanMode,
    timeout: number
  ): Promise<void> {
    // Check if already listening for this device
    if (this.activeJobs.has(deviceId)) {
      logger.warn('Scanner already listening', { deviceId });
      throw new Error('Scanner already listening');
    }

    console.log(`🎧 STARTING SCANNER LISTENER | Device: ${deviceId} | Mode: ${mode} | Timeout: ${timeout}s`);
    logger.info('Starting scanner listener', { deviceId, mode, timeout });

    // Create job
    const job: ScannerJob = {
      deviceId,
      mode,
      timeout,
      startTime: Date.now(),
      buffer: '',
      isActive: true,
    };

    this.activeJobs.set(deviceId, job);

    // Start keyboard listener if not already running
    if (!this.isListening) {
      await this.initializeKeyboardListener();
    } else {
      console.log(`✅ Keyboard listener already running, job added to queue`);
    }

    // Set timeout to auto-stop
    setTimeout(() => {
      if (this.activeJobs.has(deviceId)) {
        logger.info('Scanner listener timeout reached', { deviceId, timeout });
        this.stopListening(deviceId);
      }
    }, timeout * 1000);
  }

  /**
   * Stop listening for scanner input
   */
  stopListening(deviceId: string): void {
    const job = this.activeJobs.get(deviceId);
    
    if (!job) {
      logger.warn('No active scanner job found', { deviceId });
      return;
    }

    logger.info('Stopping scanner listener', { deviceId });

    job.isActive = false;
    this.activeJobs.delete(deviceId);

    // Stop keyboard listener if no more active jobs
    if (this.activeJobs.size === 0 && this.isListening) {
      this.stopKeyboardListener();
    }
  }

  /**
   * Initialize keyboard listener
   */
  private async initializeKeyboardListener(): Promise<void> {
    if (this.isListening) {
      return;
    }

    console.log('⌨️  INITIALIZING KEYBOARD LISTENER...');
    logger.info('Initializing keyboard listener for scanner input');

    try {
      this.keyboardListener = new GlobalKeyboardListener();

      this.keyboardListener.addListener((event, down) => {
        // Only process key down events
        if (event.state !== 'DOWN' || !event.name) {
          return;
        }

        this.handleKeyPress(event.name, down);
      });

      this.isListening = true;
      console.log('✅ KEYBOARD LISTENER INITIALIZED AND READY');
      logger.info('Keyboard listener initialized');
    } catch (error: any) {
      console.error('❌ FAILED TO INITIALIZE KEYBOARD LISTENER:', error.message);
      logger.error('Failed to initialize keyboard listener', { error: error.message });
      throw error;
    }
  }

  /**
   * Stop keyboard listener
   */
  private stopKeyboardListener(): void {
    if (!this.isListening || !this.keyboardListener) {
      return;
    }

    logger.info('Stopping keyboard listener');

    this.keyboardListener.kill();
    this.keyboardListener = null;
    this.isListening = false;
  }

  /**
   * Handle key press event
   */
  private handleKeyPress(key: string, down: any): void {
    // Log all key presses for debugging (including to console for visibility)
    console.log(`🎹 KEY PRESS: "${key}" | Active jobs: ${this.activeJobs.size}`);
    logger.debug('Key press detected', { key, activeJobs: this.activeJobs.size });

    // Process all active jobs
    for (const [deviceId, job] of this.activeJobs.entries()) {
      if (!job.isActive) {
        continue;
      }

      // Check if timeout reached
      const elapsed = Date.now() - job.startTime;
      if (elapsed > job.timeout * 1000) {
        logger.info('Scanner listener timeout reached', { deviceId, elapsed });
        this.stopListening(deviceId);
        continue;
      }

      // Handle Enter key - barcode complete
      if (key === 'RETURN' || key === 'ENTER') {
        logger.info('Enter key detected', { deviceId, bufferLength: job.buffer.length });
        if (job.buffer.length > 0) {
          logger.info('Barcode complete, processing...', { deviceId, barcode: job.buffer });
          this.handleBarcodeScanned(deviceId, job.buffer);
          job.buffer = '';

          // Stop if single mode
          if (job.mode === 'single') {
            logger.info('Single mode - stopping after scan', { deviceId });
            this.stopListening(deviceId);
          }
        }
        continue;
      }

      // Handle backspace
      if (key === 'BACKSPACE') {
        job.buffer = job.buffer.slice(0, -1);
        logger.debug('Backspace pressed', { deviceId, buffer: job.buffer });
        continue;
      }

      // Add character to buffer
      // Handle special keys
      if (key.length === 1) {
        job.buffer += key;
        logger.debug('Character added to buffer', { deviceId, key, buffer: job.buffer });
      } else if (key.startsWith('NUMPAD ')) {
        // Handle numpad keys
        const numpadKey = key.replace('NUMPAD ', '');
        if (numpadKey.length === 1) {
          job.buffer += numpadKey;
          logger.debug('Numpad key added to buffer', { deviceId, key: numpadKey, buffer: job.buffer });
        }
      } else if (key === 'SPACE') {
        job.buffer += ' ';
        logger.debug('Space added to buffer', { deviceId, buffer: job.buffer });
      } else if (key.match(/^[0-9]$/)) {
        job.buffer += key;
        logger.debug('Number added to buffer', { deviceId, key, buffer: job.buffer });
      } else {
        logger.debug('Unhandled key', { deviceId, key });
      }
    }
  }

  /**
   * Handle barcode/RFID scanned
   */
  private async handleBarcodeScanned(deviceId: string, barcode: string): Promise<void> {
    console.log('🔍 SCAN DETECTED:', barcode.substring(0, 100), 'Device:', deviceId);
    logger.info('Scan detected', { deviceId, data: barcode.substring(0, 200), length: barcode.length });

    try {
      // Detect format (RFID, barcode, or QR)
      const format = this.detectScanFormat(barcode);
      console.log('📊 Format detected:', format);

      // Parse data based on format
      let parsed = undefined;
      if (format === 'QR') {
        parsed = this.parseQRData(barcode);
        console.log('🔍 QR data parsed:', parsed.type);
      } else if (format === 'RFID') {
        parsed = this.parseRFIDData(barcode);
        console.log('🔍 RFID card parsed:', parsed.cardType);
      }

      // Report to backend
      console.log('📤 Reporting to backend...');
      const payload: any = {
        device_id: deviceId,
        barcode,
        format,
        timestamp: new Date().toISOString(), // ISO string format
      };

      // Add parsed data
      if (parsed) {
        payload.parsed = parsed;
      }

      await this.apiClient.reportScanResult(this.agentId, payload);

      console.log('✅ Scan result reported to backend successfully');
      logger.info('Scan result reported to backend', { 
        deviceId, 
        format, 
        parsedType: parsed && ('type' in parsed ? parsed.type : parsed.cardType)
      });
    } catch (error: any) {
      console.error('❌ Failed to report scan result:', error.message);
      logger.error('Failed to report scan result', {
        error: error.message,
        deviceId,
        data: barcode.substring(0, 200),
      });
    }
  }

  /**
   * Detect scan format (RFID, barcode, or QR code)
   */
  private detectScanFormat(data: string): string {
    // RFID cards typically have specific patterns
    // Common RFID formats:
    // - 10 digits (EM4100, HID Prox)
    // - 8 hex characters (Mifare UID)
    // - 14 hex characters (Mifare full)
    
    // Check for RFID patterns
    if (this.isLikelyRFID(data)) {
      return 'RFID';
    }

    // QR codes are typically longer and can contain more complex data
    // If it's longer than typical barcode length or contains special patterns, it's likely a QR code
    if (data.length > 50) {
      return 'QR';
    }

    // Check for QR-specific patterns
    if (data.includes('\n') || data.includes('\r')) {
      return 'QR';
    }

    if (data.startsWith('{') || data.startsWith('[') || data.startsWith('http://') || data.startsWith('https://')) {
      return 'QR';
    }

    if (data.toUpperCase().startsWith('NOOVAERP:')) {
      return 'QR';
    }

    // Otherwise, detect barcode format
    return this.detectBarcodeFormat(data);
  }

  /**
   * Check if scanned data is likely an RFID card
   */
  private isLikelyRFID(data: string): boolean {
    // Remove any whitespace
    const cleaned = data.trim();

    // EM4100 / HID Prox: 10 digits
    if (/^[0-9]{10}$/.test(cleaned)) {
      return true;
    }

    // Mifare UID: 8 hex characters (4 bytes)
    if (/^[0-9A-Fa-f]{8}$/.test(cleaned)) {
      return true;
    }

    // Mifare full: 14 hex characters (7 bytes)
    if (/^[0-9A-Fa-f]{14}$/.test(cleaned)) {
      return true;
    }

    // Some readers prefix with card type
    if (cleaned.toUpperCase().startsWith('RFID:') || 
        cleaned.toUpperCase().startsWith('CARD:') ||
        cleaned.toUpperCase().startsWith('UID:')) {
      return true;
    }

    // 125kHz cards: often 8-10 digits
    if (/^[0-9]{8,10}$/.test(cleaned) && !this.isLikelyBarcode(cleaned)) {
      return true;
    }

    return false;
  }

  /**
   * Check if data is likely a barcode (to avoid false RFID detection)
   */
  private isLikelyBarcode(data: string): boolean {
    // EAN-13: 13 digits
    if (/^[0-9]{13}$/.test(data)) {
      return true;
    }

    // UPC-A: 12 digits
    if (/^[0-9]{12}$/.test(data)) {
      return true;
    }

    // EAN-8: 8 digits
    if (/^[0-9]{8}$/.test(data)) {
      return true;
    }

    return false;
  }

  /**
   * Detect barcode format based on content
   */
  private detectBarcodeFormat(barcode: string): string {
    // Basic format detection
    if (/^[0-9]{8}$/.test(barcode)) {
      return 'EAN-8';
    } else if (/^[0-9]{13}$/.test(barcode)) {
      return 'EAN-13';
    } else if (/^[0-9]{12}$/.test(barcode)) {
      return 'UPC-A';
    } else if (/^[0-9A-Z\-\.\$\/\+\% ]+$/.test(barcode)) {
      return 'CODE-39';
    } else if (/^[0-9]+$/.test(barcode)) {
      return 'CODE-128';
    } else {
      return 'UNKNOWN';
    }
  }

  /**
   * Parse QR code data and determine its type
   */
  private parseQRData(qrData: string): { type: string; data: any } {
    // Try JSON parsing
    if (qrData.trim().startsWith('{') || qrData.trim().startsWith('[')) {
      try {
        const parsed = JSON.parse(qrData);
        return {
          type: 'json',
          data: parsed,
        };
      } catch (e) {
        // Not valid JSON, continue to other checks
      }
    }

    // Check for URL
    if (qrData.match(/^https?:\/\//i)) {
      try {
        const url = new URL(qrData);
        return {
          type: 'url',
          data: {
            url: qrData,
            protocol: url.protocol,
            hostname: url.hostname,
            pathname: url.pathname,
            search: url.search,
            hash: url.hash,
          },
        };
      } catch (e) {
        // Not a valid URL, continue
      }
    }

    // Check for NoovaERP product format: NOOVAERP:PROD:SKU:QTY:N
    if (qrData.toUpperCase().startsWith('NOOVAERP:PROD:')) {
      const parts = qrData.split(':');
      if (parts.length >= 5) {
        return {
          type: 'noovaerp_product',
          data: {
            sku: parts[2],
            quantity: parseInt(parts[3], 10) || 1,
            variant: parts[4],
            raw: qrData,
          },
        };
      }
    }

    // Default to plain text
    return {
      type: 'text',
      data: {
        text: qrData,
        length: qrData.length,
      },
    };
  }

  /**
   * Parse RFID card data
   */
  private parseRFIDData(rfidData: string): { cardType: string; cardId: string; data: any } {
    const cleaned = rfidData.trim();

    // Remove common prefixes
    let cardId = cleaned;
    if (cleaned.toUpperCase().startsWith('RFID:')) {
      cardId = cleaned.substring(5);
    } else if (cleaned.toUpperCase().startsWith('CARD:')) {
      cardId = cleaned.substring(5);
    } else if (cleaned.toUpperCase().startsWith('UID:')) {
      cardId = cleaned.substring(4);
    }

    cardId = cardId.trim();

    // Determine card type based on format
    let cardType = 'UNKNOWN';
    
    if (/^[0-9]{10}$/.test(cardId)) {
      cardType = 'EM4100'; // 125kHz proximity card
    } else if (/^[0-9A-Fa-f]{8}$/.test(cardId)) {
      cardType = 'MIFARE_UID'; // Mifare 4-byte UID
    } else if (/^[0-9A-Fa-f]{14}$/.test(cardId)) {
      cardType = 'MIFARE_FULL'; // Mifare 7-byte UID
    } else if (/^[0-9]{8,10}$/.test(cardId)) {
      cardType = 'HID_PROX'; // HID Proximity card
    }

    return {
      cardType,
      cardId,
      data: {
        raw: rfidData,
        cleaned: cardId,
        length: cardId.length,
        format: this.detectRFIDFormat(cardId),
      },
    };
  }

  /**
   * Detect RFID card format
   */
  private detectRFIDFormat(cardId: string): string {
    if (/^[0-9]{10}$/.test(cardId)) {
      return 'decimal_10';
    } else if (/^[0-9A-Fa-f]{8}$/.test(cardId)) {
      return 'hex_8';
    } else if (/^[0-9A-Fa-f]{14}$/.test(cardId)) {
      return 'hex_14';
    } else if (/^[0-9]+$/.test(cardId)) {
      return 'decimal';
    } else if (/^[0-9A-Fa-f]+$/.test(cardId)) {
      return 'hex';
    } else {
      return 'mixed';
    }
  }

  /**
   * Get active jobs
   */
  getActiveJobs(): Map<string, ScannerJob> {
    return new Map(this.activeJobs);
  }

  /**
   * Check if device is listening
   */
  isDeviceListening(deviceId: string): boolean {
    return this.activeJobs.has(deviceId);
  }

  /**
   * Stop all listeners
   */
  stopAll(): void {
    logger.info('Stopping all scanner listeners');

    for (const deviceId of this.activeJobs.keys()) {
      this.stopListening(deviceId);
    }

    if (this.isListening) {
      this.stopKeyboardListener();
    }
  }
}
