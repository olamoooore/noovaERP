const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Convert to LF for processing
content = content.replace(/\r\n/g, '\n');

// Fix: Convert Buffer to string for receipt printer
const oldPayload = `    if (action === 'PRINT_RECEIPT' && processedPayload.rawCommands) {
      // For receipt printers, send raw ESC/POS commands as content
      agentPayload.content = processedPayload.rawCommands;
    } else if (action === 'PRINT_LABEL' && processedPayload.rawCommands) {
      // For label printers, send TSPL commands as content
      agentPayload.content = processedPayload.rawCommands;
    }`;

const newPayload = `    if (action === 'PRINT_RECEIPT' && processedPayload.rawCommands) {
      // For receipt printers, send raw ESC/POS commands as content (convert Buffer to string)
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('binary') 
        : processedPayload.rawCommands;
    } else if (action === 'PRINT_LABEL' && processedPayload.rawCommands) {
      // For label printers, send TSPL commands as content
      agentPayload.content = Buffer.isBuffer(processedPayload.rawCommands) 
        ? processedPayload.rawCommands.toString('utf8') 
        : processedPayload.rawCommands;
    }`;

if (content.includes(oldPayload)) {
  content = content.replace(oldPayload, newPayload);
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
}
