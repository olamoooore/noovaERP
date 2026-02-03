import { useEffect, useRef, useState, useCallback } from 'react'
import { useToast } from './use-toast'

interface LocalAgentMessage {
  type: string
  data: any
  requestId?: string
}

interface UseLocalAgentOptions {
  url?: string
  autoConnect?: boolean
  onConnect?: () => void
  onDisconnect?: () => void
  onMessage?: (message: LocalAgentMessage) => void
}

export function useLocalAgent(options: UseLocalAgentOptions = {}) {
  const {
    url = 'ws://localhost:3001',
    autoConnect = false,
    onConnect,
    onDisconnect,
    onMessage,
  } = options

  const [isConnected, setIsConnected] = useState(false)
  const [isConnecting, setIsConnecting] = useState(false)
  const wsRef = useRef<WebSocket | null>(null)
  const reconnectTimeoutRef = useRef<NodeJS.Timeout>()
  const pendingRequestsRef = useRef<Map<string, (data: any) => void>>(new Map())
  const { toast } = useToast()

  const connect = useCallback(() => {
    if (wsRef.current?.readyState === WebSocket.OPEN) {
      return
    }

    setIsConnecting(true)

    try {
      const ws = new WebSocket(url)

      ws.onopen = () => {
        setIsConnected(true)
        setIsConnecting(false)
        onConnect?.()
        console.log('[LocalAgent] Connected to local agent')
      }

      ws.onclose = () => {
        setIsConnected(false)
        setIsConnecting(false)
        onDisconnect?.()
        console.log('[LocalAgent] Disconnected from local agent')

        // Auto-reconnect after 5 seconds
        reconnectTimeoutRef.current = setTimeout(() => {
          console.log('[LocalAgent] Attempting to reconnect...')
          connect()
        }, 5000)
      }

      ws.onerror = (error) => {
        console.error('[LocalAgent] WebSocket error:', error)
        setIsConnecting(false)
      }

      ws.onmessage = (event) => {
        try {
          const message: LocalAgentMessage = JSON.parse(event.data)
          console.log('[LocalAgent] Received message:', message)

          // Handle response to pending request
          if (message.requestId && pendingRequestsRef.current.has(message.requestId)) {
            const resolver = pendingRequestsRef.current.get(message.requestId)
            resolver?.(message.data)
            pendingRequestsRef.current.delete(message.requestId)
          }

          onMessage?.(message)
        } catch (error) {
          console.error('[LocalAgent] Failed to parse message:', error)
        }
      }

      wsRef.current = ws
    } catch (error) {
      console.error('[LocalAgent] Failed to connect:', error)
      setIsConnecting(false)
      // Don't show toast on connection failure - let components handle it when needed
    }
  }, [url, onConnect, onDisconnect, onMessage])

  const disconnect = useCallback(() => {
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current)
    }

    if (wsRef.current) {
      wsRef.current.close()
      wsRef.current = null
    }

    setIsConnected(false)
  }, [])

  const sendMessage = useCallback((type: string, data: any = {}): Promise<any> => {
    return new Promise((resolve, reject) => {
      if (!wsRef.current || wsRef.current.readyState !== WebSocket.OPEN) {
        reject(new Error('Not connected to local agent'))
        return
      }

      const requestId = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`
      const message: LocalAgentMessage = { type, data, requestId }

      // Store resolver for this request
      pendingRequestsRef.current.set(requestId, resolve)

      // Send message
      wsRef.current.send(JSON.stringify(message))

      // Timeout after 30 seconds
      setTimeout(() => {
        if (pendingRequestsRef.current.has(requestId)) {
          pendingRequestsRef.current.delete(requestId)
          reject(new Error('Request timeout'))
        }
      }, 30000)
    })
  }, [])

  // Payment terminal commands
  const processPayment = useCallback(async (amount: number, currency: string = 'USD') => {
    try {
      const result = await sendMessage('payment.process', { amount, currency })
      return result
    } catch (error) {
      console.error('[LocalAgent] Payment failed:', error)
      throw error
    }
  }, [sendMessage])

  const cancelPayment = useCallback(async () => {
    try {
      const result = await sendMessage('payment.cancel', {})
      return result
    } catch (error) {
      console.error('[LocalAgent] Cancel payment failed:', error)
      throw error
    }
  }, [sendMessage])

  // Printer commands
  const printReceipt = useCallback(async (receiptData: any) => {
    try {
      const result = await sendMessage('printer.print', receiptData)
      return result
    } catch (error) {
      console.error('[LocalAgent] Print failed:', error)
      throw error
    }
  }, [sendMessage])

  // Scanner commands
  const startScanning = useCallback(async () => {
    try {
      const result = await sendMessage('scanner.start', {})
      return result
    } catch (error) {
      console.error('[LocalAgent] Start scanning failed:', error)
      throw error
    }
  }, [sendMessage])

  const stopScanning = useCallback(async () => {
    try {
      const result = await sendMessage('scanner.stop', {})
      return result
    } catch (error) {
      console.error('[LocalAgent] Stop scanning failed:', error)
      throw error
    }
  }, [sendMessage])

  // Device management
  const getDevices = useCallback(async () => {
    try {
      const result = await sendMessage('devices.list', {})
      return result
    } catch (error) {
      console.error('[LocalAgent] Get devices failed:', error)
      throw error
    }
  }, [sendMessage])

  useEffect(() => {
    if (autoConnect) {
      connect()
    }

    return () => {
      disconnect()
    }
  }, [autoConnect, connect, disconnect])

  return {
    isConnected,
    isConnecting,
    connect,
    disconnect,
    sendMessage,
    // Device-specific commands
    processPayment,
    cancelPayment,
    printReceipt,
    startScanning,
    stopScanning,
    getDevices,
  }
}
