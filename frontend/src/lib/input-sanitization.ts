/**
 * Frontend Input Sanitization Utilities
 * 
 * Provides client-side input sanitization and validation
 * to prevent XSS attacks and ensure data integrity.
 * 
 * Requirements: 8.5 - Input sanitization validation
 */

/**
 * Dangerous HTML tags that should be stripped
 */
const DANGEROUS_TAGS = [
  'script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 
  'select', 'button', 'link', 'meta', 'style', 'base', 'applet'
];

/**
 * Allowed HTML tags for rich text content
 */
const ALLOWED_TAGS = [
  'p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'ul', 'ol', 'li', 'blockquote', 'a', 'img', 'div', 'span'
];

/**
 * Allowed attributes for HTML tags
 */
const ALLOWED_ATTRIBUTES = [
  'href', 'src', 'alt', 'title', 'class', 'id', 'style'
];

/**
 * Sanitize string input to prevent XSS attacks
 */
export function sanitizeString(input: string, allowHtml = false): string {
  if (typeof input !== 'string') {
    return String(input);
  }

  // Remove null bytes
  let sanitized = input.replace(/\0/g, '');
  
  // Trim whitespace
  sanitized = sanitized.trim();
  
  // Remove control characters except newlines and tabs. The control chars are the
  // point of this rule, so the no-control-regex warning is intentional.
  // eslint-disable-next-line no-control-regex
  sanitized = sanitized.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
  
  if (allowHtml) {
    return sanitizeHtml(sanitized);
  }
  
  // For non-HTML content, escape all HTML entities
  return escapeHtml(sanitized);
}

/**
 * Escape HTML entities
 */
export function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Sanitize HTML content while preserving safe tags
 */
export function sanitizeHtml(html: string): string {
  // Create a temporary DOM element
  const temp = document.createElement('div');
  temp.innerHTML = html;
  
  // Remove dangerous tags
  DANGEROUS_TAGS.forEach(tag => {
    const elements = temp.querySelectorAll(tag);
    elements.forEach(el => el.remove());
  });
  
  // Clean attributes
  const allElements = temp.querySelectorAll('*');
  allElements.forEach(element => {
    // Remove dangerous attributes
    Array.from(element.attributes).forEach(attr => {
      if (!ALLOWED_ATTRIBUTES.includes(attr.name.toLowerCase())) {
        element.removeAttribute(attr.name);
      } else {
        // Sanitize attribute values
        const sanitizedValue = sanitizeAttributeValue(attr.value);
        element.setAttribute(attr.name, sanitizedValue);
      }
    });
    
    // Remove elements with disallowed tags
    if (!ALLOWED_TAGS.includes(element.tagName.toLowerCase())) {
      // Replace with span to preserve content
      const span = document.createElement('span');
      span.innerHTML = element.innerHTML;
      element.parentNode?.replaceChild(span, element);
    }
  });
  
  return temp.innerHTML;
}

/**
 * Sanitize HTML attribute values
 */
function sanitizeAttributeValue(value: string): string {
  // Remove javascript: and data: protocols
  let sanitized = value.replace(/^(javascript|data|vbscript):/i, '');
  
  // Remove event handlers
  sanitized = sanitized.replace(/on\w+\s*=/gi, '');
  
  // Remove script-like content
  sanitized = sanitized.replace(/[<>"']/g, '');
  
  return sanitized.trim();
}

/**
 * Validate file upload on client side
 */
export function validateFileUpload(file: File): { valid: boolean; errors: string[] } {
  const errors: string[] = [];
  
  // Check file size (5MB limit)
  const maxSize = 5 * 1024 * 1024;
  if (file.size > maxSize) {
    errors.push(`File size exceeds maximum allowed size of ${maxSize / 1024 / 1024}MB`);
  }
  
  // Check file type
  const allowedTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
    'application/vnd.ms-excel', 
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  ];
  
  if (!allowedTypes.includes(file.type)) {
    errors.push('File type not allowed');
  }
  
  // Check file extension
  const extension = file.name.split('.').pop()?.toLowerCase();
  const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'xls', 'xlsx'];
  
  if (!extension || !allowedExtensions.includes(extension)) {
    errors.push('File extension not allowed');
  }
  
  // Check for executable files
  const executableExtensions = ['exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar', 'php', 'asp', 'jsp'];
  if (extension && executableExtensions.includes(extension)) {
    errors.push('Executable files are not allowed');
  }
  
  return {
    valid: errors.length === 0,
    errors
  };
}

/**
 * Sanitize filename for safe handling
 */
export function sanitizeFilename(filename: string): string {
  // Remove path traversal attempts
  let sanitized = filename.replace(/.*[/\\]/, '');
  
  // Remove dangerous characters
  sanitized = sanitized.replace(/[^a-zA-Z0-9._-]/g, '_');
  
  // Prevent hidden files
  sanitized = sanitized.replace(/^\.+/, '');
  
  // Ensure filename is not empty
  if (!sanitized) {
    sanitized = `file_${Date.now()}`;
  }
  
  // Limit filename length
  if (sanitized.length > 255) {
    const parts = sanitized.split('.');
    const extension = parts.pop() || '';
    const name = parts.join('.').substring(0, 250 - extension.length);
    sanitized = `${name}.${extension}`;
  }
  
  return sanitized;
}

/**
 * Validate email address
 */
export function validateEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}

/**
 * Validate URL
 */
export function validateUrl(url: string): boolean {
  try {
    const urlObj = new URL(url);
    return ['http:', 'https:'].includes(urlObj.protocol);
  } catch {
    return false;
  }
}

/**
 * Sanitize object recursively
 */
export function sanitizeObject<T extends Record<string, any>>(
  obj: T, 
  allowHtml = false
): T {
  const sanitized = {} as T;
  
  for (const [key, value] of Object.entries(obj)) {
    const sanitizedKey = sanitizeString(key) as keyof T;
    
    if (typeof value === 'string') {
      sanitized[sanitizedKey] = sanitizeString(value, allowHtml) as T[keyof T];
    } else if (Array.isArray(value)) {
      sanitized[sanitizedKey] = value.map(item => 
        typeof item === 'string' ? sanitizeString(item, allowHtml) : 
        typeof item === 'object' && item !== null ? sanitizeObject(item, allowHtml) : 
        item
      ) as T[keyof T];
    } else if (typeof value === 'object' && value !== null) {
      sanitized[sanitizedKey] = sanitizeObject(value, allowHtml) as T[keyof T];
    } else {
      sanitized[sanitizedKey] = value;
    }
  }
  
  return sanitized;
}

/**
 * Check for potential XSS patterns
 */
export function detectXssPatterns(input: string): boolean {
  const xssPatterns = [
    /<script\b[^>]*>/i,
    /javascript:/i,
    /on\w+\s*=/i,
    /<iframe\b[^>]*>/i,
    /eval\s*\(/i,
    /expression\s*\(/i
  ];
  
  return xssPatterns.some(pattern => pattern.test(input));
}

/**
 * Log security violation (client-side)
 */
export function logSecurityViolation(type: string, input: string, context: Record<string, any> = {}): void {
  console.warn('Input sanitization security violation', {
    type,
    input: input.substring(0, 200), // Limit logged input length
    context,
    timestamp: new Date().toISOString(),
    userAgent: navigator.userAgent
  });
  
  // In production, you might want to send this to a logging service
  // fetch('/api/security-violations', { method: 'POST', body: JSON.stringify({ type, input, context }) });
}