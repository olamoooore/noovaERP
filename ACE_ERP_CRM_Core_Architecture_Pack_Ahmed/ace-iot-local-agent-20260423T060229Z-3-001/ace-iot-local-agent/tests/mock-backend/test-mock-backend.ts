/**
 * Test script for Mock Backend Server
 * 
 * This script tests all the mock backend endpoints to ensure they work correctly.
 * 
 * Usage: ts-node tests/mock-backend/test-mock-backend.ts
 */

import axios from 'axios';

const BASE_URL = 'http://localhost:8000';
const AGENT_ID = 'test-agent-001';
const AGENT_NAME = 'Test POS Station';
const TENANT = 'testcompany';

let agentToken = '';

async function testAgentRegistration() {
  console.log('\n1. Testing Agent Registration...');
  
  try {
    const response = await axios.post(`${BASE_URL}/api/v1/iot/agent/register`, {
      agent_id: AGENT_ID,
      agent_name: AGENT_NAME,
      agent_version: '1.0.0',
      tenant: TENANT,
      machine_id: 'test-machine-001',
    });
    
    console.log('✓ Registration successful');
    console.log('  Agent ID:', response.data.agent_id);
    console.log('  Token:', response.data.agent_token);
    
    agentToken = response.data.agent_token;
    return true;
  } catch (error: any) {
    console.error('✗ Registration failed:', error.message);
    return false;
  }
}

async function testHeartbeat() {
  console.log('\n2. Testing Heartbeat...');
  
  try {
    const response = await axios.post(
      `${BASE_URL}/api/v1/iot/agent/heartbeat`,
      {
        tenant: TENANT,
        agent_id: AGENT_ID,
        uptime: 120,
        devices: [
          { device_id: 'printer-001', status: 'ready' },
          { device_id: 'terminal-001', status: 'ready' },
        ],
      },
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Heartbeat successful');
    return true;
  } catch (error: any) {
    console.error('✗ Heartbeat failed:', error.message);
    return false;
  }
}

async function testFetchDeviceConfigs() {
  console.log('\n3. Testing Fetch Device Configs...');
  
  try {
    const response = await axios.get(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/device-configs`,
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Fetch configs successful');
    console.log('  Configs count:', response.data.configs.length);
    return true;
  } catch (error: any) {
    console.error('✗ Fetch configs failed:', error.message);
    return false;
  }
}

async function testCreateDeviceConfig() {
  console.log('\n4. Testing Create Device Config...');
  
  try {
    const response = await axios.post(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/device-configs`,
      {
        deviceType: 'printer',
        driverType: 'label-printer-raw',
        name: 'Test Label Printer',
        enabled: true,
        connectionInfo: {
          printerName: 'Gprinter GP-D320FX',
          vendorId: '0x0fe6',
          productId: '0x811e',
        },
        driverConfig: {
          labelWidth: 76,
          labelHeight: 50,
          dpi: 203,
        },
        capabilities: ['print_label', 'get_status'],
      },
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Create config successful');
    console.log('  Device ID:', response.data.config.id);
    return response.data.config.id;
  } catch (error: any) {
    console.error('✗ Create config failed:', error.message);
    return null;
  }
}

async function testUpdateDeviceConfig(deviceId: string) {
  console.log('\n5. Testing Update Device Config...');
  
  try {
    const response = await axios.put(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/device-configs/${deviceId}`,
      {
        name: 'Updated Label Printer',
        enabled: false,
      },
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Update config successful');
    console.log('  Updated name:', response.data.config.name);
    return true;
  } catch (error: any) {
    console.error('✗ Update config failed:', error.message);
    return false;
  }
}

async function testReportDiscoveredDevices() {
  console.log('\n6. Testing Report Discovered Devices...');
  
  try {
    const response = await axios.post(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/discovered-devices`,
      {
        devices: [
          {
            vendorId: '0x0fe6',
            productId: '0x811e',
            manufacturer: 'Gprinter',
            model: 'GP-D320FX',
            port: 'USB001',
            connectionType: 'usb',
            discoveredAt: new Date().toISOString(),
          },
          {
            vendorId: '0x0fe6',
            productId: '0x811e',
            manufacturer: 'KPOS',
            model: 'KPOS_80',
            port: 'COM10',
            connectionType: 'serial',
            discoveredAt: new Date().toISOString(),
          },
        ],
      },
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Report discovered devices successful');
    console.log('  Suggested configs:', response.data.suggested_configs.length);
    return true;
  } catch (error: any) {
    console.error('✗ Report discovered devices failed:', error.message);
    return false;
  }
}

async function testReportDeviceStatus() {
  console.log('\n7. Testing Report Device Status...');
  
  try {
    const response = await axios.post(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/device-status`,
      {
        device_id: 'printer-001',
        status: 'ready',
        message: 'Printer is ready',
        timestamp: Date.now(),
      },
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Report device status successful');
    return true;
  } catch (error: any) {
    console.error('✗ Report device status failed:', error.message);
    return false;
  }
}

async function testFetchDeviceTemplates() {
  console.log('\n8. Testing Fetch Device Templates...');
  
  try {
    const response = await axios.get(
      `${BASE_URL}/api/v1/iot/device-templates`,
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Fetch templates successful');
    console.log('  Templates count:', response.data.templates.length);
    
    // List templates
    response.data.templates.forEach((template: any) => {
      console.log(`    - ${template.name} (${template.driverType})`);
    });
    
    return true;
  } catch (error: any) {
    console.error('✗ Fetch templates failed:', error.message);
    return false;
  }
}

async function testDeleteDeviceConfig(deviceId: string) {
  console.log('\n9. Testing Delete Device Config...');
  
  try {
    const response = await axios.delete(
      `${BASE_URL}/api/v1/iot/agents/${AGENT_ID}/device-configs/${deviceId}`,
      {
        headers: {
          Authorization: `Bearer ${agentToken}`,
        },
      }
    );
    
    console.log('✓ Delete config successful');
    return true;
  } catch (error: any) {
    console.error('✗ Delete config failed:', error.message);
    return false;
  }
}

async function runTests() {
  console.log('='.repeat(60));
  console.log('Mock Backend Server Tests');
  console.log('='.repeat(60));
  console.log('Make sure the mock backend is running:');
  console.log('  npm run mock-backend');
  console.log('='.repeat(60));
  
  // Wait a bit for user to read
  await new Promise(resolve => setTimeout(resolve, 2000));
  
  let deviceId: string | null = null;
  
  // Run tests
  const results = {
    registration: await testAgentRegistration(),
    heartbeat: await testHeartbeat(),
    fetchConfigs: await testFetchDeviceConfigs(),
    createConfig: (deviceId = await testCreateDeviceConfig()) !== null,
    updateConfig: deviceId ? await testUpdateDeviceConfig(deviceId) : false,
    reportDiscovered: await testReportDiscoveredDevices(),
    reportStatus: await testReportDeviceStatus(),
    fetchTemplates: await testFetchDeviceTemplates(),
    deleteConfig: deviceId ? await testDeleteDeviceConfig(deviceId) : false,
  };
  
  // Summary
  console.log('\n' + '='.repeat(60));
  console.log('Test Summary');
  console.log('='.repeat(60));
  
  const passed = Object.values(results).filter(r => r).length;
  const total = Object.keys(results).length;
  
  Object.entries(results).forEach(([test, result]) => {
    console.log(`  ${result ? '✓' : '✗'} ${test}`);
  });
  
  console.log('='.repeat(60));
  console.log(`Results: ${passed}/${total} tests passed`);
  console.log('='.repeat(60));
  
  if (passed === total) {
    console.log('✓ All tests passed!');
    process.exit(0);
  } else {
    console.log('✗ Some tests failed');
    process.exit(1);
  }
}

// Run tests
runTests().catch(error => {
  console.error('Test execution failed:', error);
  process.exit(1);
});
