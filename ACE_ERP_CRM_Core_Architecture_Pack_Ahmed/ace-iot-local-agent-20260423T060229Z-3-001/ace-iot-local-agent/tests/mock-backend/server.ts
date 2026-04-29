/**
 * Mock Backend Server for Agent Development
 * 
 * This server simulates the NoovaERP backend API for testing the agent
 * without requiring the real backend to be running.
 * 
 * Usage: npm run mock-backend
 */

import express, { Request, Response } from 'express';
import { Server } from 'http';
import { WebSocketServer, WebSocket } from 'ws';
import { v4 as uuidv4 } from 'uuid';

const PORT = 8000;
const WS_PORT = 8001;

// In-memory storage
const agents = new Map<string, any>();
const deviceConfigs = new Map<string, any[]>();
const discoveredDevices = new Map<string, any[]>();
const deviceStatuses = new Map<string, any>();

// WebSocket clients
const wsClients = new Map<string, WebSocket>();

// Create Express app
const app = express();
app.use(express.json());

// CORS middleware
app.use((req, res, next) => {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
  res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
  
  if (req.method === 'OPTIONS') {
    return res.sendStatus(200);
  }
  
  next();
});

// Logging middleware
app.use((req, res, next) => {
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.path}`);
  next();
});

// Auth middleware (simple validation)
const authMiddleware = (req: Request, res: Response, next: any) => {
  const authHeader = req.headers.authorization;
  const token = authHeader?.replace('Bearer ', '');
  
  if (!token) {
    return res.status(401).json({
      success: false,
      error: 'Missing authorization token',
    });
  }
  
  // For mock, accept any token
  next();
};

// ============================================================================
// Health Check
// ============================================================================

app.get('/health', (req: Request, res: Response) => {
  res.json({
    status: 'ok',
    service: 'mock-backend',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
  });
});

// ============================================================================
// Agent Registration & Heartbeat
// ============================================================================

app.post('/api/v1/iot/agent/register', (req: Request, res: Response) => {
  const { agent_id, agent_name, agent_version, tenant, devices } = req.body;
  
  console.log('Agent registration:', { agent_id, agent_name, tenant });
  
  // Generate agent token
  const agentToken = `mock-token-${uuidv4()}`;
  
  // Store agent
  agents.set(agent_id, {
    agent_id,
    agent_name,
    agent_version,
    tenant,
    token: agentToken,
    registered_at: new Date().toISOString(),
  });
  
  // Initialize empty device configs for this agent
  if (!deviceConfigs.has(agent_id)) {
    deviceConfigs.set(agent_id, []);
  }
  
  res.json({
    success: true,
    agent_id,
    agent_token: agentToken,
    message: 'Agent registered successfully',
  });
});

app.post('/api/v1/iot/agent/heartbeat', authMiddleware, (req: Request, res: Response) => {
  const { agent_id, uptime, devices } = req.body;
  
  console.log('Heartbeat from agent:', agent_id, { uptime, deviceCount: devices?.length });
  
  // Update agent last seen
  const agent = agents.get(agent_id);
  if (agent) {
    agent.last_heartbeat = new Date().toISOString();
    agent.uptime = uptime;
  }
  
  res.json({
    success: true,
    message: 'Heartbeat received',
  });
});

app.post('/api/v1/iot/agent/unregister', authMiddleware, (req: Request, res: Response) => {
  const { agent_id } = req.body;
  
  console.log('Agent unregistration:', agent_id);
  
  agents.delete(agent_id);
  deviceConfigs.delete(agent_id);
  discoveredDevices.delete(agent_id);
  
  res.json({
    success: true,
    message: 'Agent unregistered successfully',
  });
});

// ============================================================================
// Device Configuration Management
// ============================================================================

app.get('/api/v1/iot/agents/:agentId/device-configs', authMiddleware, (req: Request, res: Response) => {
  const { agentId } = req.params;
  
  console.log('Fetching device configs for agent:', agentId);
  
  const configs = deviceConfigs.get(agentId) || [];
  
  res.json({
    success: true,
    configs,
  });
});

app.post('/api/v1/iot/agents/:agentId/device-configs', authMiddleware, (req: Request, res: Response) => {
  const { agentId } = req.params;
  const config = req.body;
  
  console.log('Creating device config for agent:', agentId, config);
  
  // Generate device ID if not provided
  if (!config.id) {
    config.id = `device-${uuidv4()}`;
  }
  
  config.agentId = agentId;
  config.createdAt = new Date().toISOString();
  config.updatedAt = new Date().toISOString();
  
  // Store config
  const configs = deviceConfigs.get(agentId) || [];
  configs.push(config);
  deviceConfigs.set(agentId, configs);
  
  // Push update to agent via WebSocket
  const ws = wsClients.get(agentId);
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({
      type: 'device_config_update',
      config,
      timestamp: Date.now(),
    }));
  }
  
  res.json({
    success: true,
    config,
  });
});

app.put('/api/v1/iot/agents/:agentId/device-configs/:deviceId', authMiddleware, (req: Request, res: Response) => {
  const { agentId, deviceId } = req.params;
  const updates = req.body;
  
  console.log('Updating device config:', { agentId, deviceId });
  
  const configs = deviceConfigs.get(agentId) || [];
  const index = configs.findIndex(c => c.id === deviceId);
  
  if (index === -1) {
    return res.status(404).json({
      success: false,
      error: 'Device configuration not found',
    });
  }
  
  // Update config
  configs[index] = {
    ...configs[index],
    ...updates,
    updatedAt: new Date().toISOString(),
  };
  deviceConfigs.set(agentId, configs);
  
  // Push update to agent via WebSocket
  const ws = wsClients.get(agentId);
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({
      type: 'device_config_update',
      config: configs[index],
      timestamp: Date.now(),
    }));
  }
  
  res.json({
    success: true,
    config: configs[index],
  });
});

app.delete('/api/v1/iot/agents/:agentId/device-configs/:deviceId', authMiddleware, (req: Request, res: Response) => {
  const { agentId, deviceId } = req.params;
  
  console.log('Deleting device config:', { agentId, deviceId });
  
  const configs = deviceConfigs.get(agentId) || [];
  const filtered = configs.filter(c => c.id !== deviceId);
  deviceConfigs.set(agentId, filtered);
  
  // Push removal to agent via WebSocket
  const ws = wsClients.get(agentId);
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({
      type: 'device_config_remove',
      device_id: deviceId,
      timestamp: Date.now(),
    }));
  }
  
  res.json({
    success: true,
    message: 'Device configuration deleted',
  });
});

// ============================================================================
// Device Discovery
// ============================================================================

app.post('/api/v1/iot/agents/:agentId/discovered-devices', authMiddleware, (req: Request, res: Response) => {
  const { agentId } = req.params;
  const { devices } = req.body;
  
  console.log('Discovered devices from agent:', agentId, { count: devices?.length });
  
  // Store discovered devices
  discoveredDevices.set(agentId, devices || []);
  
  // Generate suggested configs based on templates
  const suggestedConfigs = devices?.map((device: any) => {
    return generateSuggestedConfig(agentId, device);
  }) || [];
  
  res.json({
    success: true,
    suggested_configs: suggestedConfigs,
  });
});

// ============================================================================
// Device Status Updates
// ============================================================================

app.post('/api/v1/iot/agents/:agentId/device-status', authMiddleware, (req: Request, res: Response) => {
  const { agentId } = req.params;
  const { device_id, status, message, timestamp } = req.body;
  
  console.log('Device status update:', { agentId, device_id, status });
  
  // Store status
  const key = `${agentId}:${device_id}`;
  deviceStatuses.set(key, {
    device_id,
    status,
    message,
    timestamp,
    updated_at: new Date().toISOString(),
  });
  
  res.json({
    success: true,
    message: 'Status updated',
  });
});

// ============================================================================
// Device Templates
// ============================================================================

const deviceTemplates = [
  {
    id: 'template-gprinter-gp-d320fx',
    name: 'Gprinter GP-D320FX Label Printer',
    deviceType: 'printer',
    driverType: 'label-printer-raw',
    vendorId: '0x0fe6',
    productId: '0x811e',
    manufacturer: 'Gprinter',
    model: 'GP-D320FX',
    defaultConfig: {
      labelWidth: 76,
      labelHeight: 50,
      dpi: 203,
      protocol: 'tspl',
    },
    capabilities: ['print_label', 'get_status'],
    description: 'Gprinter GP-D320FX thermal label printer (76mm x 50mm)',
  },
  {
    id: 'template-kpos-80',
    name: 'KPOS_80 Receipt Printer',
    deviceType: 'printer',
    driverType: 'receipt-printer-raw',
    vendorId: '0x0fe6',
    productId: '0x811e',
    manufacturer: 'KPOS',
    model: 'KPOS_80',
    defaultConfig: {
      width: 80,
      protocol: 'escpos',
    },
    capabilities: ['print_receipt', 'get_status'],
    description: 'KPOS_80 thermal receipt printer (80mm)',
  },
  {
    id: 'template-generic-escpos',
    name: 'Generic ESC/POS Receipt Printer',
    deviceType: 'printer',
    driverType: 'receipt-printer-raw',
    defaultConfig: {
      width: 80,
      protocol: 'escpos',
    },
    capabilities: ['print_receipt', 'get_status'],
    description: 'Generic ESC/POS compatible receipt printer',
  },
  {
    id: 'template-generic-tspl',
    name: 'Generic TSPL Label Printer',
    deviceType: 'printer',
    driverType: 'label-printer-raw',
    defaultConfig: {
      labelWidth: 76,
      labelHeight: 50,
      dpi: 203,
      protocol: 'tspl',
    },
    capabilities: ['print_label', 'get_status'],
    description: 'Generic TSPL compatible label printer',
  },
];

app.get('/api/v1/iot/device-templates', authMiddleware, (req: Request, res: Response) => {
  const { device_type, vendor_id, product_id } = req.query;
  
  console.log('Fetching device templates:', { device_type, vendor_id, product_id });
  
  let filtered = deviceTemplates;
  
  if (device_type) {
    filtered = filtered.filter(t => t.deviceType === device_type);
  }
  
  if (vendor_id && product_id) {
    filtered = filtered.filter(t => 
      t.vendorId === vendor_id && t.productId === product_id
    );
  }
  
  res.json({
    success: true,
    templates: filtered,
  });
});

app.get('/api/v1/iot/device-templates/:templateId', authMiddleware, (req: Request, res: Response) => {
  const { templateId } = req.params;
  
  const template = deviceTemplates.find(t => t.id === templateId);
  
  if (!template) {
    return res.status(404).json({
      success: false,
      error: 'Template not found',
    });
  }
  
  res.json({
    success: true,
    template,
  });
});

// ============================================================================
// Helper Functions
// ============================================================================

function generateSuggestedConfig(agentId: string, device: any): any {
  // Find matching template
  const template = deviceTemplates.find(t => 
    t.vendorId === device.vendorId && t.productId === device.productId
  );
  
  if (template) {
    return {
      id: `device-${uuidv4()}`,
      agentId,
      deviceType: template.deviceType,
      driverType: template.driverType,
      name: `${template.name} - ${device.port || 'Auto'}`,
      enabled: true,
      connectionInfo: {
        vendorId: device.vendorId,
        productId: device.productId,
        port: device.port,
        printerName: device.model || template.model,
      },
      driverConfig: template.defaultConfig,
      capabilities: template.capabilities,
      metadata: {
        discoveredAt: device.discoveredAt,
        manufacturer: device.manufacturer || template.manufacturer,
        model: device.model || template.model,
      },
    };
  }
  
  // Generic config if no template matches
  return {
    id: `device-${uuidv4()}`,
    agentId,
    deviceType: 'printer',
    driverType: 'generic',
    name: `Unknown Device - ${device.vendorId}:${device.productId}`,
    enabled: false,
    connectionInfo: {
      vendorId: device.vendorId,
      productId: device.productId,
      port: device.port,
    },
    driverConfig: {},
    capabilities: [],
    metadata: {
      discoveredAt: device.discoveredAt,
      needsConfiguration: true,
    },
  };
}

// ============================================================================
// WebSocket Server
// ============================================================================

const wss = new WebSocketServer({ port: WS_PORT });

wss.on('connection', (ws: WebSocket, req) => {
  console.log('WebSocket client connected');
  
  // Extract agent ID from query params or headers
  const url = new URL(req.url || '', `http://${req.headers.host}`);
  const agentId = url.searchParams.get('agent_id');
  
  if (agentId) {
    wsClients.set(agentId, ws);
    console.log('Agent WebSocket registered:', agentId);
  }
  
  ws.on('message', (data: Buffer) => {
    try {
      const message = JSON.parse(data.toString());
      console.log('WebSocket message received:', message.type);
      
      // Handle different message types
      switch (message.type) {
        case 'device_status_change':
          // Store status update
          if (agentId && message.device_id) {
            const key = `${agentId}:${message.device_id}`;
            deviceStatuses.set(key, {
              device_id: message.device_id,
              status: message.status,
              message: message.message,
              timestamp: message.timestamp,
              updated_at: new Date().toISOString(),
            });
          }
          break;
          
        case 'device_discovered':
          // Add to discovered devices
          if (agentId) {
            const devices = discoveredDevices.get(agentId) || [];
            devices.push(message.device);
            discoveredDevices.set(agentId, devices);
          }
          break;
          
        case 'device_disconnected':
          // Mark device as offline
          if (agentId && message.device_id) {
            const key = `${agentId}:${message.device_id}`;
            deviceStatuses.set(key, {
              device_id: message.device_id,
              status: 'offline',
              timestamp: message.timestamp,
              updated_at: new Date().toISOString(),
            });
          }
          break;
      }
    } catch (error) {
      console.error('Error processing WebSocket message:', error);
    }
  });
  
  ws.on('close', () => {
    if (agentId) {
      wsClients.delete(agentId);
      console.log('Agent WebSocket disconnected:', agentId);
    }
  });
});

// ============================================================================
// Start Server
// ============================================================================

const server: Server = app.listen(PORT, () => {
  console.log('='.repeat(60));
  console.log('Mock Backend Server Started');
  console.log('='.repeat(60));
  console.log(`HTTP API:       http://localhost:${PORT}`);
  console.log(`WebSocket:      ws://localhost:${WS_PORT}`);
  console.log('');
  console.log('Available Endpoints:');
  console.log('  POST   /api/v1/iot/agent/register');
  console.log('  POST   /api/v1/iot/agent/heartbeat');
  console.log('  POST   /api/v1/iot/agent/unregister');
  console.log('  GET    /api/v1/iot/agents/:agentId/device-configs');
  console.log('  POST   /api/v1/iot/agents/:agentId/device-configs');
  console.log('  PUT    /api/v1/iot/agents/:agentId/device-configs/:deviceId');
  console.log('  DELETE /api/v1/iot/agents/:agentId/device-configs/:deviceId');
  console.log('  POST   /api/v1/iot/agents/:agentId/discovered-devices');
  console.log('  POST   /api/v1/iot/agents/:agentId/device-status');
  console.log('  GET    /api/v1/iot/device-templates');
  console.log('  GET    /api/v1/iot/device-templates/:templateId');
  console.log('='.repeat(60));
});

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\nShutting down mock backend server...');
  wss.close();
  server.close(() => {
    console.log('Mock backend server stopped');
    process.exit(0);
  });
});
