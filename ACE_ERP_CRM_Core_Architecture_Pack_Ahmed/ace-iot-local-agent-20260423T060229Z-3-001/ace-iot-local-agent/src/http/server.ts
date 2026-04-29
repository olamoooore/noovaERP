import express, { Request, Response } from 'express';
import { Server } from 'http';
import { logger } from '../utils/logger';
import { config } from '../config/config';
import { DeviceManager } from '../devices/deviceManager';
import { ScannerListenerService } from '../services/scannerListenerService';
import { PalmVeinScannerService } from '../services/palmVeinScannerService';
import { ApiClient } from '../api/client';

export class HttpServer {
  private app: express.Application;
  private server: Server | null = null;
  private apiClient?: ApiClient;

  constructor(
    private port: number,
    private deviceManager: DeviceManager,
    private scannerListener?: ScannerListenerService,
    private palmVeinScanner?: PalmVeinScannerService
  ) {
    this.app = express();
    this.setupMiddleware();
    this.setupRoutes();
  }

  /**
   * Set API client for backend communication
   */
  setApiClient(apiClient: ApiClient): void {
    this.apiClient = apiClient;
  }

  private setupMiddleware(): void {
    this.app.use(express.json());
    
    // CORS for local development
    this.app.use((req, res, next) => {
      res.header('Access-Control-Allow-Origin', '*');
      res.header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
      res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
      
      if (req.method === 'OPTIONS') {
        return res.sendStatus(200);
      }
      
      next();
    });

    // Auth middleware
    this.app.use((req, res, next) => {
      // Skip auth for health endpoint
      if (req.path === '/api/health') {
        return next();
      }

      const authHeader = req.headers.authorization;
      const token = authHeader?.replace('Bearer ', '');

      if (!token || token !== config.backend.apiKey) {
        return res.status(401).json({
          success: false,
          error: 'Unauthorized',
        });
      }

      next();
    });
  }

  private setupRoutes(): void {
    // Health check endpoint (no auth required)
    this.app.get('/api/health', (req: Request, res: Response) => {
      const devices = this.deviceManager.getDevices();
      
      res.json({
        success: true,
        agent_id: config.agent.id,
        version: config.agent.version,
        uptime: Math.floor(process.uptime()),
        devices: devices.map(d => ({
          device_id: d.backendId || d.id,  // Prefer backend ID
          type: d.type,
          vendor: d.vendor,
          model: d.model,
          status: d.status,
        })),
      });
    });

    // Device list endpoint
    this.app.get('/api/devices', (req: Request, res: Response) => {
      const devices = this.deviceManager.getDevices();
      
      res.json({
        success: true,
        devices: devices.map(d => ({
          device_id: d.backendId || d.id,  // Prefer backend ID for status sync
          local_id: d.id,                   // Also include local ID for debugging
          type: d.type,
          vendor: d.vendor,
          model: d.model,
          serial: d.serial,
          status: d.status,
        })),
      });
    });

    // Reload all devices endpoint
    this.app.post('/api/devices/reload', async (req: Request, res: Response) => {
      try {
        logger.info('Reload all devices request received');

        const devices = this.deviceManager.getDevices();
        const results = [];

        for (const device of devices) {
          try {
            await this.deviceManager.reloadDevice(device.id);
            results.push({ deviceId: device.id, success: true });
          } catch (error: any) {
            logger.error('Failed to reload device', {
              deviceId: device.id,
              error: error.message,
            });
            results.push({
              deviceId: device.id,
              success: false,
              error: error.message,
            });
          }
        }

        res.json({
          success: true,
          results,
          total: devices.length,
          successful: results.filter(r => r.success).length,
          failed: results.filter(r => !r.success).length,
        });

      } catch (error: any) {
        logger.error('Reload all devices failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Map backend device ID to local device
    this.app.post('/api/devices/map', async (req: Request, res: Response) => {
      try {
        const { localId, backendId } = req.body;

        if (!localId || !backendId) {
          return res.status(400).json({
            success: false,
            error: 'Missing required fields: localId, backendId',
          });
        }

        logger.info('Device ID mapping request', { localId, backendId });

        const success = this.deviceManager.setBackendDeviceId(localId, backendId);

        if (success) {
          res.json({
            success: true,
            message: 'Device ID mapped successfully',
            localId,
            backendId,
          });
        } else {
          res.status(404).json({
            success: false,
            error: `Local device ${localId} not found`,
          });
        }

      } catch (error: any) {
        logger.error('Device ID mapping failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Reload specific device endpoint
    this.app.post('/api/devices/:deviceId/reload', async (req: Request, res: Response) => {
      try {
        const { deviceId } = req.params;

        logger.info('Reload device request received', { deviceId });

        await this.deviceManager.reloadDevice(deviceId);

        res.json({
          success: true,
          device_id: deviceId,
          message: 'Device reloaded successfully',
        });

      } catch (error: any) {
        logger.error('Reload device failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Trigger device discovery endpoint
    this.app.post('/api/devices/discover', async (req: Request, res: Response) => {
      try {
        logger.info('Device discovery request received');

        const discovered = await this.deviceManager.discoverDevices();

        res.json({
          success: true,
          discovered: discovered,
          count: discovered.length,
        });

      } catch (error: any) {
        logger.error('Device discovery failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Get discovered devices endpoint (cached results)
    this.app.get('/api/devices/discovered', async (req: Request, res: Response) => {
      try {
        logger.info('Get discovered devices request received');

        const discovered = await this.deviceManager.discoverDevices();

        res.json({
          success: true,
          discovered: discovered,
          count: discovered.length,
        });

      } catch (error: any) {
        logger.error('Get discovered devices failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Terminal payment endpoint
    this.app.post('/api/terminal/payment', async (req: Request, res: Response) => {
      try {
        const { device_id, amount, currency, reference, metadata } = req.body;

        if (!device_id || !amount || !currency) {
          return res.status(400).json({
            success: false,
            error: 'Missing required fields: device_id, amount, currency',
          });
        }

        const result = await this.deviceManager.executeCommand(device_id, 'create_payment', {
          amount,
          currency,
          reference,
          metadata,
        });

        res.json({
          success: true,
          ...result,
        });

      } catch (error: any) {
        logger.error('Payment request failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Payment status endpoint
    this.app.get('/api/terminal/payment/:transactionId', async (req: Request, res: Response) => {
      try {
        const { transactionId } = req.params;
        const { device_id } = req.query;

        if (!device_id) {
          return res.status(400).json({
            success: false,
            error: 'Missing required query parameter: device_id',
          });
        }

        const result = await this.deviceManager.executeCommand(
          device_id as string,
          'check_payment_status',
          { transaction_id: transactionId }
        );

        res.json({
          success: true,
          ...result,
        });

      } catch (error: any) {
        logger.error('Payment status check failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Payment cancel endpoint
    this.app.post('/api/terminal/payment/:transactionId/cancel', async (req: Request, res: Response) => {
      try {
        const { transactionId } = req.params;
        const { device_id } = req.body;

        if (!device_id) {
          return res.status(400).json({
            success: false,
            error: 'Missing required field: device_id',
          });
        }

        const result = await this.deviceManager.executeCommand(
          device_id,
          'cancel_payment',
          { transaction_id: transactionId }
        );

        res.json({
          success: true,
          ...result,
        });

      } catch (error: any) {
        logger.error('Payment cancellation failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Label printer endpoint
    this.app.post('/api/printer/label', async (req: Request, res: Response) => {
      try {
        const { productName, sku, price, quantity } = req.body;

        if (!productName || !sku || !price) {
          return res.status(400).json({
            success: false,
            error: 'Missing required fields: productName, sku, price',
          });
        }

        logger.info('Label print request received', {
          productName,
          sku,
          price,
          quantity: quantity || 1,
        });

        const result = await this.deviceManager.executeCommand(
          'printer-label-001',
          'print_label',
          {
            productName,
            sku,
            price: String(price),
            quantity: quantity || 1,
          }
        );

        res.json({
          success: true,
          ...result,
        });

      } catch (error: any) {
        logger.error('Label print failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Receipt printer endpoint - handles ESC/POS commands from backend
    this.app.post('/api/printer/receipt', async (req: Request, res: Response) => {
      const { deviceId, jobId, jobType, commands, encoding } = req.body;

      // Validate required fields
      if (!deviceId) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: deviceId',
        });
      }

      if (!jobId) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: jobId',
        });
      }

      if (!commands) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: commands',
        });
      }

      logger.info('Receipt print job received', {
        deviceId,
        jobId,
        jobType,
        encoding: encoding || 'utf8',
        commandLength: commands.length,
      });

      try {
        // Decode commands if base64 encoded
        let escposCommands = commands;
        if (encoding === 'base64') {
          const buffer = Buffer.from(commands, 'base64');
          escposCommands = buffer.toString('binary');
          logger.debug('Decoded base64 ESC/POS commands', {
            jobId,
            originalLength: commands.length,
            decodedLength: escposCommands.length,
          });
        }

        // Find the device - try multiple lookup methods
        let device = this.deviceManager.getDevice(deviceId);

        // If not found by exact ID, search for receipt printer
        if (!device) {
          logger.debug('Device not found by ID, searching for receipt printer...', { deviceId });
          const allDevices = this.deviceManager.getDevices();
          
          device = allDevices.find(d => 
            d.type === 'printer' && 
            (d.model?.toLowerCase().includes('receipt') || 
             d.model?.toLowerCase().includes('kpos') || 
             d.id.includes('receipt'))
          );
          
          if (device) {
            logger.info('Found receipt printer by type search', { 
              requestedId: deviceId, 
              foundId: device.id,
              model: device.model 
            });
          }
        }

        if (!device) {
          logger.warn('Receipt print job failed: device not found', { deviceId, jobId });
          
          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Device not found');
          
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        // Verify it's a printer
        if (device.type !== 'printer') {
          logger.warn('Receipt print job failed: device is not a printer', { 
            deviceId, 
            jobId, 
            type: device.type 
          });
          
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Device is not a printer');
          
          return res.status(400).json({
            success: false,
            error: 'Device is not a printer',
          });
        }

        // Check device status
        if (device.status === 'offline') {
          logger.warn('Receipt print job failed: printer is offline', { deviceId, jobId });
          
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Printer is offline');
          
          return res.status(503).json({
            success: false,
            error: 'Printer is offline',
          });
        }

        // Execute print command
        let result: any;
        
        if (device.driver && typeof device.driver.executeCommand === 'function') {
          logger.debug('Executing print_raw_escpos command', { deviceId, jobId });
          result = await device.driver.executeCommand('print_raw_escpos', escposCommands);
        } else {
          throw new Error('Printer driver does not support executeCommand');
        }

        if (result.success) {
          logger.info('Receipt print job completed successfully', {
            deviceId,
            jobId,
          });

          // Report success to backend
          await this.reportPrintJobResult(jobId, deviceId, 'completed');

          return res.json({
            success: true,
            message: 'Print job sent to printer',
          });
        } else {
          logger.error('Receipt print job failed', {
            deviceId,
            jobId,
            error: result.error,
          });

          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', result.error);

          return res.status(500).json({
            success: false,
            error: result.error || 'Print failed',
          });
        }

      } catch (error: any) {
        logger.error('Receipt print job execution failed', {
          deviceId,
          jobId,
          error: error.message,
        });

        // Report failure to backend
        await this.reportPrintJobResult(jobId, deviceId, 'failed', error.message);

        return res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Scanner start listening endpoint (with /api prefix)
    this.app.post('/api/scanner/start-listening', async (req: Request, res: Response) => {
      try {
        const { deviceId, mode, timeout } = req.body;

        if (!deviceId) {
          return res.status(400).json({
            success: false,
            error: 'Missing required field: deviceId',
          });
        }

        if (!mode || !['single', 'continuous'].includes(mode)) {
          return res.status(400).json({
            success: false,
            error: 'Invalid mode. Must be "single" or "continuous"',
          });
        }

        if (!timeout || typeof timeout !== 'number' || timeout <= 0) {
          return res.status(400).json({
            success: false,
            error: 'Invalid timeout. Must be a positive number',
          });
        }

        logger.info('Scanner start listening request received', {
          deviceId,
          mode,
          timeout,
        });

        // Determine device type and route to appropriate service
        const device = this.deviceManager.getDevices().find(d => d.id === deviceId);
        
        if (!device) {
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        // Check if it's a palm vein scanner by checking driver capabilities
        const capabilities = device.driver?.getCapabilities ? device.driver.getCapabilities() : [];
        const isPalmVein = capabilities.includes('scan_palm_vein') || 
                          device.model?.toLowerCase().includes('palm') ||
                          device.model?.toLowerCase().includes('vein');

        if (isPalmVein) {
          // Route to palm vein scanner service
          if (!this.palmVeinScanner) {
            return res.status(500).json({
              success: false,
              error: 'Palm vein scanner service not available',
            });
          }

          await this.palmVeinScanner.startScanning(deviceId, mode, timeout);
        } else {
          // Route to keyboard-based scanner listener
          if (!this.scannerListener) {
            return res.status(500).json({
              success: false,
              error: 'Scanner listener service not available',
            });
          }

          await this.scannerListener.startListening(deviceId, mode, timeout);
        }

        res.json({
          success: true,
          message: 'Scanner listening started',
          deviceId,
          mode,
          timeout,
          scannerType: isPalmVein ? 'palm_vein' : 'keyboard',
        });

      } catch (error: any) {
        logger.error('Scanner start listening failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Scanner stop listening endpoint
    this.app.post('/api/scanner/stop-listening', async (req: Request, res: Response) => {
      try {
        const { deviceId } = req.body;

        if (!deviceId) {
          return res.status(400).json({
            success: false,
            error: 'Missing required field: deviceId',
          });
        }

        logger.info('Scanner stop listening request received', { deviceId });

        // Determine device type and route to appropriate service
        const device = this.deviceManager.getDevices().find(d => d.id === deviceId);
        
        if (!device) {
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        const capabilities = device.driver?.getCapabilities ? device.driver.getCapabilities() : [];
        const isPalmVein = capabilities.includes('scan_palm_vein') || device.model?.toLowerCase().includes('palm') || device.model?.toLowerCase().includes('vein');

        if (isPalmVein) {
          if (!this.palmVeinScanner) {
            return res.status(500).json({
              success: false,
              error: 'Palm vein scanner service not available',
            });
          }

          this.palmVeinScanner.stopScanning(deviceId);
        } else {
          if (!this.scannerListener) {
            return res.status(500).json({
              success: false,
              error: 'Scanner listener service not available',
            });
          }

          this.scannerListener.stopListening(deviceId);
        }

        res.json({
          success: true,
          message: 'Scanner listening stopped',
          deviceId,
        });

      } catch (error: any) {
        logger.error('Scanner stop listening failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Scanner status endpoint
    this.app.get('/api/scanner/status', async (req: Request, res: Response) => {
      try {
        const jobs: any[] = [];

        // Get keyboard scanner jobs
        if (this.scannerListener) {
          const activeJobs = this.scannerListener.getActiveJobs();
          jobs.push(...Array.from(activeJobs.entries()).map(([deviceId, job]) => ({
            deviceId,
            mode: job.mode,
            timeout: job.timeout,
            startTime: job.startTime,
            elapsed: Math.floor((Date.now() - job.startTime) / 1000),
            isActive: job.isActive,
            scannerType: 'keyboard',
          })));
        }

        // Get palm vein scanner jobs
        if (this.palmVeinScanner) {
          const palmVeinJobs = this.palmVeinScanner.getActiveJobs();
          jobs.push(...Array.from(palmVeinJobs.entries()).map(([deviceId, job]) => ({
            deviceId,
            mode: job.mode,
            timeout: job.timeout,
            startTime: job.startTime,
            elapsed: Math.floor((Date.now() - job.startTime) / 1000),
            isActive: job.isActive,
            scannerType: 'palm_vein',
          })));
        }

        res.json({
          success: true,
          activeJobs: jobs,
          count: jobs.length,
        });

      } catch (error: any) {
        logger.error('Scanner status check failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Scanner endpoints without /api prefix (for backend compatibility)
    this.app.post('/scanner/start-listening', async (req: Request, res: Response) => {
      try {
        const { deviceId, mode, timeout } = req.body;

        if (!deviceId) {
          return res.status(400).json({
            success: false,
            error: 'Missing required field: deviceId',
          });
        }

        if (!mode || !['single', 'continuous'].includes(mode)) {
          return res.status(400).json({
            success: false,
            error: 'Invalid mode. Must be "single" or "continuous"',
          });
        }

        if (!timeout || typeof timeout !== 'number' || timeout <= 0) {
          return res.status(400).json({
            success: false,
            error: 'Invalid timeout. Must be a positive number',
          });
        }

        logger.info('Scanner start listening request received', {
          deviceId,
          mode,
          timeout,
        });

        // Determine device type and route to appropriate service
        const device = this.deviceManager.getDevices().find(d => d.id === deviceId);
        
        if (!device) {
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        const capabilities = device.driver?.getCapabilities ? device.driver.getCapabilities() : [];
        const isPalmVein = capabilities.includes('scan_palm_vein') || 
                          device.model?.toLowerCase().includes('palm') ||
                          device.model?.toLowerCase().includes('vein');

        if (isPalmVein) {
          if (!this.palmVeinScanner) {
            return res.status(500).json({
              success: false,
              error: 'Palm vein scanner service not available',
            });
          }

          await this.palmVeinScanner.startScanning(deviceId, mode, timeout);
        } else {
          if (!this.scannerListener) {
            return res.status(500).json({
              success: false,
              error: 'Scanner listener service not available',
            });
          }

          await this.scannerListener.startListening(deviceId, mode, timeout);
        }

        res.json({
          success: true,
          message: 'Scanner listening started',
          deviceId,
          mode,
          timeout,
          scannerType: isPalmVein ? 'palm_vein' : 'keyboard',
        });

      } catch (error: any) {
        logger.error('Scanner start listening failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    this.app.post('/scanner/stop-listening', async (req: Request, res: Response) => {
      try {
        const { deviceId } = req.body;

        if (!deviceId) {
          return res.status(400).json({
            success: false,
            error: 'Missing required field: deviceId',
          });
        }

        logger.info('Scanner stop listening request received', { deviceId });

        // Determine device type and route to appropriate service
        const device = this.deviceManager.getDevices().find(d => d.id === deviceId);
        
        if (!device) {
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        const capabilities = device.driver?.getCapabilities ? device.driver.getCapabilities() : [];
        const isPalmVein = capabilities.includes('scan_palm_vein') || device.model?.toLowerCase().includes('palm') || device.model?.toLowerCase().includes('vein');

        if (isPalmVein) {
          if (!this.palmVeinScanner) {
            return res.status(500).json({
              success: false,
              error: 'Palm vein scanner service not available',
            });
          }

          this.palmVeinScanner.stopScanning(deviceId);
        } else {
          if (!this.scannerListener) {
            return res.status(500).json({
              success: false,
              error: 'Scanner listener service not available',
            });
          }

          this.scannerListener.stopListening(deviceId);
        }

        res.json({
          success: true,
          message: 'Scanner listening stopped',
          deviceId,
        });

      } catch (error: any) {
        logger.error('Scanner stop listening failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    this.app.get('/scanner/status', async (req: Request, res: Response) => {
      try {
        const jobs: any[] = [];

        // Get keyboard scanner jobs
        if (this.scannerListener) {
          const activeJobs = this.scannerListener.getActiveJobs();
          jobs.push(...Array.from(activeJobs.entries()).map(([deviceId, job]) => ({
            deviceId,
            mode: job.mode,
            timeout: job.timeout,
            startTime: job.startTime,
            elapsed: Math.floor((Date.now() - job.startTime) / 1000),
            isActive: job.isActive,
            scannerType: 'keyboard',
          })));
        }

        // Get palm vein scanner jobs
        if (this.palmVeinScanner) {
          const palmVeinJobs = this.palmVeinScanner.getActiveJobs();
          jobs.push(...Array.from(palmVeinJobs.entries()).map(([deviceId, job]) => ({
            deviceId,
            mode: job.mode,
            timeout: job.timeout,
            startTime: job.startTime,
            elapsed: Math.floor((Date.now() - job.startTime) / 1000),
            isActive: job.isActive,
            scannerType: 'palm_vein',
          })));
        }

        res.json({
          success: true,
          activeJobs: jobs,
          count: jobs.length,
        });

      } catch (error: any) {
        logger.error('Scanner status check failed', { error: error.message });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // ============================================================================
    // Printer Print Job Endpoints
    // ============================================================================

    // Print job endpoint - receives print jobs from backend
    this.app.post('/api/printer/print', async (req: Request, res: Response) => {
      const { deviceId, jobId, jobType, commands, copies } = req.body;

      // Validate required fields
      if (!deviceId) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: deviceId',
        });
      }

      if (!jobId) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: jobId',
        });
      }

      if (!commands) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: commands',
        });
      }

      logger.info('Print job received', {
        deviceId,
        jobId,
        jobType,
        copies: copies || 1,
        commandLength: commands.length,
      });

      try {
        // Find the device - try multiple lookup methods
        let device = this.deviceManager.getDevice(deviceId);

        // If not found by exact ID, search by type (for label printers)
        if (!device) {
          logger.debug('Device not found by ID, searching by type...', { deviceId });
          const allDevices = this.deviceManager.getDevices();
          
          // For label print jobs, find any available label printer
          if (jobType === 'product_label' || jobType === 'barcode_label' || jobType === 'test_label' || jobType === 'raw_tspl') {
            device = allDevices.find(d => 
              d.type === 'printer' && 
              (d.model?.toLowerCase().includes('label') || d.model?.toLowerCase().includes('gprinter') || d.id.includes('label'))
            );
            if (device) {
              logger.info('Found label printer by type search', { 
                requestedId: deviceId, 
                foundId: device.id,
                model: device.model 
              });
            }
          }
          
          // For receipt print jobs, find any available receipt printer
          if (jobType === 'receipt' || jobType === 'raw_escpos') {
            device = allDevices.find(d => 
              d.type === 'printer' && 
              (d.model?.toLowerCase().includes('receipt') || d.model?.toLowerCase().includes('kpos') || d.id.includes('receipt'))
            );
            if (device) {
              logger.info('Found receipt printer by type search', { 
                requestedId: deviceId, 
                foundId: device.id,
                model: device.model 
              });
            }
          }
        }

        if (!device) {
          logger.warn('Print job failed: device not found', { deviceId, jobId });
          
          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Device not found');
          
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        // Verify it's a printer
        if (device.type !== 'printer') {
          logger.warn('Print job failed: device is not a printer', { deviceId, jobId, type: device.type });
          
          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Device is not a printer');
          
          return res.status(400).json({
            success: false,
            error: 'Device is not a printer',
          });
        }

        // Check device status
        if (device.status === 'offline') {
          logger.warn('Print job failed: printer is offline', { deviceId, jobId });
          
          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', 'Printer is offline');
          
          return res.status(503).json({
            success: false,
            error: 'Printer is offline',
          });
        }

        // Execute print command based on job type
        let result: any;
        let action: string;
        
        if (device.driver && typeof device.driver.executeCommand === 'function') {
          // Determine action based on job type
          if (jobType === 'receipt' || jobType === 'raw_escpos') {
            action = 'print_raw_escpos';
          } else {
            action = 'print_raw_tspl';
          }
          
          logger.debug('Executing print command', { deviceId, jobId, action });
          result = await device.driver.executeCommand(action, commands);
        } else {
          throw new Error('Printer driver does not support executeCommand');
        }

        if (result.success) {
          logger.info('Print job completed successfully', {
            deviceId,
            jobId,
            driverJobId: result.jobId,
          });

          // Report success to backend
          await this.reportPrintJobResult(jobId, deviceId, 'completed');

          return res.json({
            success: true,
            message: 'Print job sent to printer',
          });
        } else {
          logger.error('Print job failed', {
            deviceId,
            jobId,
            error: result.error,
          });

          // Report failure to backend
          await this.reportPrintJobResult(jobId, deviceId, 'failed', result.error);

          return res.status(500).json({
            success: false,
            error: result.error || 'Print failed',
          });
        }

      } catch (error: any) {
        logger.error('Print job execution failed', {
          deviceId,
          jobId,
          error: error.message,
        });

        // Report failure to backend
        await this.reportPrintJobResult(jobId, deviceId, 'failed', error.message);

        return res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // Printer status endpoint
    this.app.post('/api/printer/status', async (req: Request, res: Response) => {
      const { deviceId } = req.body;

      if (!deviceId) {
        return res.status(400).json({
          success: false,
          error: 'Missing required field: deviceId',
        });
      }

      try {
        const device = this.deviceManager.getDevice(deviceId);

        if (!device) {
          return res.status(404).json({
            success: false,
            error: 'Device not found',
          });
        }

        if (device.type !== 'printer') {
          return res.status(400).json({
            success: false,
            error: 'Device is not a printer',
          });
        }

        // Get status from driver if available
        let driverStatus: any = null;
        if (device.driver && typeof device.driver.getStatus === 'function') {
          driverStatus = device.driver.getStatus();
        }

        res.json({
          success: true,
          status: device.status,
          message: driverStatus?.message || `Printer is ${device.status}`,
          details: driverStatus,
        });

      } catch (error: any) {
        logger.error('Printer status check failed', {
          deviceId,
          error: error.message,
        });
        res.status(500).json({
          success: false,
          error: error.message,
        });
      }
    });

    // 404 handler
    this.app.use((req: Request, res: Response) => {
      res.status(404).json({
        success: false,
        error: 'Endpoint not found',
      });
    });
  }

  async start(): Promise<void> {
    return new Promise((resolve) => {
      this.server = this.app.listen(this.port, () => {
        logger.info('HTTP server started', { port: this.port });
        resolve();
      });
    });
  }

  async close(): Promise<void> {
    return new Promise((resolve) => {
      if (this.server) {
        this.server.close(() => {
          logger.info('HTTP server closed');
          resolve();
        });
      } else {
        resolve();
      }
    });
  }

  /**
   * Report print job result to backend
   */
  private async reportPrintJobResult(
    jobId: string,
    deviceId: string,
    status: 'completed' | 'failed',
    error?: string
  ): Promise<void> {
    if (!this.apiClient) {
      logger.warn('API client not set, cannot report print job result');
      return;
    }

    try {
      await this.apiClient.reportPrintJobResult(config.agent.id, {
        job_id: jobId,
        device_id: deviceId,
        status,
        error,
      });
    } catch (err: any) {
      logger.error('Failed to report print job result', {
        jobId,
        deviceId,
        error: err.message,
      });
    }
  }
}


