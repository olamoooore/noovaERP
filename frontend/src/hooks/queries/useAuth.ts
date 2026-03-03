import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/lib/api-client'

// Types
interface User {
  id: number
  name: string
  email: string
  roles?: any[]
}

interface AuthResponse {
  user: User
  token?: string
}

// Query keys
export const authKeys = {
  all: ['auth'] as const,
  user: () => [...authKeys.all, 'user'] as const,
  status: () => [...authKeys.all, 'status'] as const,
}

// API functions
const fetchUser = async (): Promise<User> => {
  const response = await fetch('/api/v1/user', {
    credentials: 'include',
  })
  
  if (!response.ok) {
    throw new Error('Failed to fetch user')
  }
  
  return response.json()
}

const logout = async (): Promise<void> => {
  await fetch('/api/v1/logout', {
    method: 'POST',
    credentials: 'include',
  })
}

// React Query hooks
export const useUser = () => {
  return useQuery({
    queryKey: authKeys.user(),
    queryFn: fetchUser,
    retry: false, // Don't retry auth failures
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
  })
}

export const useAuthStatus = () => {
  const { data: user, isLoading, error } = useUser()
  
  return {
    user,
    isAuthenticated: !!user && !error,
    isLoading,
    error,
  }
}

export const useLogout = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: logout,
    onSuccess: () => {
      // Clear all cached data
      queryClient.clear()
      // Clear localStorage
      localStorage.removeItem('user')
      // Redirect to login
      window.location.href = '/login'
    },
    onError: () => {
      // Even if logout fails, clear local data and redirect
      queryClient.clear()
      localStorage.removeItem('user')
      window.location.href = '/login'
    },
  })
}

export const useSetAuth = () => {
  const queryClient = useQueryClient()
  
  return (user: User, token?: string) => {
    // Update the user query cache
    queryClient.setQueryData(authKeys.user(), user)
    
    // Store user info in localStorage (non-sensitive)
    localStorage.setItem('user', JSON.stringify(user))
    
    // Token is handled via HttpOnly cookies, so we don't store it
  }
}