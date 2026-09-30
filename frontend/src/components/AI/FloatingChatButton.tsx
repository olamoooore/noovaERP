import { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ChatWidget } from './ChatWidget'
import { RobotIcon } from './RobotIcon'
import { useAuthStore } from '@/store/authStore'
import { useFeatureAccess } from '@/hooks/use-feature-access'

/**
 * Dashboard-wide floating AI assistant (robot button bottom-right).
 *
 * Rendered once in MainLayout so the assistant is reachable from every
 * page — not just the AI Chatbot page — whenever the tenant's AI module
 * is enabled. Shares the same "last conversation" memory as the AI page
 * so refreshing or switching pages never loses the chat.
 */
export function FloatingChatButton() {
  const [isOpen, setIsOpen] = useState(false)
  const [autoOpen, setAutoOpen] = useState(false)
  const [conversationId, setConversationId] = useState<number | null>(null)

  const location = useLocation()
  const queryClient = useQueryClient()
  const user = useAuthStore((state) => state.user)
  const { isFeatureEnabled } = useFeatureAccess()

  const aiEnabled = isFeatureEnabled('ai')

  // Resume the same conversation the AI page remembers.
  useEffect(() => {
    if (!user) return

    const stored = window.localStorage.getItem(`ai:last-conversation:${user.id}`)
    const storedId = stored ? Number(stored) : null

    if (storedId && Number.isFinite(storedId)) {
      setConversationId(storedId)
    }
  }, [user])

  const handleConversationChange = (id: number | null) => {
    setConversationId(id)

    if (user) {
      const key = `ai:last-conversation:${user.id}`
      if (id) {
        window.localStorage.setItem(key, String(id))
      } else {
        window.localStorage.removeItem(key)
      }
    }

    queryClient.invalidateQueries({ queryKey: ['ai-conversations'] })
  }

  // The AI Chatbot page embeds its own widget — avoid two chat panels.
  if (!user || !aiEnabled || location.pathname.startsWith('/ai/')) {
    return null
  }

  return (
    <>
      {!isOpen && (
        <button
          type="button"
          onClick={() => {
            setAutoOpen(true)
            setIsOpen(true)
          }}
          className="fixed bottom-6 right-6 z-[9999] flex h-11 w-11 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-110"
          aria-label="Open AI Assistant"
          title="Ask the AI Assistant"
        >
          <RobotIcon className="h-6 w-6" />
        </button>
      )}

      {isOpen && (
        <ChatWidget
          customerId={user.id}
          conversationId={conversationId}
          channel="web"
          autoOpen={autoOpen}
          onConversationChange={handleConversationChange}
          className="animate-in slide-in-from-bottom-5 duration-200"
        />
      )}
    </>
  )
}
