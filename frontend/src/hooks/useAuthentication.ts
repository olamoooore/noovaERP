import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/lib/api-client'
import { useAuthStore } from '@/store/authStore'
import { toast } from '@/hooks/use-toast'

// Types for authentication
export interface User {
  id: number
  email: string
  name: string
  is_active: boolean
  roles: Role[]
  permissions: Permission[]
}

export interface Role {
  id: number
  name: string
  permissions: Permission[]
}

export interface Permission {
  id: number
  name: string
}

export interface LoginCredentials {
  email: string
  password: string
}

export interface AuthResponse {
  user: User
  token: string
}

/**
 * Consolidated Authentication Hook for EventManagement Module
 * 
 * This hook isolates all authentication-related logic for the frontend,
 * providing a clear boundary around auth operations while preserving existing behavior.
 * 
 * Requirements: 6.1 - Isolate authentication flows in EventManagement
 */
export function useAuthentication() {
  const queryClient = useQueryClient()
  const { setLoginModalOpen, setLoggingOut } = useAuthStore()

  // Query for current user
  const {
    data: user,
    isLoading: isLoadingUser,
    error: userError,
    refetch: refetchUser
  } = useQuery({
    queryKey: ['auth', 'user'],
    queryFn: async () => {
      const response = await apiClient.get<User>('/auth/me')
      return response.data
    },
    enabled: !!apiClient.getAuthToken(),
    retry: false,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })

  // Login mutation
  const loginMutation = useMutation({
    mutationFn: async (credentials: LoginCredentials) => {
      const response = await apiClient.post<AuthResponse>('/auth/login', credentials)
      return response.data
    },
    onSuccess: (data) => {
      // Store token
      apiClient.setAuthToken(data.token)
      
      // Update user data in cache
      queryClient.setQueryData(['auth', 'user'], data.user)
      
      // Close login modal
      setLoginModalOpen(false)
      
      toast({
        title: "Welcome back!",
        description: `Logged in as ${data.user.name}`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Login failed",
        description: error.userMessage || "Invalid credentials",
        variant: "destructive",
      })
    }
  })

  // Logout mutation
  const logoutMutation = useMutation({
    mutationFn: async () => {
      await apiClient.post('/auth/logout')
    },
    onMutate: () => {
      setLoggingOut(true)
    },
    onSuccess: () => {
      // Clear auth data
      apiClient.clearAuth()
      
      // Clear user data from cache
      queryClient.setQueryData(['auth', 'user'], null)
      queryClient.removeQueries({ queryKey: ['auth'] })
      
      toast({
        title: "Logged out",
        description: "You have been successfully logged out",
      })
    },
    onError: (error: any) => {
      toast({
        title: "Logout failed",
        description: error.userMessage || "Failed to logout",
        variant: "destructive",
      })
    },
    onSettled: () => {
      setLoggingOut(false)
    }
  })

  // Authentication status queries
  const isAuthenticated = !!user && !!apiClient.getAuthToken()
  const isLoading = isLoadingUser || loginMutation.isPending || logoutMutation.isPending

  // Permission and role checking functions
  const hasPermission = (permission: string): boolean => {
    if (!user) return false
    return user.permissions.some(p => p.name === permission)
  }

  const hasRole = (role: string): boolean => {
    if (!user) return false
    return user.roles.some(r => r.name === role)
  }

  const hasAnyRole = (roles: string[]): boolean => {
    if (!user) return false
    return user.roles.some(r => roles.includes(r.name))
  }

  const hasAnyPermission = (permissions: string[]): boolean => {
    if (!user) return false
    return user.permissions.some(p => permissions.includes(p.name))
  }

  // EventManagement-specific permission checks
  const canAccessEventManagement = (): boolean => {
    if (!user) return false
    
    const eventPermissions = [
      'events.view',
      'events.create',
      'events.update',
      'events.delete',
      'bookings.view',
      'bookings.create',
      'bookings.manage',
      'tickets.view',
      'tickets.validate',
      'operations.view',
      'operations.checkin',
    ]

    return hasAnyPermission(eventPermissions) || hasAnyRole(['staff', 'admin', 'super-admin'])
  }

  const isStaff = (): boolean => {
    return hasAnyRole(['staff', 'admin', 'super-admin'])
  }

  const isAdmin = (): boolean => {
    return hasAnyRole(['admin', 'super-admin'])
  }

  const canConfigureEvents = (): boolean => {
    return hasPermission('events.configure') || hasAnyRole(['admin', 'super-admin'])
  }

  const canViewInventory = (): boolean => {
    return hasPermission('inventory.view') || isStaff()
  }

  const canManageStaff = (): boolean => {
    return hasPermission('staff.manage') || hasAnyRole(['admin', 'manager'])
  }

  const canViewAnalytics = (): boolean => {
    return hasPermission('analytics.view') || isAdmin()
  }

  // User role and permission getters
  const getUserRoles = (): string[] => {
    return user?.roles.map(r => r.name) || []
  }

  const getUserPermissions = (): string[] => {
    return user?.permissions.map(p => p.name) || []
  }

  // Login/logout actions
  const login = (credentials: LoginCredentials) => {
    loginMutation.mutate(credentials)
  }

  const logout = () => {
    logoutMutation.mutate()
  }

  const openLoginModal = () => {
    setLoginModalOpen(true)
  }

  const closeLoginModal = () => {
    setLoginModalOpen(false)
  }

  return {
    // User data
    user,
    isAuthenticated,
    isLoading,
    error: userError,
    
    // Actions
    login,
    logout,
    refetchUser,
    openLoginModal,
    closeLoginModal,
    
    // Permission checks
    hasPermission,
    hasRole,
    hasAnyRole,
    hasAnyPermission,
    
    // EventManagement-specific checks
    canAccessEventManagement,
    isStaff,
    isAdmin,
    canConfigureEvents,
    canViewInventory,
    canManageStaff,
    canViewAnalytics,
    
    // User data getters
    getUserRoles,
    getUserPermissions,
    
    // Loading states
    isLoggingIn: loginMutation.isPending,
    isLoggingOut: logoutMutation.isPending,
  }
}

// Convenience hooks for specific use cases
export const useCurrentUser = () => {
  const { user, isLoading, error } = useAuthentication()
  return { user, isLoading, error }
}

export const useAuthStatus = () => {
  const { isAuthenticated, isLoading } = useAuthentication()
  return { isAuthenticated, isLoading }
}

export const usePermissions = () => {
  const { 
    hasPermission, 
    hasRole, 
    hasAnyRole, 
    hasAnyPermission,
    getUserRoles,
    getUserPermissions
  } = useAuthentication()
  
  return {
    hasPermission,
    hasRole,
    hasAnyRole,
    hasAnyPermission,
    getUserRoles,
    getUserPermissions
  }
}

export const useEventManagementAuth = () => {
  const {
    canAccessEventManagement,
    isStaff,
    isAdmin,
    canConfigureEvents,
    canViewInventory,
    canManageStaff,
    canViewAnalytics
  } = useAuthentication()
  
  return {
    canAccessEventManagement,
    isStaff,
    isAdmin,
    canConfigureEvents,
    canViewInventory,
    canManageStaff,
    canViewAnalytics
  }
}