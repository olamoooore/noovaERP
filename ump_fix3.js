const fs = require('fs');
const filePath = '/var/www/Universal-Middleware-platform/controllers/universalDeviceController.js';
let content = fs.readFileSync(filePath, 'utf8');

// Convert to LF for processing
content = content.replace(/\r\n/g, '\n');

// Find and replace the label section
const oldLabelCode = `      } else {
        // Use PrinterTranslator to generate TSPL commands
        processedPayload.rawCommands = PrinterTranslator.generateTSPL(data);
        processedPayload.printerType = 'label';
      }
    } else if (action === 'PRINT_RECEIPT' && data) {`;

const newLabelCode = `      } else if (data.commands && typeof data.commands === 'string') {
        // Raw TSPL commands from external system (plain text)
        processedPayload.rawCommands = data.commands;
        processedPayload.printerType = 'label';
      } else {
        // Use PrinterTranslator to generate TSPL commands
        processedPayload.rawCommands = PrinterTranslator.generateTSPL(data);
        processedPayload.printerType = 'label';
      }
    } else if (action === 'PRINT_RECEIPT' && data) {`;

if (content.includes(oldLabelCode)) {
  content = content.replace(oldLabelCode, newLabelCode);
  // Restore CRLF
  content = content.replace(/\n/g, '\r\n');
  fs.writeFileSync(filePath, content, 'utf8');
  console.log('File updated successfully');
} else {
  console.log('Could not find the code to replace');
  // Debug
  const lines = content.split('\n');
  for (let i = 235; i < 250 && i < lines.length; i++) {
    console.log(`Line ${i+1}: ${JSON.stringify(lines[i])}`);
  }
}
