/**
 * Test Device Discovery Service
 * 
 * Simple test to verify device discovery works correctly.
 */

import { DeviceDiscoveryService } from '../src/devices/deviceDiscovery';

async function testDiscovery() {
  console.log('='.repeat(60));
  console.log('Device Discovery Service Test');
  console.log('='.repeat(60));

  const discovery = new DeviceDiscoveryService();

  try {
    // Scan for devices
    console.log('\n1. Scanning for devices...');
    const devices = await discovery.scanDevices();

    console.log(`\n✓ Found ${devices.length} device(s)\n`);

    // Display devices
    if (devices.length === 0) {
      console.log('No devices found.');
      console.log('This is normal if no USB/Serial/HID devices are connected.');
    } else {
      devices.forEach((device, index) => {
        console.log(`Device ${index + 1}:`);
        console.log(`  Type: ${device.connectionType}`);
        console.log(`  VID:PID: ${device.vendorId}:${device.productId}`);
        if (device.manufacturer) console.log(`  Manufacturer: ${device.manufacturer}`);
        if (device.model) console.log(`  Model: ${device.model}`);
        if (device.port) console.log(`  Port: ${device.port}`);
        if (device.serialNumber) console.log(`  Serial: ${device.serialNumber}`);
        console.log(`  Discovered: ${device.discoveredAt}`);
        console.log('');
      });
    }

    // Test device watching (run for 2 seconds)
    console.log('2. Testing device change monitoring (2 seconds)...');
    console.log('   Try connecting/disconnecting a USB device...\n');

    let eventCount = 0;
    await discovery.watchDeviceChanges((event) => {
      eventCount++;
      console.log(`   [${event.type.toUpperCase()}] ${event.device.vendorId}:${event.device.productId} (${event.device.connectionType})`);
    });

    // Wait 2 seconds
    await new Promise(resolve => setTimeout(resolve, 2000));

    discovery.stopWatching();

    if (eventCount === 0) {
      console.log('   No device changes detected (this is normal)');
    } else {
      console.log(`\n   Detected ${eventCount} device change(s)`);
    }

    console.log('\n' + '='.repeat(60));
    console.log('✓ Device Discovery Test Complete');
    console.log('='.repeat(60));

    process.exit(0);
  } catch (error: any) {
    console.error('\n✗ Test failed:', error.message);
    console.error(error.stack);
    process.exit(1);
  }
}

testDiscovery();
