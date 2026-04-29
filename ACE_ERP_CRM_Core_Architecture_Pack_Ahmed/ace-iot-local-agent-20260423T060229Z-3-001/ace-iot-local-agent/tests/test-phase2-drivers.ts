/**
 * Phase 2 Driver Refactoring Tests
 * 
 * Tests all refactored drivers to ensure they implement the DeviceDriver interface correctly.
 */

import { driverFactory, DriverTypes } from '../src/devices/driverFactory';
import { registerAllDrivers } from '../src/devices/registerDrivers';
import { DeviceConfiguration } from '../src/config/deviceConfigManager';

console.log('='.repeat(60));
console.log('Phase 2: Driver Refactoring Tests');
console.log('='.repeat(60));
console.log();

async function runTests() {
  let testsPassed = 0;
  let testsFailed = 0;

  // Test 1: Register all drivers
  console.log('Test 1: Register All Drivers');
  console.log('-'.repeat(60));
  try {
    registerAllDrivers();
    const registeredDrivers = driverFactory.getRegisteredDriverTypes();
    console.log('✓ Registered drivers:', registeredDrivers);
    console.log('✓ Driver count:', registeredDrivers.length);
    
    if (registeredDrivers.length === 3) {
      console.log('✓ All 3 drivers registered successfully');
      testsPassed++;
    } else {
      console.log('✗ Expected 3 drivers, got', registeredDrivers.length);
      testsFailed++;
    }
  } catch (error: any) {
    console.error('✗ Failed to register drivers:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 2: Label Printer Driver
  console.log('Test 2: Label Printer Driver');
  console.log('-'.repeat(60));
  try {
    const config: DeviceConfiguration = {
      id: 'label-001',
      agentId: 'test-agent',
      name: 'Test Label Printer',
      deviceType: 'printer',
      driverType: DriverTypes.LABEL_PRINTER_RAW,
      connectionInfo: {
        printerName: 'Gprinter GP-D320FX'
      },
      driverConfig: {},
      capabilities: ['print_label', 'get_status'],
      enabled: true,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    const driver = await driverFactory.createDriver(DriverTypes.LABEL_PRINTER_RAW, config);
    console.log('✓ Driver created successfully');

    // Check capabilities
    const capabilities = driver.getCapabilities();
    console.log('✓ Capabilities:', capabilities);
    
    if (capabilities.includes('print_label') && capabilities.includes('get_status')) {
      console.log('✓ Has required capabilities');
      testsPassed++;
    } else {
      console.log('✗ Missing required capabilities');
      testsFailed++;
    }

    // Check status
    const status = driver.getStatus();
    console.log('✓ Status:', status);

    // Test unsupported action
    try {
      await driver.executeCommand('invalid_action', {});
      console.log('✗ Should have thrown error for invalid action');
      testsFailed++;
    } catch (error: any) {
      console.log('✓ Correctly rejected invalid action');
      testsPassed++;
    }

    await driver.close();
    console.log('✓ Driver closed successfully');
  } catch (error: any) {
    console.error('✗ Label printer test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 3: Receipt Printer Driver
  console.log('Test 3: Receipt Printer Driver');
  console.log('-'.repeat(60));
  try {
    const config: DeviceConfiguration = {
      id: 'receipt-001',
      agentId: 'test-agent',
      name: 'Test Receipt Printer',
      deviceType: 'printer',
      driverType: DriverTypes.RECEIPT_PRINTER_RAW,
      connectionInfo: {
        printerName: 'KPOS_80'
      },
      driverConfig: {},
      capabilities: ['print_receipt', 'get_status'],
      enabled: true,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    const driver = await driverFactory.createDriver(DriverTypes.RECEIPT_PRINTER_RAW, config);
    console.log('✓ Driver created successfully');

    // Check capabilities
    const capabilities = driver.getCapabilities();
    console.log('✓ Capabilities:', capabilities);
    
    if (capabilities.includes('print_receipt') && capabilities.includes('get_status')) {
      console.log('✓ Has required capabilities');
      testsPassed++;
    } else {
      console.log('✗ Missing required capabilities');
      testsFailed++;
    }

    // Check status
    const status = driver.getStatus();
    console.log('✓ Status:', status);

    // Test unsupported action
    try {
      await driver.executeCommand('invalid_action', {});
      console.log('✗ Should have thrown error for invalid action');
      testsFailed++;
    } catch (error: any) {
      console.log('✓ Correctly rejected invalid action');
      testsPassed++;
    }

    await driver.close();
    console.log('✓ Driver closed successfully');
  } catch (error: any) {
    console.error('✗ Receipt printer test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 4: Mock Terminal Driver
  console.log('Test 4: Mock Terminal Driver');
  console.log('-'.repeat(60));
  try {
    const config: DeviceConfiguration = {
      id: 'terminal-001',
      agentId: 'test-agent',
      name: 'Test Mock Terminal',
      deviceType: 'terminal',
      driverType: DriverTypes.TERMINAL_MOCK,
      connectionInfo: {},
      driverConfig: {},
      capabilities: ['create_payment', 'check_payment_status', 'cancel_payment', 'get_status'],
      enabled: true,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    const driver = await driverFactory.createDriver(DriverTypes.TERMINAL_MOCK, config);
    console.log('✓ Driver created successfully');

    // Check capabilities
    const capabilities = driver.getCapabilities();
    console.log('✓ Capabilities:', capabilities);
    
    const expectedCapabilities = ['create_payment', 'check_payment_status', 'cancel_payment', 'get_status'];
    const hasAllCapabilities = expectedCapabilities.every(cap => capabilities.includes(cap as any));
    
    if (hasAllCapabilities) {
      console.log('✓ Has all required capabilities');
      testsPassed++;
    } else {
      console.log('✗ Missing required capabilities');
      testsFailed++;
    }

    // Check status
    const status = driver.getStatus();
    console.log('✓ Status:', status);

    // Test get_status command
    const statusResult = await driver.executeCommand('get_status', {});
    console.log('✓ get_status command executed:', statusResult);
    testsPassed++;

    // Test unsupported action
    try {
      await driver.executeCommand('invalid_action', {});
      console.log('✗ Should have thrown error for invalid action');
      testsFailed++;
    } catch (error: any) {
      console.log('✓ Correctly rejected invalid action');
      testsPassed++;
    }

    await driver.close();
    console.log('✓ Driver closed successfully');
  } catch (error: any) {
    console.error('✗ Mock terminal test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 5: Driver Factory - Check All Drivers Available
  console.log('Test 5: Driver Factory - All Drivers Available');
  console.log('-'.repeat(60));
  try {
    const availableDrivers = [
      DriverTypes.LABEL_PRINTER_RAW,
      DriverTypes.RECEIPT_PRINTER_RAW,
      DriverTypes.TERMINAL_MOCK,
    ];

    let allAvailable = true;
    for (const driverType of availableDrivers) {
      const isAvailable = driverFactory.isDriverAvailable(driverType);
      console.log(`  ${driverType}: ${isAvailable ? '✓' : '✗'}`);
      if (!isAvailable) {
        allAvailable = false;
      }
    }

    if (allAvailable) {
      console.log('✓ All drivers are available');
      testsPassed++;
    } else {
      console.log('✗ Some drivers are not available');
      testsFailed++;
    }
  } catch (error: any) {
    console.error('✗ Driver availability test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 6: Driver Info
  console.log('Test 6: Driver Info');
  console.log('-'.repeat(60));
  try {
    const allDrivers = driverFactory.getAllDrivers();
    console.log('✓ Retrieved driver info for', allDrivers.length, 'drivers');
    
    allDrivers.forEach(driver => {
      console.log(`  - ${driver.driverType}: ${driver.description}`);
    });

    if (allDrivers.length === 3) {
      console.log('✓ All driver info retrieved');
      testsPassed++;
    } else {
      console.log('✗ Expected 3 drivers, got', allDrivers.length);
      testsFailed++;
    }
  } catch (error: any) {
    console.error('✗ Driver info test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Test 7: Interface Validation
  console.log('Test 7: Interface Validation');
  console.log('-'.repeat(60));
  try {
    // Create a mock driver that doesn't implement the interface properly
    class InvalidDriver {
      // Missing required methods
    }

    driverFactory.registerDriver('invalid-driver', InvalidDriver as any, 'Invalid driver for testing');

    const config: DeviceConfiguration = {
      id: 'invalid-001',
      agentId: 'test-agent',
      name: 'Invalid Driver',
      deviceType: 'printer',
      driverType: 'invalid-driver',
      connectionInfo: {},
      driverConfig: {},
      capabilities: [],
      enabled: true,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    };

    try {
      await driverFactory.createDriver('invalid-driver', config);
      console.log('✗ Should have thrown error for invalid driver');
      testsFailed++;
    } catch (error: any) {
      console.log('✓ Correctly rejected invalid driver:', error.message);
      testsPassed++;
    }

    // Clean up
    driverFactory.unregisterDriver('invalid-driver');
  } catch (error: any) {
    console.error('✗ Interface validation test failed:', error.message);
    testsFailed++;
  }
  console.log();

  // Summary
  console.log('='.repeat(60));
  console.log('Test Summary');
  console.log('='.repeat(60));
  console.log(`Tests Passed: ${testsPassed}`);
  console.log(`Tests Failed: ${testsFailed}`);
  console.log(`Total Tests: ${testsPassed + testsFailed}`);
  console.log();

  if (testsFailed === 0) {
    console.log('✓ ALL TESTS PASSED!');
    console.log();
    console.log('Phase 2 Status: ✅ COMPLETE');
    console.log();
    console.log('All drivers have been successfully refactored to implement');
    console.log('the DeviceDriver interface and registered with DriverFactory.');
  } else {
    console.log('✗ SOME TESTS FAILED');
    process.exit(1);
  }
}

runTests().catch(error => {
  console.error('Test execution failed:', error);
  process.exit(1);
});
