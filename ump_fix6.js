const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Convert to LF for processing
content = content.replace(/\r\n/g, '\n');

// Fix: Use PRINT_RECEIPT for Job type (matches enum), but map to edge agent type for MQTT
const oldMapping = `    // Map UMP action types to Edge Agent expected job types
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
      // For receipt printers, send raw ESC/POS commands as content (convert Buffer to string)
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('binary') 
        : processedPayload.rawCommands;
    } else if (action === 'PRINT_LABEL' && processedPayload.rawCommands) {
      // For label printers, send TSPL commands as content
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('utf8') 
        : processedPayload.rawCommands;
    }
    
    // Create job record
    const job = new Job({
      jobId,
      type: edgeAgentJobType, // Use mapped type for Edge Agent
      status: 'queued',`;

const newMapping = `    // Map UMP action types to Edge Agent expected job types
    // Job.type must match DB enum (PRINT_RECEIPT, PRINT_LABEL, etc.)
    // MQTT payload.type must match edge agent expectations (print_receipt, print-label, etc.)
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
      // For receipt printers, send raw ESC/POS commands as content (convert Buffer to string)
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('binary') 
        : processedPayload.rawCommands;
    } else if (action === 'PRINT_LABEL' && processedPayload.rawCommands) {
      // For label printers, send TSPL commands as content
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('utf8') 
        : processedPayload.rawCommands;
    }
    
    // Create job record - use original action type (matches DB enum)
    const job = new Job({
      jobId,
      type: action, // Use original action type for DB (PRINT_RECEIPT, PRINT_LABEL, etc.)
      status: 'queued',`;

if (content.includes(oldMapping)) {
  content = content.replace(oldMapping, newMapping);
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
}
