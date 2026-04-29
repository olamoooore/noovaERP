export class AgentError extends Error {
  constructor(
    message: string,
    public code: string,
    public details?: any
  ) {
    super(message);
    this.name = 'AgentError';
  }
}

export class DeviceError extends AgentError {
  constructor(message: string, code: string, details?: any) {
    super(message, code, details);
    this.name = 'DeviceError';
  }
}

export class ConnectionError extends AgentError {
  constructor(message: string, details?: any) {
    super(message, 'CONNECTION_ERROR', details);
    this.name = 'ConnectionError';
  }
}

export class AuthenticationError extends AgentError {
  constructor(message: string, details?: any) {
    super(message, 'AUTH_ERROR', details);
    this.name = 'AuthenticationError';
  }
}

export const ErrorCodes = {
  DEVICE_NOT_FOUND: 'DEVICE_NOT_FOUND',
  DEVICE_OFFLINE: 'DEVICE_OFFLINE',
  DEVICE_BUSY: 'DEVICE_BUSY',
  DRIVER_ERROR: 'DRIVER_ERROR',
  PAYMENT_DECLINED: 'PAYMENT_DECLINED',
  PAYMENT_TIMEOUT: 'PAYMENT_TIMEOUT',
  PAYMENT_CANCELLED: 'PAYMENT_CANCELLED',
  AUTH_FAILED: 'AUTH_FAILED',
  BACKEND_UNREACHABLE: 'BACKEND_UNREACHABLE',
  INVALID_MESSAGE: 'INVALID_MESSAGE',
  COMMAND_FAILED: 'COMMAND_FAILED',
} as const;
