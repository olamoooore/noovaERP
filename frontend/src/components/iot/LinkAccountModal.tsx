/**
 * Link Account Modal Component
 * 
 * Modal for collecting UMP API Key to authenticate with the UMP Backend.
 * Sends the key to the local agent for validation.
 */

import { useState } from 'react'
import { Icon } from '@iconify/react'
import axios from 'axios'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import universalIoTService from '@/services/UniversalIoTService'

interface LinkAccountModalProps {
  isOpen: boolean
  onClose: () => void
  onSuccess: () => void
  agentUrl?: string
}

export function LinkAccountModal({ isOpen, onClose, onSuccess, agentUrl = 'http://localhost:3100' }: LinkAccountModalProps) {
  const [apiKey, setApiKey] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showKey, setShowKey] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    
    if (!apiKey.trim()) {
      setError('Please enter your UMP API Key')
      return
    }

    setIsLoading(true)
    setError(null)

    try {
      // Validate the API key using UMP's auth/validate endpoint
      const response = await axios.get(
        `${agentUrl}/api/v1/auth/validate`,
        {
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${apiKey.trim()}`,
          },
          timeout: 10000,
        }
      )

      if (response.data.success || response.status === 200) {
        // Save the API key
        localStorage.setItem('ump_api_key', apiKey.trim())
        universalIoTService.setApiKey(apiKey.trim())
        
        // Close modal and trigger refresh
        onSuccess()
        onClose()
        setApiKey('')
      } else {
        setError(response.data.message || 'Invalid API Key')
      }
    } catch (err: unknown) {
      const axiosError = err as { response?: { status?: number; data?: { message?: string } }; code?: string }
      
      // If we get a 401/403, the key is invalid
      if (axiosError.response?.status === 401 || axiosError.response?.status === 403) {
        setError('Invalid API Key. Please check and try again.')
      } else if (axiosError.code === 'ECONNREFUSED' || axiosError.code === 'ERR_NETWORK') {
        setError('Cannot connect to UMP. Make sure the service is running.')
      } else {
        // For other errors, still try to save the key (might be a different endpoint structure)
        // The user can test if it works by fetching devices
        localStorage.setItem('ump_api_key', apiKey.trim())
        universalIoTService.setApiKey(apiKey.trim())
        onSuccess()
        onClose()
        setApiKey('')
      }
    } finally {
      setIsLoading(false)
    }
  }

  const handleClose = () => {
    setApiKey('')
    setError(null)
    onClose()
  }

  return (
    <Dialog open={isOpen} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:key-bold-duotone" className="h-5 w-5 text-primary" />
            Link UMP Account
          </DialogTitle>
          <DialogDescription>
            Enter your UMP API Key to connect your account and access your devices.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Error Message */}
          {error && (
            <div className="flex items-center gap-2 p-3 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-lg text-red-700 dark:text-red-300 text-sm">
              <Icon icon="solar:danger-triangle-bold" className="h-4 w-4 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* API Key Input */}
          <div className="space-y-2">
            <Label htmlFor="ump-api-key">UMP API Key</Label>
            <div className="relative">
              <Input
                id="ump-api-key"
                type={showKey ? 'text' : 'password'}
                placeholder="Enter your API key..."
                value={apiKey}
                onChange={(e) => setApiKey(e.target.value)}
                disabled={isLoading}
                className="pr-10"
                autoComplete="off"
              />
              <button
                type="button"
                onClick={() => setShowKey(!showKey)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              >
                <Icon 
                  icon={showKey ? 'solar:eye-closed-linear' : 'solar:eye-linear'} 
                  className="h-4 w-4" 
                />
              </button>
            </div>
            <p className="text-xs text-muted-foreground">
              You can find your API key in the UMP Dashboard under Settings → API Keys
            </p>
          </div>

          {/* Info Box */}
          <div className="p-3 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <div className="flex items-start gap-2">
              <Icon icon="solar:info-circle-bold" className="h-4 w-4 text-blue-600 mt-0.5 flex-shrink-0" />
              <div className="text-xs text-blue-700 dark:text-blue-300">
                <p className="font-medium mb-1">Why do I need an API Key?</p>
                <p>The API key authenticates your NoovaERP account with the UMP service, allowing you to access and control your IoT devices securely.</p>
              </div>
            </div>
          </div>

          {/* Actions */}
          <div className="flex gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              onClick={handleClose}
              disabled={isLoading}
              className="flex-1"
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={isLoading || !apiKey.trim()}
              className="flex-1"
            >
              {isLoading ? (
                <>
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                  Validating...
                </>
              ) : (
                <>
                  <Icon icon="solar:link-bold" className="h-4 w-4 mr-2" />
                  Link Account
                </>
              )}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}

export default LinkAccountModal
