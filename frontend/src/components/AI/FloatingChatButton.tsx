import { Button } from '@/components/ui/button'
import { ChatWidget } from './ChatWidget'
import { Icon } from '@iconify/react'
import { useState } from 'react'
import { useCustomerAuth } from '@/storefront/context/CustomerAuthContext'

export function FloatingChatButton() {
  const [isOpen, setIsOpen] = useState(false)
  const { customer, isAuthenticated } = useCustomerAuth()
  
  // Use customer ID from auth context, or default to 1 for guest users
  const customerId = customer?.id || 1

  return (
    <>
      {/* Floating Button - only show when chat is closed */}
      {!isOpen && (
        <Button
          onClick={() => setIsOpen(true)}
          className="fixed bottom-6 right-6 h-14 w-14 rounded-full shadow-lg hover:scale-110 transition-transform z-[9999]"
          size="icon"
        >
          <Icon icon="mdi:robot" className="h-7 w-7" />
        </Button>
      )}

      {/* Chat Window */}
      {isOpen && (
        <div className="z-50">
          <ChatWidget
            customerId={customerId}
            channel="web"
            onConversationEnd={() => setIsOpen(false)}
            className="animate-in slide-in-from-bottom-5 duration-200"
          />
        </div>
      )}
    </>
  )
}
