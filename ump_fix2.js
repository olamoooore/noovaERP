const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Handle CRLF line endings
content = content.replace(/\r\n/g, '\n');

const oldCode = `    } else if (action === 'PRINT_LABEL' && data) {
      // Check if raw commands are already provided (from external system like NoovaERP)
      if (data.rawCommands) {
        processedPayload.rawCommands = data.rawCommands;
        processedPayload.printerType = 'label';
      } else if (data.commands && data.encoding === 'base64') {
        // Pre-encoded TSPL commands from external system
        processedPayload.rawCommands = Buffer.from(data.commands, 'base64').toString('utf8');
        processedPayload.printerType = 'label';
      } else {
        // Use PrinterTranslator to generate TSPL commands
        processedPayload.rawCommands = PrinterTranslator.generateTSPL(data);
        processedPayload.printerType = 'label';
      }`;

const newCode = `    } else if (action === 'PRINT_LABEL' && data) {
      // Check if raw commands are already provided (from external system like NoovaERP)
      if (data.rawCommands) {
        processedPayload.rawCommands = data.rawCommands;
        processedPayload.printerType = 'label';
      } else if (data.commands && data.encoding === 'base64') {
        // Pre-encoded TSPL commands from external system (base64)
        processedPayload.rawCommands = Buffer.from(data.commands, 'base64').toString('utf8');
        processedPayload.printerType = 'label';
      } else if (data.commands && typeof data.commands === 'string') {
        // Raw TSPL commands from external system (plain text)
        processedPayload.rawCommands = data.commands;
        processedPayload.printerType = 'label';
      } else {
        // Use PrinterTranslator to generate TSPL commands
        processedPayload.rawCommands = PrinterTranslator.generateTSPL(data);
        processedPayload.printerType = 'label';
      }`;

if (content.includes(oldCode)) {
  content = content.replace(oldCode, newCode);
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
}
