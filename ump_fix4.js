const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Convert to LF for processing
content = content.replace(/\r\n/g, '\n');

// Fix 1: Map action types and fix Job creation
const oldJobCreation = `    // Create job record
    const job = new Job({
      jobId,
      type: action,
      status: 'queued',
      companyId: agent.tenantId, // Required field
      agentId: agent._id,
      deviceId: deviceId,
      tenantId: agent.tenantId,
      payload: processedPayload,`;

const newJobCreation = `    // Map UMP action types to Edge Agent expected job types
    const edgeAgentJobTypeMap = {
      'PRINT_RECEIPT': 'print_receipt',
      'PRINT_LABEL': 'print-label',
      'PRINT_RAW': 'print_raw',
      'PRINT_FILE': 'print',
    };
    const edgeAgentJobType = edgeAgentJobTypeMap[action] || action.toLowerCase();
    
    // Structure payload for Edge Agent handlers
    let agentPayload = { ...processedPayload };
    if (action === 'PRINT_RECEIPT' && processedPayload.rawCommands) {
      // For receipt printers, send raw ESC/POS commands as content
      agentPayload.content = processedPayload.rawCommands;
    } else if (action === 'PRINT_LABEL' && processedPayload.rawCommands) {
      // For label printers, send TSPL commands as content
      agentPayload.content = processedPayload.rawCommands;
    }
    
    // Create job record
    const job = new Job({
      jobId,
      type: edgeAgentJobType, // Use mapped type for Edge Agent
      status: 'queued',
      companyId: agent.tenantId, // Required field
      agentId: agent._id,
      deviceId: deviceId,
      tenantId: agent.tenantId,
      payload: agentPayload,`;

if (content.includes(oldJobCreation)) {
  content = content.replace(oldJobCreation, newJobCreation);
  
  // Fix 2: Update MQTT payload to use mapped type
  const oldMqttPayload = `    const mqttPayload = {
      jobId,
      type: action, // Edge Agent expects 'type' field
      action,
      deviceId,
      payload: processedPayload,
      timestamp: new Date().toISOString()
    };`;

  const newMqttPayload = `    const mqttPayload = {
      jobId,
      type: edgeAgentJobType, // Use mapped type for Edge Agent
      action,
      deviceId,
      payload: agentPayload,
      timestamp: new Date().toISOString()
    };`;

  if (content.includes(oldMqttPayload)) {
    content = content.replace(oldMqttPayload, newMqttPayload);
  }
  
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
  // Debug
  const lines = content.split('\n');
  for (let i = 268; i < 285 && i < lines.length; i++) {
    console.log(`Line ${i+1}: ${JSON.stringify(lines[i])}`);
  }
}
