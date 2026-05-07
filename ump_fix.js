const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Handle CRLF line endings
content = content.replace(/\r\n/g, '\n');

const oldCode = `    // Process the command based on action type
    let processedPayload = { action, data, options };
    
    // Handle printer-specific translations
    if (action === 'PRINT_LABEL' && data) {
      // Use PrinterTranslator to generate TSPL commands
      processedPayload.rawCommands = PrinterTranslator.generateTSPL(data);
      processedPayload.printerType = 'label';
    } else if (action === 'PRINT_RECEIPT' && data) {
      // Use PrinterTranslator to generate ESC/POS commands
      processedPayload.rawCommands = PrinterTranslator.generateEscPos(data);
      processedPayload.printerType = 'receipt';
    } else if (action === 'PRINT_FILE' && req.file) {`;

const newCode = `    // Process the command based on action type
    let processedPayload = { action, data, options };
    
    // Handle printer-specific translations
    if (action === 'PRINT_LABEL' && data) {
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
      }
    } else if (action === 'PRINT_RECEIPT' && data) {
      // Check if raw commands are already provided (from external system like NoovaERP)
      if (data.rawCommands) {
        processedPayload.rawCommands = data.rawCommands;
        processedPayload.printerType = 'receipt';
      } else if (data.commands && data.encoding === 'base64') {
        // Pre-encoded ESC/POS commands from external system
        processedPayload.rawCommands = Buffer.from(data.commands, 'base64');
        processedPayload.printerType = 'receipt';
      } else {
        // Use PrinterTranslator to generate ESC/POS commands
        processedPayload.rawCommands = PrinterTranslator.generateEscPos(data);
        processedPayload.printerType = 'receipt';
      }
    } else if (action === 'PRINT_FILE' && req.file) {`;

if (content.includes(oldCode)) {
  content = content.replace(oldCode, newCode);
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
  // Debug: show what's around line 226
  const lines = content.split('\n');
  for (let i = 224; i < 245 && i < lines.length; i++) {
    console.log(`Line ${i+1}: ${JSON.stringify(lines[i])}`);
  }
}
