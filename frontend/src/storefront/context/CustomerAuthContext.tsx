import React, { createContext, useContext, useState, useCallback, useEffect } from 'react';
import { 
  CustomerProfile, 
  customerLogin, 
  customerRegister, 
  customerLogout, 
  getCustomerProfile,
  CustomerLoginPayload,
  CustomerRegisterPayload
} from '@/storefront/lib/api';

const AUTH_TOKEN_KEY = 'storefront_customer_token';

interface CustomerAuthContextType {
  customer: CustomerProfile | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (payload: CustomerLoginPayload) => Promise<{ success: boolean; message?: string }>;
  register: (payload: CustomerRegisterPayload) => Promise<{ success: boolean; message?: string }>;
  logout: () => Promise<void>;
  refreshProfile: () => Promise<void>;
}

const CustomerAuthContext = createContext<CustomerAuthContextType | undefined>(undefined);

export const CustomerAuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [customer, setCustomer] = useState<CustomerProfile | null>(null);
  const [token, setToken] = useState<string | null>(() => {
    if (typeof window !== 'undefined') {
      return localStorage.getItem(AUTH_TOKEN_KEY);
    }
    return null;
  });
  const [isLoading, setIsLoading] = useState(true);

  // Load profile on mount if token exists
  useEffect(() => {
    const loadProfile = async () => {
      if (token) {
        try {
          const profile = await getCustomerProfile(token);
          setCustomer(profile);
        } catch (error) {
          // Token invalid, clear it
          localStorage.removeItem(AUTH_TOKEN_KEY);
          setToken(null);
          setCustomer(null);
        }
      }
      setIsLoading(false);
    };
    loadProfile();
  }, [token]);

  const login = useCallback(async (payload: CustomerLoginPayload) => {
    try {
      const response = await customerLogin(payload);
      if (response.success && response.token) {
        localStorage.setItem(AUTH_TOKEN_KEY, response.token);
        setToken(response.token);
        if (response.customer) {
          setCustomer(response.customer);
        }
        return { success: true };
      }
      return { success: false, message: response.message || 'Login failed' };
    } catch (error: any) {
      return { success: false, message: error.response?.data?.message || 'Login failed' };
    }
  }, []);

  const register = useCallback(async (payload: CustomerRegisterPayload) => {
    try {
      const response = await customerRegister(payload);
      if (response.success && response.token) {
        localStorage.setItem(AUTH_TOKEN_KEY, response.token);
        setToken(response.token);
        if (response.customer) {
          setCustomer(response.customer);
        }
        return { success: true };
      }
      return { success: false, message: response.message || 'Registration failed' };
    } catch (error: any) {
      const data = error.response?.data;
      // Extract specific validation errors from 422 responses
      if (data?.errors) {
        const firstError = Object.values(data.errors).flat()[0] as string;
        return { success: false, message: firstError || data.message || 'Registration failed' };
      }
      return { success: false, message: data?.message || 'Registration failed' };
    }
  }, []);

  const logout = useCallback(async () => {
    if (token) {
      try {
        await customerLogout(token);
      } catch {
        // Ignore logout errors
      }
    }
    localStorage.removeItem(AUTH_TOKEN_KEY);
    setToken(null);
    setCustomer(null);
  }, [token]);

  const refreshProfile = useCallback(async () => {
    if (token) {
      try {
        const profile = await getCustomerProfile(token);
        setCustomer(profile);
      } catch {
        // Token expired
        await logout();
      }
    }
  }, [token, logout]);

  return (
    <CustomerAuthContext.Provider
      value={{
        customer,
        token,
        isAuthenticated: !!customer,
        isLoading,
        login,
        register,
        logout,
        refreshProfile,
      }}
    >
      {children}
    </CustomerAuthContext.Provider>
  );
};

export const useCustomerAuth = () => {
  const context = useContext(CustomerAuthContext);
  if (context === undefined) {
    throw new Error('useCustomerAuth must be used within a CustomerAuthProvider');
  }
  return context;
};
