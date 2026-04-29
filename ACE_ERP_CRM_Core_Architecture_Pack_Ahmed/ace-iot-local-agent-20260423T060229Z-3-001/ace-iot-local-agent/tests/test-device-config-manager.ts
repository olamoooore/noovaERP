/**
 * Test Device Configuration Manager
 * 
 * Tests the device configuration manager with the mock backend.
 * 
 * Prerequisites: Mock backend must be running (npm run mock-backend)
 */

import { DeviceConfigurationManager } from '../src/config/deviceConfigManager';
import { ApiClient } from '../src/api/client';
import fs from 'fs';
import path from 'path';

const MOCK_BACKEND_URL = 'http://localhost:8000';
const AGENT_ID = 'test-agent-config-001';
const CACHE_DIR = path.join(process.cwd(), 'tests', 'cache');

async function testConfigManager() {
  console.log('='.repeat(60));
  console.log('Device Configuration Manager Test');
  console.log('='.repeat(60));
  console.log('Prerequisites: Mock backend must be running');
  console.log('  npm run mock-backend');
  console.log('='.repeat(60));

  // Clean up cache directory
  if (fs.existsSync(CACHE_DIR)) {
    fs.rmSync(CACHE_DIR, { recursive: true });
  }
  fs.mkdirSync(CACHE_DIR, { recursive: true });

  // Create API client
  const apiClient = new ApiClient(MOCK_BACKEND_URL);

  try {
    // Register agent first
    console.log('\n1. Registering agent...');
    await apiClient.register({
      agent_id: AGENT_ID,
      agent_name: 'Test Config Agent',
      agent_version: '1.0.0',
      machine_id: 'test-machine',
    });
    console.log('✓ Agent registered');

    // Create config manager
    const configManager = new DeviceConfigurationManager(
      apiClient,
      AGENT_ID,
      CACHE_DIR
    );

    // Test 1: Fetch configurations (should be empty initially)
    console.log('\n2. Fetching configurations (should be empty)...');
    const configs1 = await configManager.fetchConfigurations();
    console.log(`✓ Fetched ${configs1.length} configuration(s)`);

    if (configs1.length !== 0) {
      throw new Error('Expected 0 configurations');
    }

    // Test 2: Sync configurations
    console.log('\n3. Syncing configurations...');
    const synced = await configManager.syncConfigurations();
    console.log(`✓ Synced ${synced.length} configuration(s)`);

    // Test 3: Check cache file was created
    console.log('\n4. Checking cache file...');
    const cacheFile = configManager.getCacheFilePath();
    if (!fs.existsSync(cacheFile)) {
      throw new Error('Cache file was not created');
    }
    console.log(`✓ Cache file created: ${cacheFile}`);

    // Test 4: Apply a configuration
    console.log('\n5. Applying a test configuration...');
    const testConfig = {
      id: 'device-test-001',
      agentId: AGENT_ID,
      deviceType: 'printer' as const,
      driverType: 'label-printer-raw',
      name: 'Test Label Printer',
      enabled: true,
      connectionInfo: {
        printerName: 'Test Printer',
        vendorId: '0x0fe6',
        productId: '0x811e',
      },
      driverConfig: {
        labelWidth: 76,
        labelHeight: 50,
      },
      capabilities: ['print_label' as const, 'get_status' as const],
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    await configManager.applyConfiguration(testConfig);
    console.log('✓ Configuration applied');

    // Test 5: Get configuration
    console.log('\n6. Getting configuration...');
    const retrieved = configManager.getConfiguration('device-test-001');
    if (!retrieved) {
      throw new Error('Configuration not found');
    }
    console.log(`✓ Retrieved configuration: ${retrieved.name}`);

    // Test 6: Get all configurations
    console.log('\n7. Getting all configurations...');
    const allConfigs = configManager.getAllConfigurations();
    console.log(`✓ Found ${allConfigs.length} configuration(s)`);

    // Test 7: Get enabled configurations
    console.log('\n8. Getting enabled configurations...');
    const enabledConfigs = configManager.getEnabledConfigurations();
    console.log(`✓ Found ${enabledConfigs.length} enabled configuration(s)`);

    // Test 8: Get configurations by type
    console.log('\n9. Getting printer configurations...');
    const printerConfigs = configManager.getConfigurationsByType('printer');
    console.log(`✓ Found ${printerConfigs.length} printer configuration(s)`);

    // Test 9: Remove configuration
    console.log('\n10. Removing configuration...');
    await configManager.removeConfiguration('device-test-001');
    const removed = configManager.getConfiguration('device-test-001');
    if (removed) {
      throw new Error('Configuration was not removed');
    }
    console.log('✓ Configuration removed');

    // Test 10: Load from cache (simulate offline mode)
    console.log('\n11. Testing cache loading (offline mode simulation)...');
    
    // Apply a config and cache it
    await configManager.applyConfiguration(testConfig);
    
    // Create new config manager (simulates restart)
    const configManager2 = new DeviceConfigurationManager(
      apiClient,
      AGENT_ID,
      CACHE_DIR
    );
    
    // Load from cache
    const cachedConfigs = await configManager2.loadCachedConfigurations();
    console.log(`✓ Loaded ${cachedConfigs.length} configuration(s) from cache`);

    if (cachedConfigs.length === 0) {
      throw new Error('Failed to load from cache');
    }

    console.log('\n' + '='.repeat(60));
    console.log('✓ All Device Configuration Manager Tests Passed!');
    console.log('='.repeat(60));

    // Cleanup
    if (fs.existsSync(CACHE_DIR)) {
      fs.rmSync(CACHE_DIR, { recursive: true });
    }

    process.exit(0);
  } catch (error: any) {
    console.error('\n✗ Test failed:', error.message);
    console.error(error.stack);

    // Cleanup
    if (fs.existsSync(CACHE_DIR)) {
      fs.rmSync(CACHE_DIR, { recursive: true });
    }

    process.exit(1);
  }
}

testConfigManager();
