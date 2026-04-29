/**
 * End-to-End Testing for Phases 0-3
 * 
 * This test suite validates the complete integration of:
 * - Phase 0: Mock Backend API
 * - Phase 1: Agent Foundation (Discovery, Config Manager, Driver Factory)
 * - Phase 2: Refactored Drivers
 * - Phase 3: Agent Integration (DeviceManager, WebSocket, HTTP)
 * 
 * Test Scenarios:
 * 1. Agent startup with dynamic mode
 * 2. Configuration sync from backend
 * 3. Device discovery and reporting
 * 4. Device registration from config
 * 5. WebSocket config updates
 * 6. HTTP device management endpoints
 * 7. Periodic config sync
 * 8. Offline mode with cached configs
 * 9. Device command execution
 * 10. Heartbeat with device status
 */

import WebSocket from 'ws';
import axios from 'axios';

const MOCK_BACKEND_URL = 'http://localhost:8000';
const AGENT_HTTP_URL = 'http://localhost:3002';
const AGENT_WS_URL = 'ws://localhost:3001';

// Test configuration - use the token from agent config
const TEST_AGENT_ID = 'agent-auto-generated';
const TEST_API_KEY = 'mock-token-aeeb5ff9-2202-4373-838b-89372eb69f13';

interface TestResult {
  scenario: string;
  status: 'PASS' | 'FAIL' | 'SKIP';
  duration: number;
  error?: string;
  details?: any;
}

class E2ETestRunner {
  private results: TestResult[] = [];
  private ws: WebSocket | null = null;

  async runAllTests(): Promise<void> {
    console.log('\n=================================================');
    console.log('  End-to-End Testing: Phases 0-3');
    console.log('=================================================\n');

    // Test 1: Mock Backend Health Check
    await this.runTest('Mock Backend Health Check', async () => {
      const response = await axios.get(`${MOCK_BACKEND_URL}/health`);
      if (response.data.status !== 'ok') {
        throw new Error('Mock backend not healthy');
      }
      return { backend: 'healthy', version: response.data.version };
    });

    // Test 2: Agent HTTP Server Health Check
    await this.runTest('Agent HTTP Server Health Check', async () => {
      const response = await axios.get(`${AGENT_HTTP_URL}/api/health`);
      if (!response.data.success) {
        throw new Error('Agent HTTP server not healthy');
      }
      return { agent: 'healthy', agentId: response.data.agent_id };
    });

    // Test 3: Fetch Device Configurations from Mock Backend
    await this.runTest('Fetch Device Configurations', async () => {
      const response = await axios.get(
        `${MOCK_BACKEND_URL}/api/v1/iot/agents/${TEST_AGENT_ID}/device-configs`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Failed to fetch configs');
      }
      
      return {
        configCount: response.data.configs.length,
        configs: response.data.configs.map((c: any) => ({
          id: c.id,
          name: c.name,
          type: c.deviceType,
          driver: c.driverType
        }))
      };
    });

    // Test 4: Report Discovered Devices to Mock Backend
    await this.runTest('Report Discovered Devices', async () => {
      const discoveredDevices = [
        {
          vendorId: '0x0fe6',
          productId: '0x811e',
          manufacturer: 'Gprinter',
          model: 'GP-D320FX',
          connectionType: 'usb',
          port: 'USB001',
          discoveredAt: new Date().toISOString()
        }
      ];

      const response = await axios.post(
        `${MOCK_BACKEND_URL}/api/v1/iot/agents/${TEST_AGENT_ID}/discovered-devices`,
        { devices: discoveredDevices },
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );

      if (!response.data.success) {
        throw new Error('Failed to report discovered devices');
      }

      return {
        reported: discoveredDevices.length,
        suggestions: response.data.suggested_configs?.length || 0
      };
    });

    // Test 5: Agent Device Discovery Endpoint
    await this.runTest('Agent Device Discovery Endpoint', async () => {
      const response = await axios.post(
        `${AGENT_HTTP_URL}/api/devices/discover`,
        {},
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Device discovery failed');
      }

      return {
        discovered: response.data.discovered.length,
        devices: response.data.discovered.map((d: any) => ({
          vendor: d.vendorId,
          product: d.productId,
          type: d.connectionType
        }))
      };
    });

    // Test 6: Get Discovered Devices from Agent
    await this.runTest('Get Discovered Devices', async () => {
      const response = await axios.get(
        `${AGENT_HTTP_URL}/api/devices/discovered`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Failed to get discovered devices');
      }

      return {
        count: response.data.discovered.length
      };
    });

    // Test 7: List Active Devices
    await this.runTest('List Active Devices', async () => {
      const response = await axios.get(
        `${AGENT_HTTP_URL}/api/devices`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Failed to list devices');
      }

      return {
        deviceCount: response.data.devices.length,
        devices: response.data.devices.map((d: any) => ({
          id: d.device_id,
          type: d.type,
          status: d.status
        }))
      };
    });

    // Test 8: WebSocket Connection to Agent
    await this.runTest('WebSocket Connection', async () => {
      return new Promise((resolve, reject) => {
        this.ws = new WebSocket(AGENT_WS_URL);
        
        const timeout = setTimeout(() => {
          reject(new Error('WebSocket connection timeout'));
        }, 5000);

        this.ws.on('open', () => {
          clearTimeout(timeout);
          resolve({ connected: true });
        });

        this.ws.on('error', (error) => {
          clearTimeout(timeout);
          reject(error);
        });
      });
    });

    // Test 9: WebSocket Authentication
    await this.runTest('WebSocket Authentication', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Auth response timeout'));
        }, 5000);

        this.ws!.once('message', (data) => {
          clearTimeout(timeout);
          const message = JSON.parse(data.toString());
          if (message.type === 'auth_response' && message.data.authenticated) {
            resolve({ authenticated: true });
          } else {
            reject(new Error('Authentication failed'));
          }
        });

        this.ws!.send(JSON.stringify({
          id: 'auth-test-1',
          type: 'auth',
          data: { token: TEST_API_KEY },
          timestamp: Date.now()
        }));
      });
    });

    // Test 10: WebSocket Device Config Update
    await this.runTest('WebSocket Device Config Update', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      const testConfig = {
        id: 'test-device-ws-001',
        agentId: TEST_AGENT_ID,
        deviceType: 'printer',
        driverType: 'label-printer-raw',
        name: 'Test Label Printer (WebSocket)',
        enabled: true,
        connectionInfo: {
          printerName: 'Test Printer',
          vendorId: '0x0fe6',
          productId: '0x811e'
        },
        driverConfig: {
          labelWidth: 76,
          labelHeight: 50
        },
        capabilities: ['print_label', 'get_status'],
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString()
      };

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Config update response timeout'));
        }, 5000);

        this.ws!.once('message', (data) => {
          clearTimeout(timeout);
          const message = JSON.parse(data.toString());
          if (message.type === 'response' && message.data.success) {
            resolve({ updated: true, deviceId: testConfig.id });
          } else {
            reject(new Error(`Config update failed: ${message.data?.error || 'Unknown error'}`));
          }
        });

        this.ws!.send(JSON.stringify({
          id: 'config-update-test-1',
          type: 'device_config_update',
          data: { config: testConfig },
          timestamp: Date.now()
        }));
      });
    });

    // Test 11: Verify Device Registered
    await this.runTest('Verify Device Registered via WebSocket', async () => {
      const response = await axios.get(
        `${AGENT_HTTP_URL}/api/devices`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      const device = response.data.devices.find((d: any) => d.device_id === 'test-device-ws-001');
      if (!device) {
        throw new Error('Device not found after WebSocket update');
      }

      return {
        found: true,
        deviceType: device.type,
        status: device.status
      };
    });

    // Test 12: Reload Specific Device
    await this.runTest('Reload Specific Device', async () => {
      const response = await axios.post(
        `${AGENT_HTTP_URL}/api/devices/test-device-ws-001/reload`,
        {},
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Device reload failed');
      }

      return { reloaded: true, deviceId: response.data.device_id };
    });

    // Test 13: Reload All Devices
    await this.runTest('Reload All Devices', async () => {
      const response = await axios.post(
        `${AGENT_HTTP_URL}/api/devices/reload`,
        {},
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      if (!response.data.success) {
        throw new Error('Reload all devices failed');
      }

      return {
        reloaded: response.data.reloaded,
        failed: response.data.failed || 0
      };
    });

    // Test 14: WebSocket Device Reload Command
    await this.runTest('WebSocket Device Reload Command', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Reload response timeout'));
        }, 5000);

        this.ws!.once('message', (data) => {
          clearTimeout(timeout);
          const message = JSON.parse(data.toString());
          if (message.type === 'response' && message.data.success) {
            resolve({ reloaded: true });
          } else {
            reject(new Error('Reload failed'));
          }
        });

        this.ws!.send(JSON.stringify({
          id: 'reload-test-1',
          type: 'device_reload',
          data: { deviceId: 'test-device-ws-001' },
          timestamp: Date.now()
        }));
      });
    });

    // Test 15: WebSocket Reload All Devices Command
    await this.runTest('WebSocket Reload All Devices Command', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Reload all response timeout'));
        }, 5000);

        const messageHandler = (data: Buffer) => {
          try {
            const message = JSON.parse(data.toString());
            // Filter for response messages with devices_reloaded_all action
            if (message.type === 'response' && 
                message.action === 'devices_reloaded_all' && 
                message.data.success) {
              clearTimeout(timeout);
              this.ws!.off('message', messageHandler);
              resolve({
                reloaded: message.data.reloaded,
                failed: message.data.failed
              });
            }
          } catch (error) {
            // Ignore parse errors, wait for next message
          }
        };

        this.ws!.on('message', messageHandler);

        this.ws!.send(JSON.stringify({
          id: 'reload-all-test-1',
          type: 'devices_reload_all',
          timestamp: Date.now()
        }));
      });
    });

    // Test 16: WebSocket Device Config Remove
    await this.runTest('WebSocket Device Config Remove', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Remove response timeout'));
        }, 5000);

        const messageHandler = (data: Buffer) => {
          try {
            const message = JSON.parse(data.toString());
            // Filter for response messages with device_config_removed action
            if (message.type === 'response' && 
                message.action === 'device_config_removed' && 
                message.data.success) {
              clearTimeout(timeout);
              this.ws!.off('message', messageHandler);
              resolve({ removed: true });
            }
          } catch (error) {
            // Ignore parse errors, wait for next message
          }
        };

        this.ws!.on('message', messageHandler);

        this.ws!.send(JSON.stringify({
          id: 'remove-test-1',
          type: 'device_config_remove',
          data: { deviceId: 'test-device-ws-001' },
          timestamp: Date.now()
        }));
      });
    });

    // Test 17: Verify Device Removed
    await this.runTest('Verify Device Removed', async () => {
      const response = await axios.get(
        `${AGENT_HTTP_URL}/api/devices`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );
      
      const device = response.data.devices.find((d: any) => d.device_id === 'test-device-ws-001');
      if (device) {
        throw new Error('Device still exists after removal');
      }

      return { removed: true };
    });

    // Test 18: Device Templates Endpoint
    await this.runTest('Fetch Device Templates', async () => {
      const response = await axios.get(
        `${MOCK_BACKEND_URL}/api/v1/iot/device-templates`,
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );

      if (!response.data.success) {
        throw new Error('Failed to fetch templates');
      }

      return {
        templateCount: response.data.templates.length,
        templates: response.data.templates.map((t: any) => ({
          id: t.id,
          name: t.name,
          type: t.deviceType
        }))
      };
    });

    // Test 19: Device Status Update to Backend
    await this.runTest('Report Device Status to Backend', async () => {
      const response = await axios.post(
        `${MOCK_BACKEND_URL}/api/v1/iot/agents/${TEST_AGENT_ID}/device-status`,
        {
          device_id: 'test-device-001',
          status: 'ready',
          message: 'Device operational',
          timestamp: Date.now()
        },
        {
          headers: { Authorization: `Bearer ${TEST_API_KEY}` }
        }
      );

      if (!response.data.success) {
        throw new Error('Failed to report device status');
      }

      return { reported: true };
    });

    // Test 20: WebSocket Ping/Pong
    await this.runTest('WebSocket Ping/Pong', async () => {
      if (!this.ws) throw new Error('WebSocket not connected');

      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Pong response timeout'));
        }, 5000);

        this.ws!.once('message', (data) => {
          clearTimeout(timeout);
          const message = JSON.parse(data.toString());
          if (message.type === 'pong') {
            resolve({ latency: Date.now() - message.timestamp });
          } else {
            reject(new Error('Expected pong response'));
          }
        });

        this.ws!.send(JSON.stringify({
          id: 'ping-test-1',
          type: 'ping',
          timestamp: Date.now()
        }));
      });
    });

    // Cleanup
    if (this.ws) {
      this.ws.close();
    }

    // Print results
    this.printResults();
  }

  private async runTest(
    scenario: string,
    testFn: () => Promise<any>
  ): Promise<void> {
    const startTime = Date.now();
    
    try {
      console.log(`\n🧪 Testing: ${scenario}`);
      const details = await testFn();
      const duration = Date.now() - startTime;
      
      this.results.push({
        scenario,
        status: 'PASS',
        duration,
        details
      });
      
      console.log(`✅ PASS (${duration}ms)`);
      if (details) {
        console.log(`   Details:`, JSON.stringify(details, null, 2));
      }
    } catch (error: any) {
      const duration = Date.now() - startTime;
      
      this.results.push({
        scenario,
        status: 'FAIL',
        duration,
        error: error.message
      });
      
      console.log(`❌ FAIL (${duration}ms)`);
      console.log(`   Error: ${error.message}`);
    }
  }

  private printResults(): void {
    console.log('\n\n=================================================');
    console.log('  Test Results Summary');
    console.log('=================================================\n');

    const passed = this.results.filter(r => r.status === 'PASS').length;
    const failed = this.results.filter(r => r.status === 'FAIL').length;
    const total = this.results.length;
    const successRate = ((passed / total) * 100).toFixed(1);

    console.log(`Total Tests: ${total}`);
    console.log(`Passed: ${passed} ✅`);
    console.log(`Failed: ${failed} ❌`);
    console.log(`Success Rate: ${successRate}%`);

    if (failed > 0) {
      console.log('\n\nFailed Tests:');
      this.results
        .filter(r => r.status === 'FAIL')
        .forEach(r => {
          console.log(`\n❌ ${r.scenario}`);
          console.log(`   Error: ${r.error}`);
        });
    }

    console.log('\n=================================================\n');

    // Exit with error code if any tests failed
    if (failed > 0) {
      process.exit(1);
    }
  }
}

// Run tests
const runner = new E2ETestRunner();
runner.runAllTests().catch(error => {
  console.error('\n❌ Test runner failed:', error);
  process.exit(1);
});
