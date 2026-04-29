import dotenv from 'dotenv';
import { v4 as uuidv4 } from 'uuid';
import fs from 'fs';
import path from 'path';
import os from 'os';

dotenv.config();

interface AgentConfig {
  agent: {
    id: string;
    name: string;
    version: string;
    heartbeatInterval: number;
  };
  backend: {
    url: string;
    tenant: string;
    apiKey?: string;
  };
  websocket: {
    port: number;
    host: string;
  };
  http: {
    port: number;
    host: string;
  };
  devices: {
    autoDiscover: boolean;
    whitelistedVendorIds: string[];
    useLegacyMode: boolean;
  };
  logging: {
    level: string;
  };
}

class ConfigManager {
  private config: AgentConfig;
  private configPath: string;

  constructor() {
    // Config file location
    this.configPath = process.env.CONFIG_PATH || path.join(process.cwd(), 'config', 'agent.json');
    
    // Load or create config
    this.config = this.loadConfig();
  }

  private loadConfig(): AgentConfig {
    // Try to load existing config
    if (fs.existsSync(this.configPath)) {
      try {
        const data = fs.readFileSync(this.configPath, 'utf-8');
        return JSON.parse(data);
      } catch (error) {
        console.warn('Failed to load config, using defaults');
      }
    }

    // Create default config
    const defaultConfig: AgentConfig = {
      agent: {
        id: process.env.AGENT_ID || `agent-${uuidv4()}`,
        name: process.env.AGENT_NAME || `POS-${os.hostname()}`,
        version: '1.0.0',
        heartbeatInterval: parseInt(process.env.HEARTBEAT_INTERVAL || '60'),
      },
      backend: {
        url: process.env.BACKEND_URL || 'http://localhost:8000',
        tenant: process.env.TENANT || 'testcompany',
        apiKey: process.env.API_KEY,
      },
      websocket: {
        port: parseInt(process.env.WS_PORT || '3001'),
        host: process.env.WS_HOST || 'localhost',
      },
      http: {
        port: parseInt(process.env.HTTP_PORT || '3002'),
        host: process.env.HTTP_HOST || 'localhost',
      },
      devices: {
        autoDiscover: process.env.AUTO_DISCOVER !== 'false',
        whitelistedVendorIds: [
          '0x0b00', // Ingenico
          '0x11ca', // Verifone
          '0x0801', // PAX
        ],
        useLegacyMode: process.env.USE_LEGACY_MODE === 'true',
      },
      logging: {
        level: process.env.LOG_LEVEL || 'info',
      },
    };

    // Save default config
    this.saveConfig(defaultConfig);
    return defaultConfig;
  }

  private saveConfig(config: AgentConfig): void {
    try {
      const dir = path.dirname(this.configPath);
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }
      fs.writeFileSync(this.configPath, JSON.stringify(config, null, 2));
    } catch (error) {
      console.error('Failed to save config:', error);
    }
  }

  get(): AgentConfig {
    return this.config;
  }

  update(updates: Partial<AgentConfig>): void {
    this.config = { ...this.config, ...updates };
    this.saveConfig(this.config);
  }

  setToken(token: string): void {
    this.config.backend.apiKey = token;
    this.saveConfig(this.config);
  }
}

export const configManager = new ConfigManager();
export const config = configManager.get();
