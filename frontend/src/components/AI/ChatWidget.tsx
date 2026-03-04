import { useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { CreateTicketModal } from './CreateTicketModal'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import { ModuleBadge } from './ModuleBadge'
import { QuickOptions } from './QuickOptions'
import { ScrollArea } from '@/components/ui/scroll-area'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

interface Message {
  id: number
  role: 'user' | 'assistant'
  content: string
  created_at: string
  quick_options?: QuickOption[]
  common_questions?: QuickOption[]
  show_buttons?: boolean
}

interface QuickOption {
  id: string
  icon: string
  label: string
  description: string
}

interface ChatWidgetProps {
  customerId: number
  conversationId?: number | null
  channel?: string
  className?: string
  onConversationEnd?: () => void
}

export function ChatWidget({ customerId, conversationId: initialConversationId = null, channel = 'web', className = '', onConversationEnd }: ChatWidgetProps) {
  const [isOpen, setIsOpen] = useState(true) // Auto-open for new chats
  const [messages, setMessages] = useState<Message[]>([])
  const [inputMessage, setInputMessage] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const [dynamicQuickOptions, setDynamicQuickOptions] = useState<QuickOption[] | null>(null)
  const [conversationId, setConversationId] = useState<number | null>(initialConversationId)
  const [conversationStatus, setConversationStatus] = useState<string>('active') // Track conversation status
  const [activeModule, setActiveModule] = useState<string | null>(null) // Track active module
  const [showTicketModal, setShowTicketModal] = useState(false) // Track ticket modal visibility
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const { toast } = useToast()

  const getFallbackQuickOptions = (): QuickOption[] => [
    {
      id: 'orders',
      icon: '📦',
      label: 'Orders & Shipping',
      description: 'Track orders and check status',
    },
    {
      id: 'loyalty',
      icon: '🎁',
      label: 'Loyalty & Rewards',
      description: 'Check points and redeem rewards',
    },
    {
      id: 'products',
      icon: '🛍️',
      label: 'Products & Catalog',
      description: 'Search products and check availability',
    },
    {
      id: 'inventory',
      icon: '📦',
      label: 'Inventory',
      description: 'Check stock levels and low stock items',
    },
    {
      id: 'invoices',
      icon: '💰',
      label: 'Invoices & Payments',
      description: 'View invoices and payment status',
    },
    {
      id: 'account',
      icon: '👤',
      label: 'My Account',
      description: 'View account details and settings',
    },
  ]

  const mergeQuickOptions = (primary: QuickOption[], fallback: QuickOption[]): QuickOption[] => {
    const seen = new Set<string>()
    const merged: QuickOption[] = []

    for (const option of primary) {
      if (!seen.has(option.id)) {
        seen.add(option.id)
        merged.push(option)
      }
    }

    for (const option of fallback) {
      if (!seen.has(option.id)) {
        seen.add(option.id)
        merged.push(option)
      }
    }

    return merged
  }

  const getQuickOptionsForUi = (): QuickOption[] => {
    const fallback = getFallbackQuickOptions()
    if (!dynamicQuickOptions || dynamicQuickOptions.length === 0) return fallback
    return mergeQuickOptions(dynamicQuickOptions, fallback)
  }

  // Fetch dynamic module list (kept as additive on top of fallback)
  useEffect(() => {
    let cancelled = false

    const loadModules = async () => {
      try {
        const response = await api.get('/ai/modules')

        if (!response.data?.success) return
        const rawModules = response.data?.data
        if (!Array.isArray(rawModules)) return

        const mapped: QuickOption[] = rawModules
          .filter((m: any) => m && typeof m.name === 'string' && m.name !== 'general')
          .map((m: any) => ({
            id: m.name,
            icon: m.icon || '❓',
            label: m.display_name || m.name,
            description: m.description || '',
          }))

        if (!cancelled) {
          setDynamicQuickOptions(mapped)
        }
      } catch (e) {
        // Non-fatal: fallback quick options will be used
      }
    }

    loadModules()
    return () => {
      cancelled = true
    }
  }, [])

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }

  useEffect(() => {
    scrollToBottom()
  }, [messages])

  // Load conversation history if conversationId is provided, or show greeting for new chat
  useEffect(() => {
    const loadConversationHistory = async () => {
      if (initialConversationId) {
        setIsLoading(true)
        try {
          console.log('Loading conversation history for ID:', initialConversationId)
          const response = await api.get(`/ai/conversations/${initialConversationId}`)
          
          console.log('Conversation API response:', response.data)
          
          if (response.data.success && response.data.conversation) {
            const conversation = response.data.conversation
            
            // Set conversation status and active module
            setConversationStatus(conversation.status || 'active')
            setActiveModule(conversation.active_module || null)
            
            if (conversation.messages) {
              const loadedMessages: Message[] = conversation.messages.map((msg: any) => ({
                id: msg.id,
                role: msg.role,
                content: msg.content,
                created_at: msg.created_at,
              }))
              
              // Add quick_options to the last assistant message if available
              if (response.data.quick_options && loadedMessages.length > 0) {
                const lastMessage = loadedMessages[loadedMessages.length - 1]
                if (lastMessage.role === 'assistant') {
                  lastMessage.quick_options = response.data.quick_options
                }
              }
              
              console.log('Loaded messages:', loadedMessages)
              setMessages(loadedMessages)
              setConversationId(initialConversationId)
            }
          } else {
            console.log('No messages found or API returned error')
          }
        } catch (error: any) {
          console.error('Failed to load conversation history:', error)
          console.error('Error response:', error.response)
          toast({
            title: 'Error',
            description: 'Failed to load conversation history',
            variant: 'destructive',
          })
        } finally {
          setIsLoading(false)
        }
      } else {
        // New conversation - show greeting with quick options immediately
        showInitialGreeting()
      }
    }

    loadConversationHistory()
  }, [initialConversationId, toast])

  const showInitialGreeting = () => {
    // Show greeting message with quick options WITHOUT creating conversation yet
    const greetingMessage: Message = {
      id: Date.now() + Math.random(),
      role: 'assistant',
      content: '👋 Hi! How can I help you today?',
      created_at: new Date().toISOString(),
      quick_options: getQuickOptionsForUi(),
    }
    setMessages([greetingMessage])
  }

  const handleEndConversation = async () => {
    if (!conversationId) return

    try {
      await api.post(`/ai/conversations/${conversationId}/close`)
      
      // Update status to closed
      setConversationStatus('closed')
      
      toast({
        title: 'Conversation Ended',
        description: 'This conversation has been closed. Click "New Chat" to start a new one.',
      })
      
      // Notify parent to refresh conversation list
      if (onConversationEnd) {
        onConversationEnd()
      }
      
      // Close the widget
      setIsOpen(false)
    } catch (error: any) {
      console.error('Failed to end conversation:', error)
      toast({
        title: 'Error',
        description: 'Failed to end conversation',
        variant: 'destructive',
      })
    }
  }

  const handleClose = () => {
    // Just close the widget, don't end the conversation
    setIsOpen(false)
  }

  const handleModuleSelect = async (moduleId: string) => {
    setIsLoading(true)
    try {
      // If no conversation yet, create one first by sending a greeting
      if (!conversationId) {
        console.log('Creating conversation first...')
        console.log('Customer ID being used:', customerId)
        
        const createResponse = await api.post('/ai/chat', {
          customer_id: customerId,
          message: 'hi',
          channel,
        })
        
        if (createResponse.data.success) {
          setConversationId(createResponse.data.conversation_id)
          
          // Now select the module
          console.log('Selecting module:', moduleId)
          const response = await api.post(`/ai/conversations/${createResponse.data.conversation_id}/select-module`, {
            module: moduleId,
          })

          if (response.data.success) {
            setActiveModule(moduleId)
            
            const confirmationMessage: Message = {
              id: Date.now() + Math.random(), // Ensure unique ID
              role: 'assistant',
              content: response.data.message,
              created_at: new Date().toISOString(),
              common_questions: response.data.common_questions,
              show_buttons: true,
            }
            setMessages(prev => [...prev, confirmationMessage])
          }
        }
      } else {
        // Conversation exists, just select module
        console.log('Selecting module:', moduleId)
        const response = await api.post(`/ai/conversations/${conversationId}/select-module`, {
          module: moduleId,
        })

        console.log('Module selection response:', response.data)

        if (response.data.success) {
          setActiveModule(moduleId)
          
          const confirmationMessage: Message = {
            id: Date.now() + Math.random(), // Ensure unique ID
            role: 'assistant',
            content: response.data.message,
            created_at: new Date().toISOString(),
            common_questions: response.data.common_questions,
            show_buttons: true,
          }
          setMessages(prev => [...prev, confirmationMessage])
        }
      }
    } catch (error: any) {
      console.error('Module selection error:', error)
      console.error('Error response:', error.response?.data)
      
      let errorMessage = 'Failed to select module'
      if (error.response?.data?.errors) {
        // Laravel validation errors
        const errors = error.response.data.errors
        errorMessage = Object.values(errors).flat().join(', ')
      } else if (error.response?.data?.error) {
        errorMessage = error.response.data.error
      } else if (error.response?.data?.message) {
        errorMessage = error.response.data.message
      }
      
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleCommonQuestionSelect = async (questionId: string) => {
    setIsLoading(true)
    try {
      console.log('Handling common question:', questionId)
      
      // Send button press to backend
      const response = await api.post('/ai/chat', {
        customer_id: customerId,
        message: questionId,
        channel,
        conversation_id: conversationId,
        is_button_press: true,
      })

      console.log('Common question response:', response.data)

      if (response.data.success) {
        // Update conversation ID if needed
        if (!conversationId) {
          setConversationId(response.data.conversation_id)
        }

        // Add AI response
        const aiMessage: Message = {
          id: Date.now() + Math.random(),
          role: 'assistant',
          content: response.data.message,
          created_at: response.data.timestamp,
          common_questions: response.data.common_questions,
          show_buttons: response.data.show_buttons || response.data.fallback_mode,
        }
        setMessages(prev => [...prev, aiMessage])
      }
    } catch (error: any) {
      console.error('Common question error:', error)
      toast({
        title: 'Error',
        description: 'Failed to process your request',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleBackToGeneral = async () => {
    if (!conversationId) return
    
    setIsLoading(true)
    try {
      await handleModuleSelect('general')
      
      // Show quick options again when returning to general
      const optionsMessage: Message = {
        id: Date.now() + Math.random(),
        role: 'assistant',
        content: '👋 What would you like help with?',
        created_at: new Date().toISOString(),
        quick_options: getQuickOptionsForUi(),
      }
      setMessages(prev => [...prev, optionsMessage])
    } finally {
      setIsLoading(false)
    }
  }

  const handleTicketCreated = (ticketNumber: string) => {
    // Add confirmation message to chat
    const confirmationMessage: Message = {
      id: Date.now() + Math.random(),
      role: 'assistant',
      content: `✅ Support ticket ${ticketNumber} has been created successfully. Our support team will contact you within 2 hours.`,
      created_at: new Date().toISOString(),
    }
    setMessages(prev => [...prev, confirmationMessage])
  }

  const sendMessage = async () => {
    if (!inputMessage.trim() || isLoading || conversationStatus === 'closed') return

    const userMessage = inputMessage.trim()
    setInputMessage('')
    
    // Add user message to UI immediately
    const tempUserMessage: Message = {
      id: Date.now() + Math.random(),
      role: 'user',
      content: userMessage,
      created_at: new Date().toISOString(),
    }
    setMessages(prev => [...prev, tempUserMessage])
    setIsLoading(true)

    try {
      console.log('Sending message to AI:', {
        customer_id: customerId,
        message: userMessage,
        channel,
        conversation_id: conversationId,
      })
      
      const response = await api.post('/ai/chat', {
        customer_id: customerId,
        message: userMessage,
        channel,
        conversation_id: conversationId,
      })

      console.log('AI response:', response.data)

      if (response.data.success) {
        // Update conversation ID
        if (!conversationId) {
          setConversationId(response.data.conversation_id)
        }

        // Add AI response
        const aiMessage: Message = {
          id: Date.now() + Math.random(),
          role: 'assistant',
          content: response.data.message,
          created_at: response.data.timestamp,
          quick_options: response.data.quick_options,
          common_questions: response.data.common_questions,
          show_buttons: response.data.show_buttons || response.data.fallback_mode,
        }
        setMessages(prev => [...prev, aiMessage])
      } else {
        throw new Error(response.data.error || 'Failed to send message')
      }
    } catch (error: any) {
      console.error('Chat error:', error)
      console.error('Error response:', error.response)
      
      let errorMessage = 'Failed to send message'
      
      if (error.response?.status === 401) {
        errorMessage = 'Please log in to use the chat'
      } else if (error.response?.status === 403) {
        errorMessage = 'AI feature is not enabled'
      } else if (error.response?.data?.error) {
        errorMessage = error.response.data.error
      } else if (error.response?.data?.message) {
        errorMessage = error.response.data.message
      }
      
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      })
      
      // Remove the temporary user message on error
      setMessages(prev => prev.filter(m => m.id !== tempUserMessage.id))
    } finally {
      setIsLoading(false)
    }
  }

  const quickActions = [
    { label: 'Check Points', message: 'What is my loyalty points balance?' },
    { label: 'View Rewards', message: 'What rewards can I redeem?' },
    { label: 'Order Status', message: 'Check my recent orders' },
  ]

  if (!isOpen) {
    return (
      <Button
        onClick={() => setIsOpen(true)}
        className={`fixed bottom-4 right-4 sm:bottom-6 sm:right-6 h-12 w-12 sm:h-14 sm:w-14 rounded-full shadow-lg z-[99999] ${className}`}
        size="icon"
      >
        <Icon icon="mdi:robot" className="h-6 w-6" />
      </Button>
    )
  }

  return (
    <Card className={`fixed bottom-0 left-0 right-0 h-[85vh] rounded-t-2xl sm:inset-auto sm:bottom-6 sm:right-6 sm:w-96 sm:h-[600px] sm:rounded-lg shadow-2xl flex flex-col z-[99999] ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b bg-primary text-primary-foreground rounded-t-lg">
        <div className="flex items-center gap-2 flex-1 min-w-0">
          <Icon icon="mdi:robot" className="h-5 w-5 flex-shrink-0" />
          <div className="flex-1 min-w-0">
            <h3 className="font-semibold">AI Assistant</h3>
            <p className="text-xs opacity-90 truncate">
              {conversationId ? `Conversation #${conversationId}` : 'New Chat'}
            </p>
          </div>
          {activeModule && activeModule !== 'general' && (
            <ModuleBadge 
              module={activeModule} 
              onClose={handleBackToGeneral}
            />
          )}
        </div>
        <div className="flex gap-1 flex-shrink-0">
          {conversationId && (
            <Button
              variant="ghost"
              size="sm"
              onClick={handleEndConversation}
              className="text-primary-foreground hover:bg-primary-foreground/20 text-xs"
              title="End this conversation"
            >
              <Icon icon="mdi:stop-circle" className="h-4 w-4 mr-1" />
              End
            </Button>
          )}
          <Button
            variant="ghost"
            size="icon"
            onClick={handleClose}
            className="text-primary-foreground hover:bg-primary-foreground/20"
          >
            <Icon icon="mdi:close" className="h-5 w-5" />
          </Button>
        </div>
      </div>

      {/* Messages */}
      <ScrollArea className="flex-1 p-4">
        {isLoading && messages.length === 0 && (
          <div className="text-center text-muted-foreground py-8">
            <Icon icon="mdi:loading" className="h-12 w-12 mx-auto mb-2 opacity-50 animate-spin" />
            <p className="text-sm">Loading conversation history...</p>
          </div>
        )}
        
        {!isLoading && messages.length === 0 && (
          <div className="text-center text-muted-foreground py-8">
            <Icon icon="mdi:robot-happy" className="h-12 w-12 mx-auto mb-2 opacity-50" />
            <p className="text-sm">Hi! How can I help you today?</p>
            
            {/* Quick Actions */}
            <div className="mt-4 space-y-2">
              {quickActions.map((action, index) => (
                <Button
                  key={index}
                  variant="outline"
                  size="sm"
                  className="w-full"
                  onClick={() => {
                    setInputMessage(action.message)
                  }}
                >
                  {action.label}
                </Button>
              ))}
            </div>
          </div>
        )}

        {messages.map((message) => (
          <div key={message.id}>
            <div
              className={`mb-4 flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}
            >
              <div
                className={`max-w-[80%] rounded-lg px-4 py-2 ${
                  message.role === 'user'
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted'
                }`}
              >
                <p className="text-sm whitespace-pre-wrap break-words overflow-wrap-anywhere">{message.content}</p>
                <p className="text-xs opacity-70 mt-1">
                  {new Date(message.created_at).toLocaleTimeString()}
                </p>
              </div>
            </div>
            
            {/* Render quick options if present */}
            {message.role === 'assistant' && message.quick_options && message.quick_options.length > 0 && (
              <div className="mb-4">
                <QuickOptions 
                  options={message.quick_options} 
                  onSelect={handleModuleSelect}
                />
              </div>
            )}
            
            {/* Render common questions if present */}
            {message.role === 'assistant' && message.common_questions && message.common_questions.length > 0 && (
              <div className="mb-4">
                <QuickOptions 
                  options={message.common_questions} 
                  onSelect={handleCommonQuestionSelect}
                />
              </div>
            )}
          </div>
        ))}

        {isLoading && (
          <div className="flex justify-start mb-4">
            <div className="bg-muted rounded-lg px-4 py-2">
              <div className="flex gap-1">
                <div className="w-2 h-2 bg-muted-foreground rounded-full animate-bounce" />
                <div className="w-2 h-2 bg-muted-foreground rounded-full animate-bounce delay-100" />
                <div className="w-2 h-2 bg-muted-foreground rounded-full animate-bounce delay-200" />
              </div>
            </div>
          </div>
        )}

        <div ref={messagesEndRef} />
      </ScrollArea>

      {/* Input */}
      <div className="p-4 border-t">
        {conversationStatus === 'closed' ? (
          <div className="text-center py-4">
            <p className="text-sm text-muted-foreground mb-2">
              This conversation has ended
            </p>
            <p className="text-xs text-muted-foreground">
              Click "New Chat" to start a new conversation
            </p>
          </div>
        ) : (
          <>
            {/* Create Ticket Button */}
            {conversationId && (
              <div className="mb-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setShowTicketModal(true)}
                  className="w-full"
                  disabled={isLoading}
                >
                  <Icon icon="mdi:ticket" className="h-4 w-4 mr-2" />
                  Need Human Support? Create Ticket
                </Button>
              </div>
            )}
            
            <div className="flex gap-2">
              <Input
                value={inputMessage}
                onChange={(e) => setInputMessage(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault()
                    sendMessage()
                  }
                }}
                placeholder="Type your message..."
                disabled={isLoading || conversationStatus === 'closed'}
                className="flex-1"
              />
              <Button
                onClick={sendMessage}
                disabled={!inputMessage.trim() || isLoading || conversationStatus === 'closed'}
                size="icon"
              >
                <Icon icon="mdi:send" className="h-5 w-5" />
              </Button>
            </div>
            <p className="text-xs text-muted-foreground mt-2">
              Powered by AI • Press Enter to send
            </p>
          </>
        )}
      </div>

      {/* Create Ticket Modal */}
      {conversationId && (
        <CreateTicketModal
          open={showTicketModal}
          onClose={() => setShowTicketModal(false)}
          conversationId={conversationId}
          customerId={customerId}
          onTicketCreated={handleTicketCreated}
        />
      )}
    </Card>
  )
}
