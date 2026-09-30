import { useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { CreateTicketModal } from './CreateTicketModal'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import { ModuleBadge } from './ModuleBadge'
import { MessageBody } from './MessageBody'
import { RobotIcon } from './RobotIcon'
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
  /** Message that intentionally re-shows the module menu (e.g. back to general). */
  isModuleMenu?: boolean
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
  initialModule?: string
  autoOpen?: boolean
  /** Notifies the host when the active conversation changes (created, loaded, cleared). */
  onConversationChange?: (conversationId: number | null) => void
}

export function ChatWidget({ customerId, conversationId: initialConversationId = null, channel = 'web', className = '', initialModule, autoOpen = false, onConversationChange }: ChatWidgetProps) {
  // Closed by default — opens via the floating bot button, when an explicit
  // action requests it (New Chat), or when a conversation is loaded.
  const [isOpen, setIsOpen] = useState<boolean>(!!initialConversationId || autoOpen)
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
      icon: 'solar:delivery-bold-duotone',
      label: 'Orders & Shipping',
      description: 'Track orders and check status',
    },
    {
      id: 'loyalty',
      icon: 'solar:gift-bold-duotone',
      label: 'Loyalty & Rewards',
      description: 'Check points and redeem rewards',
    },
    {
      id: 'products',
      icon: 'solar:bag-4-bold-duotone',
      label: 'Products & Catalog',
      description: 'Search products and check availability',
    },
    {
      id: 'inventory',
      icon: 'solar:layers-minimalistic-bold-duotone',
      label: 'Inventory',
      description: 'Check stock levels and low stock items',
    },
    {
      id: 'invoices',
      icon: 'solar:document-text-bold-duotone',
      label: 'Invoices & Payments',
      description: 'View invoices and payment status',
    },
    {
      id: 'account',
      icon: 'solar:user-circle-bold-duotone',
      label: 'My Account',
      description: 'View account details and settings',
    },
    {
      id: 'accounting_insights',
      icon: 'solar:chart-square-bold-duotone',
      label: 'Accounting Insights',
      description: 'AI-powered accounting analysis',
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

  // Opening a specific conversation always reveals the chat window.
  useEffect(() => {
    if (initialConversationId) setIsOpen(true)
  }, [initialConversationId])

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
              
              // Loaded conversations do not re-show the module menu — it is a
              // new-chat affordance only, otherwise a stale menu renders under
              // an unrelated historical reply.
              
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

          if (error.response?.status === 404) {
            // Conversation was deleted elsewhere — start fresh instead of
            // erroring. The host will forget the stored conversation id.
            setConversationId(null)
            onConversationChange?.(null)
            showInitialGreeting()
          } else {
            toast({
              title: 'Error',
              description: 'Failed to load conversation history',
              variant: 'destructive',
            })
          }
        } finally {
          setIsLoading(false)
        }
      } else {
        // New conversation - show greeting with quick options immediately
        showInitialGreeting()
        // If a module was requested via URL param, auto-select it after a short delay
        if (initialModule) {
          setTimeout(() => handleModuleSelect(initialModule), 400)
        }
      }
    }

    loadConversationHistory()
    // handleModuleSelect/showInitialGreeting are recreated every render;
    // adding them would re-seed the greeting on each state change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialConversationId, initialModule, toast])

  const showInitialGreeting = () => {
    // Show greeting message with quick options WITHOUT creating conversation yet
    const greetingMessage: Message = {
      id: Date.now() + Math.random(),
      role: 'assistant',
      content: "👋 Hi! I'm the NoovaERP AI Assistant. How can I help you today?",
      created_at: new Date().toISOString(),
      quick_options: getQuickOptionsForUi(),
    }
    setMessages([greetingMessage])
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
          onConversationChange?.(createResponse.data.conversation_id)
          
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
          onConversationChange?.(response.data.conversation_id)
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
        isModuleMenu: true,
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
      content: `Support ticket ${ticketNumber} has been created successfully. Our support team will contact you within 2 hours.`,
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
      const response = await api.post('/ai/chat', {
        customer_id: customerId,
        message: userMessage,
        channel,
        conversation_id: conversationId,
      })

      if (response.data.success) {
        // Update conversation ID
        const convId = response.data.conversation_id
        if (!conversationId) {
          setConversationId(convId)
          onConversationChange?.(convId)
        }

        // Check if response is being processed asynchronously
        if (response.data.processing) {
          // Show typing indicator and poll for result
          const thinkingId = Date.now() + Math.random()
          const thinkingMessage: Message = {
            id: thinkingId,
            role: 'assistant',
            content: 'Thinking…',
            created_at: new Date().toISOString(),
          }
          setMessages(prev => [...prev, thinkingMessage])
          
          // Poll for the response
          const pollForResponse = async () => {
            const maxAttempts = 60 // ~2 minutes
            for (let i = 0; i < maxAttempts; i++) {
              await new Promise(resolve => setTimeout(resolve, 2000))
              try {
                const pollResp = await api.get(`/ai/conversations/${convId}/poll`)
                if (pollResp.data.success && !pollResp.data.processing) {
                  // Replace thinking message with actual response
                  setMessages(prev => prev.map(m => 
                    m.id === thinkingId ? {
                      ...m,
                      content: pollResp.data.message,
                      common_questions: pollResp.data.common_questions,
                      quick_options: pollResp.data.quick_options,
                      show_buttons: pollResp.data.show_buttons,
                    } : m
                  ))
                  return
                }
              } catch {
                // Polling failed, keep trying
              }
            }
            // Timeout - update thinking message
            setMessages(prev => prev.map(m =>
              m.id === thinkingId ? {
                ...m,
                content: 'Sorry, the AI is taking too long to respond. Please try again or select one of the options below.',
              } : m
            ))
          }
          pollForResponse().finally(() => setIsLoading(false))
          return // Don't setIsLoading(false) yet - polling handles it
        }

        // Immediate response (greetings, button presses)
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
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        className={`fixed bottom-4 right-4 z-[99999] flex h-12 w-12 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg sm:bottom-6 sm:right-6 sm:h-14 sm:w-14 ${className}`}
        aria-label="Open AI Assistant"
      >
        <RobotIcon className="h-6 w-6" />
      </button>
    )
  }

  return (
    <Card className={`fixed bottom-0 left-0 right-0 h-[85vh] rounded-t-2xl sm:inset-auto sm:bottom-6 sm:right-6 sm:w-96 sm:h-[600px] sm:rounded-lg shadow-2xl flex flex-col z-[99999] ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b bg-primary text-primary-foreground rounded-t-2xl sm:rounded-t-lg">
        <div className="flex items-center gap-3 flex-1 min-w-0">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-foreground/15">
            <RobotIcon className="h-5 w-5" />
          </span>
          <div className="flex-1 min-w-0">
            <h3 className="font-semibold leading-tight">NoovaERP AI Assistant</h3>
            <p className="text-xs opacity-90 truncate">
              {conversationId ? `Conversation #${conversationId}` : 'New chat'}
            </p>
          </div>
          {activeModule && activeModule !== 'general' && (
            <ModuleBadge 
              module={activeModule} 
              onClose={handleBackToGeneral}
            />
          )}
        </div>
        <Button
          variant="ghost"
          size="icon"
          onClick={handleClose}
          title="Close"
          className="ml-2 h-9 w-9 shrink-0 rounded-full text-primary-foreground hover:bg-primary-foreground/20"
        >
          <Icon icon="mdi:close" className="h-5 w-5" />
        </Button>
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
            <RobotIcon className="mx-auto mb-2 h-12 w-12 opacity-50" />
            <p className="text-sm">Hi! I'm the NoovaERP AI Assistant. How can I help you today?</p>
            
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

        {messages.map((message) => {
          const firstAssistantId = messages.find((m) => m.role === 'assistant')?.id
          const showModuleMenu = message.id === firstAssistantId || message.isModuleMenu

          return (
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
                {message.role === 'assistant' ? (
                  <MessageBody content={message.content} className="text-sm break-words overflow-wrap-anywhere" />
                ) : (
                  <p className="text-sm whitespace-pre-wrap break-words overflow-wrap-anywhere">{message.content}</p>
                )}
                <p className="text-xs opacity-70 mt-1">
                  {new Date(message.created_at).toLocaleTimeString()}
                </p>
              </div>
            </div>
            
            {/* Render the module menu once (greeting / explicit menu message)
                instead of repeating it after every assistant reply. */}
            {message.role === 'assistant' && showModuleMenu && message.quick_options && message.quick_options.length > 0 && (
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
          )
        })}

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
              <div className="mb-3">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setShowTicketModal(true)}
                  className="w-full rounded-full"
                  disabled={isLoading}
                >
                  <Icon icon="mdi:lifebuoy" className="h-4 w-4 mr-2" />
                  Need human support? Create a ticket
                </Button>
              </div>
            )}
            
            <div className="flex items-center gap-2">
              <Input
                value={inputMessage}
                onChange={(e) => setInputMessage(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault()
                    sendMessage()
                  }
                }}
                placeholder="Type your message…"
                disabled={isLoading || conversationStatus === 'closed'}
                className="flex-1 rounded-full bg-muted/40 px-4"
              />
              <Button
                onClick={sendMessage}
                disabled={!inputMessage.trim() || isLoading || conversationStatus === 'closed'}
                size="icon"
                className="h-10 w-10 shrink-0 rounded-full"
              >
                <Icon icon="mdi:send" className="h-5 w-5" />
              </Button>
            </div>
            <p className="text-xs text-muted-foreground mt-2 text-center">
              Powered by NoovaERP • Press Enter to send
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
