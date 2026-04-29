/**
 * Test Driver Factory
 * 
 * Tests the driver factory with mock drivers.
 */

import { DriverFactory, DriverTypes } from '../src/devices/driverFactory';
import { DeviceDriver, DeviceStatus, DeviceCapability, BaseDeviceDriver } from '../src/devices/interfaces/deviceDriver';
import { DeviceConfiguration } from '../src/config/deviceConfigManager';

// Mock driver for testing
class MockPrinterDriver extends BaseDeviceDriver {
  private config: any;

  async initialize(config: any): Promise<void> {
    this.config = config;
    this.updateStatus('ready', 'Mock printer initialized');
  }

  async executeCommand(action: string, data: any): Promise<any> {
    this.validateAction(action);
    
    if (action === 'print_label') {
      return { success: true, message: 'Label printed' };
    }
    
    if (action === 'get_status') {
      return this.getStatus();
    }
    
    throw new Error(`Unsupported action: ${action}`);
  }

  getCapabilities(): DeviceCapability[] {
    return ['print_label', 'get_status'];
  }

  async close(): Promise<void> {
    this.updateStatus('offline', 'Mock printer closed');
  }
}

// Invalid driver (missing methods) for testing
class InvalidDriver {
  async initialize(config: any): Promise<void> {
    // Missing other required methods
  }
}

async function testDriverFactory() {
  console.log('='.repeat(60));
  console.log('Driver Factory Test');
  console.log('='.repeat(60));

  const factory = new DriverFactory();

  try {
    // Test 1: Register a driver
    console.log('\n1. Registering mock driver...');
    factory.registerDriver(
      'mock-printer',
      MockPrinterDriver,
      'Mock printer driver for testing'
    );
    console.log('✓ Driver registered');

    // Test 2: Check if driver is available
    console.log('\n2. Checking driver availability...');
    const isAvailable = factory.isDriverAvailable('mock-printer');
    if (!isAvailable) {
      throw new Error('Driver should be available');
    }
    console.log('✓ Driver is available');

    // Test 3: Get registered driver types
    console.log('\n3. Getting registered driver types...');
    const driverTypes = factory.getRegisteredDriverTypes();
    console.log(`✓ Found ${driverTypes.length} registered driver(s): ${driverTypes.join(', ')}`);

    // Test 4: Get driver info
    console.log('\n4. Getting driver info...');
    const driverInfo = factory.getDriverInfo('mock-printer');
    if (!driverInfo) {
      throw new Error('Driver info not found');
    }
    console.log(`✓ Driver info: ${driverInfo.description}`);

    // Test 5: Create a driver instance
    console.log('\n5. Creating driver instance...');
    const config: DeviceConfiguration = {
      id: 'device-test-001',
      agentId: 'agent-test-001',
      deviceType: 'printer',
      driverType: 'mock-printer',
      name: 'Test Mock Printer',
      enabled: true,
      connectionInfo: {
        printerName: 'Mock Printer',
      },
      driverConfig: {
        testParam: 'testValue',
      },
      capabilities: ['print_label', 'get_status'],
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    const driver = await factory.createDriver('mock-printer', config);
    console.log('✓ Driver instance created');

    // Test 6: Verify driver capabilities
    console.log('\n6. Verifying driver capabilities...');
    const capabilities = driver.getCapabilities();
    console.log(`✓ Driver capabilities: ${capabilities.join(', ')}`);

    // Test 7: Execute command
    console.log('\n7. Executing command...');
    const result = await driver.executeCommand('print_label', { text: 'Test' });
    console.log(`✓ Command executed: ${result.message}`);

    // Test 8: Get driver status
    console.log('\n8. Getting driver status...');
    const status = driver.getStatus();
    console.log(`✓ Driver status: ${status.state} - ${status.message}`);

    // Test 9: Close driver
    console.log('\n9. Closing driver...');
    await driver.close();
    const closedStatus = driver.getStatus();
    console.log(`✓ Driver closed: ${closedStatus.state}`);

    // Test 10: Try to create driver with invalid type
    console.log('\n10. Testing error handling (invalid driver type)...');
    try {
      await factory.createDriver('non-existent-driver', config);
      throw new Error('Should have thrown error for invalid driver type');
    } catch (error: any) {
      if (error.message.includes('Driver not found')) {
        console.log('✓ Correctly threw error for invalid driver type');
      } else {
        throw error;
      }
    }

    // Test 11: Register invalid driver
    console.log('\n11. Testing driver interface validation...');
    factory.registerDriver('invalid-driver', InvalidDriver as any);
    try {
      await factory.createDriver('invalid-driver', config);
      throw new Error('Should have thrown error for invalid driver interface');
    } catch (error: any) {
      if (error.message.includes('does not implement required methods')) {
        console.log('✓ Correctly validated driver interface');
      } else {
        throw error;
      }
    }

    // Test 12: Unregister driver
    console.log('\n12. Unregistering driver...');
    factory.unregisterDriver('mock-printer');
    const stillAvailable = factory.isDriverAvailable('mock-printer');
    if (stillAvailable) {
      throw new Error('Driver should not be available after unregistering');
    }
    console.log('✓ Driver unregistered');

    // Test 13: Get all drivers
    console.log('\n13. Getting all drivers...');
    factory.registerDriver('mock-printer', MockPrinterDriver);
    factory.registerDriver('mock-printer-2', MockPrinterDriver);
    const allDrivers = factory.getAllDrivers();
    console.log(`✓ Found ${allDrivers.length} driver(s)`);

    // Test 14: Clear all drivers
    console.log('\n14. Clearing all drivers...');
    factory.clearDrivers();
    const count = factory.getDriverCount();
    if (count !== 0) {
      throw new Error('All drivers should be cleared');
    }
    console.log('✓ All drivers cleared');

    // Test 15: Test driver type constants
    console.log('\n15. Testing driver type constants...');
    console.log(`✓ Driver type constants available:`);
    console.log(`   - ${DriverTypes.LABEL_PRINTER_RAW}`);
    console.log(`   - ${DriverTypes.RECEIPT_PRINTER_RAW}`);
    console.log(`   - ${DriverTypes.TERMINAL_MOCK}`);

    console.log('\n' + '='.repeat(60));
    console.log('✓ All Driver Factory Tests Passed!');
    console.log('='.repeat(60));

    process.exit(0);
  } catch (error: any) {
    console.error('\n✗ Test failed:', error.message);
    console.error(error.stack);
    process.exit(1);
  }
}

testDriverFactory();
