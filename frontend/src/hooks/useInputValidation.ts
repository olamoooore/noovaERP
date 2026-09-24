import { useState, useCallback } from 'react';
import { 
  sanitizeString, 
  validateEmail, 
  validateUrl, 
  detectXssPatterns, 
  logSecurityViolation 
} from '@/lib/input-sanitization';

/**
 * Input validation hook for React forms
 * 
 * Provides comprehensive input validation and sanitization
 * for form inputs to prevent injection attacks.
 * 
 * Requirements: 8.5 - Input sanitization validation
 */

export interface ValidationRule {
  required?: boolean;
  minLength?: number;
  maxLength?: number;
  pattern?: RegExp;
  email?: boolean;
  url?: boolean;
  allowHtml?: boolean;
  custom?: (value: string) => string | null;
}

export interface ValidationResult {
  isValid: boolean;
  errors: string[];
  sanitizedValue: string;
}

export interface UseInputValidationReturn {
  validateInput: (value: string, rules: ValidationRule) => ValidationResult;
  validateForm: (formData: Record<string, any>, rules: Record<string, ValidationRule>) => {
    isValid: boolean;
    errors: Record<string, string[]>;
    sanitizedData: Record<string, any>;
  };
  sanitizeInput: (value: string, allowHtml?: boolean) => string;
}

export function useInputValidation(): UseInputValidationReturn {
  const [securityViolations, setSecurityViolations] = useState<number>(0);

  const validateInput = useCallback((value: string, rules: ValidationRule): ValidationResult => {
    const errors: string[] = [];
    let sanitizedValue = value;

    // Check for XSS patterns before sanitization
    if (detectXssPatterns(value)) {
      logSecurityViolation('xss_attempt', value, { rules });
      setSecurityViolations(prev => prev + 1);
    }

    // Sanitize input
    sanitizedValue = sanitizeString(value, rules.allowHtml || false);

    // Required validation
    if (rules.required && !sanitizedValue.trim()) {
      errors.push('This field is required');
    }

    // Length validations
    if (rules.minLength && sanitizedValue.length < rules.minLength) {
      errors.push(`Minimum length is ${rules.minLength} characters`);
    }

    if (rules.maxLength && sanitizedValue.length > rules.maxLength) {
      errors.push(`Maximum length is ${rules.maxLength} characters`);
      // Truncate if too long
      sanitizedValue = sanitizedValue.substring(0, rules.maxLength);
    }

    // Pattern validation
    if (rules.pattern && sanitizedValue && !rules.pattern.test(sanitizedValue)) {
      errors.push('Invalid format');
    }

    // Email validation
    if (rules.email && sanitizedValue && !validateEmail(sanitizedValue)) {
      errors.push('Invalid email address');
    }

    // URL validation
    if (rules.url && sanitizedValue && !validateUrl(sanitizedValue)) {
      errors.push('Invalid URL');
    }

    // Custom validation
    if (rules.custom && sanitizedValue) {
      const customError = rules.custom(sanitizedValue);
      if (customError) {
        errors.push(customError);
      }
    }

    return {
      isValid: errors.length === 0,
      errors,
      sanitizedValue
    };
  }, []);

  const validateForm = useCallback((
    formData: Record<string, any>, 
    rules: Record<string, ValidationRule>
  ) => {
    const errors: Record<string, string[]> = {};
    const sanitizedData: Record<string, any> = {};
    let isValid = true;

    for (const [field, value] of Object.entries(formData)) {
      const fieldRules = rules[field];
      
      if (fieldRules && typeof value === 'string') {
        const result = validateInput(value, fieldRules);
        
        if (!result.isValid) {
          errors[field] = result.errors;
          isValid = false;
        }
        
        sanitizedData[field] = result.sanitizedValue;
      } else {
        // For non-string values, pass through as-is
        sanitizedData[field] = value;
      }
    }

    return {
      isValid,
      errors,
      sanitizedData
    };
  }, [validateInput]);

  const sanitizeInput = useCallback((value: string, allowHtml = false): string => {
    return sanitizeString(value, allowHtml);
  }, []);

  return {
    validateInput,
    validateForm,
    sanitizeInput
  };
}

/**
 * Common validation rules for different input types
 */
export const ValidationRules = {
  email: {
    required: true,
    email: true,
    maxLength: 255
  },
  password: {
    required: true,
    minLength: 8,
    maxLength: 128,
    pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/
  },
  name: {
    required: true,
    minLength: 2,
    maxLength: 100,
    pattern: /^[a-zA-Z\s'-]+$/
  },
  phone: {
    required: true,
    pattern: /^\+?[\d\s\-()]+$/,
    minLength: 10,
    maxLength: 20
  },
  url: {
    url: true,
    maxLength: 2048
  },
  description: {
    maxLength: 1000,
    allowHtml: false
  },
  richText: {
    maxLength: 5000,
    allowHtml: true
  },
  filename: {
    pattern: /^[a-zA-Z0-9._-]+$/,
    maxLength: 255
  }
} as const;