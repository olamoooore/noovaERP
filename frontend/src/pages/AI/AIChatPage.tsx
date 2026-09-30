import { useState, useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { StatCard } from '@/components/ui/stat-card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import api from '@/lib/api'
import { ChatWidget } from '@/components/AI/ChatWidget'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'

interface Conversation {
  id: number
  channel: string
  status: string
  language: string
  started_at: string
  last_message: string
  message_count: number
}

export function AIChatPage() {
  const [searchParams] = useSearchParams()
  const initialModule = searchParams.get('module') || undefined

  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [chatKey, setChatKey] = useState(0) // Force new chat widget
  const [chatAutoOpen, setChatAutoOpen] = useState(false) // Open the chat panel only on explicit actions
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [conversationToDelete, setConversationToDelete] = useState<number | null>(null)
  const { toast } = useToast()
  const user = useAuthStore((state) => state.user)
  
  // Use current user's ID as customer_id
  const currentCustomerId = user?.id || 1

  // Restore the last conversation on mount (and when module param changes)
  // so refreshing or coming back never loses the chat; only once user is loaded.
  useEffect(() => {
    if (!user) return

    const stored = window.localStorage.getItem(`ai:last-conversation:${user.id}`)
    const storedId = stored ? Number(stored) : null
    const restoreId = storedId && Number.isFinite(storedId) ? storedId : null

    setSelectedCustomerId(currentCustomerId)
    setSelectedConversationId(restoreId)
    setChatAutoOpen(Boolean(restoreId))
    setChatKey(prev => prev + 1)
  }, [user, initialModule, currentCustomerId])

  // Remember (or forget) the active conversation per user and refresh the list.
  const rememberConversation = (conversationId: number | null) => {
    if (user) {
      const key = `ai:last-conversation:${user.id}`
      if (conversationId) {
        window.localStorage.setItem(key, String(conversationId))
      } else {
        window.localStorage.removeItem(key)
      }
    }
    refetch()
  }

  // Get AI service status with error handling
  const { data: aiStatus, isError: aiStatusError, error: aiError, isLoading: aiStatusLoading } = useQuery({
    queryKey: ['ai-status'],
    queryFn: async () => {
      const response = await api.get('/ai/info')
      return response.data
    },
    refetchInterval: 15000, // Refresh every 15 seconds
    retry: 1,
  })

  // Tenant-wide metrics (authoritative counts, computed server-side)
  const { data: aiStats } = useQuery({
    queryKey: ['ai-stats'],
    queryFn: async () => {
      const response = await api.get('/ai/stats')
      return response.data.stats as {
        total_conversations: number
        active_conversations: number
        messages_today: number
      }
    },
    refetchInterval: 30000,
  })

  // Recent conversations across the whole workspace (staff view)
  const { data: conversations, isLoading, refetch } = useQuery({
    queryKey: ['ai-conversations'],
    queryFn: async () => {
      const response = await api.get('/ai/conversations', {
        params: { limit: 25 },
      })
      return response.data.conversations as Conversation[]
    },
  })

  const handleNewChat = () => {
    // Forget the remembered conversation — this is an explicit fresh start.
    if (user) {
      window.localStorage.removeItem(`ai:last-conversation:${user.id}`)
    }
    // Force create a new chat by incrementing the key
    setChatAutoOpen(true)
    setChatKey(prev => prev + 1)
    setSelectedCustomerId(currentCustomerId)
    setSelectedConversationId(null) // New conversation, no history
    // Refetch conversations after a short delay to show the new one
    setTimeout(() => refetch(), 1000)
  }

  const handleOpenConversation = (conversationId: number, e: React.MouseEvent) => {
    e.stopPropagation()
    rememberConversation(conversationId)
    setChatAutoOpen(true)
    setChatKey(prev => prev + 1) // Force remount to load new conversation
    setSelectedCustomerId(currentCustomerId)
    setSelectedConversationId(conversationId) // Load this conversation's history
  }

  const handleDeleteClick = (conversationId: number, e: React.MouseEvent) => {
    e.stopPropagation()
    setConversationToDelete(conversationId)
    setDeleteDialogOpen(true)
  }

  const handleDeleteConfirm = async () => {
    if (!conversationToDelete) return

    try {
      await api.delete(`/ai/conversations/${conversationToDelete}`)
      
      toast({
        title: 'Success',
        description: 'Conversation deleted successfully',
      })
      
      // Forget the remembered conversation if it was the deleted one, then refetch.
      if (user && window.localStorage.getItem(`ai:last-conversation:${user.id}`) === String(conversationToDelete)) {
        window.localStorage.removeItem(`ai:last-conversation:${user.id}`)
      }
      refetch()
      
      // Close chat widget if the deleted conversation was open
      setSelectedCustomerId(null)
    } catch (error: any) {
      console.error('Delete conversation failed:', error)
      
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to delete conversation',
        variant: 'destructive',
      })
    } finally {
      setDeleteDialogOpen(false)
      setConversationToDelete(null)
    }
  }

  const getStatusStyle = (status: string) => {
    switch (status) {
      case 'active':
        return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
      case 'transferred':
        return 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
      case 'closed':
      default:
        return 'bg-muted text-muted-foreground'
    }
  }

  const getChannelIcon = (channel: string) => {
    switch (channel) {
      case 'whatsapp':
        return 'solar:chat-round-line-bold-duotone'
      case 'telegram':
        return 'solar:plain-2-bold-duotone'
      default:
        return 'solar:globus-bold-duotone'
    }
  }

  const offlineReason =
    (aiError as any)?.response?.status === 401 ? 'Not authenticated'
      : (aiError as any)?.response?.status === 503 ? 'Service unavailable'
        : (aiError as any)?.message?.includes('Network') ? 'Network error'
          : aiError ? 'Connection failed'
            : undefined

  const filteredConversations = conversations?.filter(conv => 
    !searchQuery || 
    conv.last_message?.toLowerCase().includes(searchQuery.toLowerCase()) ||
    conv.id.toString().includes(searchQuery)
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">AI Chatbot</h1>
          <p className="mt-1 text-muted-foreground">Manage AI-powered customer conversations</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* Service status pill */}
          {aiStatusLoading ? (
            <span className="inline-flex h-8 items-center gap-2 rounded-full border bg-card px-3 text-xs font-medium text-amber-600 dark:text-amber-400">
              <span className="h-2 w-2 animate-pulse rounded-full bg-amber-500" />
              Checking…
            </span>
          ) : aiStatusError ? (
            <span
              className="inline-flex h-8 items-center gap-2 rounded-full border bg-card px-3 text-xs font-medium text-rose-600 dark:text-rose-400"
              title={offlineReason}
            >
              <span className="h-2 w-2 rounded-full bg-rose-500" />
              Offline
              {offlineReason && <span className="font-normal text-muted-foreground">{offlineReason}</span>}
            </span>
          ) : aiStatus?.service?.status === 'running' ? (
            <span className="inline-flex h-8 items-center gap-2 rounded-full border bg-card px-3 text-xs font-medium text-emerald-600 dark:text-emerald-400" title={aiStatus.service?.provider ? `${aiStatus.service.provider} — ${aiStatus.service?.current_model ?? ''}` : undefined}>
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
              </span>
              Online
              <span className="font-normal text-muted-foreground">
                {aiStatus.service?.provider} — {aiStatus.service?.current_model}
              </span>
            </span>
          ) : (
            <span className="inline-flex h-8 items-center gap-2 rounded-full border bg-card px-3 text-xs font-medium text-orange-600 dark:text-orange-400">
              <span className="h-2 w-2 rounded-full bg-orange-500" />
              Degraded
            </span>
          )}

          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold-duotone"
            loading={isLoading}
            onClick={() => refetch()}
            disabled={isLoading}
          >
            Refresh
          </HeaderActionButton>
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={handleNewChat}>
            New Chat
          </HeaderActionButton>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Total Conversations"
          value={aiStats?.total_conversations ?? 0}
          hint="All time"
          icon="solar:chat-round-dots-bold-duotone"
        />
        <StatCard
          label="Active Chats"
          value={aiStats?.active_conversations ?? 0}
          hint="Currently active"
          icon="solar:chat-round-call-bold-duotone"
          tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
        />
        <StatCard
          label="Messages Today"
          value={aiStats?.messages_today ?? 0}
          hint="Created today"
          icon="solar:chat-line-bold-duotone"
          tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
        />
        <StatCard
          label="AI Status"
          value={aiStatus?.service.status === 'running' ? 'Healthy' : 'Down'}
          hint="Service status"
          icon="solar:cpu-bolt-bold-duotone"
          tone={aiStatus?.service.status === 'running'
            ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
            : 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400'}
        />
      </div>

      {/* Main Content */}
      <Tabs defaultValue="conversations" className="space-y-4">
        <TabsList>
          <TabsTrigger value="conversations" className="gap-2">
            <Icon icon="solar:chat-round-dots-bold-duotone" className="h-4 w-4" />
            Conversations
          </TabsTrigger>
          <TabsTrigger value="settings" className="gap-2">
            <Icon icon="solar:settings-bold-duotone" className="h-4 w-4" />
            Settings
          </TabsTrigger>
        </TabsList>

        {/* Conversations Tab */}
        <TabsContent value="conversations" className="space-y-4">
          <Card className="overflow-hidden rounded-xl">
            <CardHeader className="border-b">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                    <Icon icon="solar:chat-round-line-bold-duotone" className="h-5 w-5 text-primary" />
                  </div>
                  <div>
                    <CardTitle className="text-base">Recent Conversations</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                      {filteredConversations?.length ?? 0} conversation{(filteredConversations?.length ?? 0) === 1 ? '' : 's'}
                    </p>
                  </div>
                </div>
                <div className="relative w-full sm:w-64">
                  <Icon
                    icon="solar:magnifer-bold-duotone"
                    className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
                  />
                  <Input
                    placeholder="Search conversations..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="h-8 rounded-full pl-9 pr-3 text-xs"
                  />
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              {isLoading ? (
                <div className="flex items-center justify-center py-16">
                  <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
                </div>
              ) : filteredConversations && filteredConversations.length > 0 ? (
                <ScrollArea className="h-[420px]">
                  <div className="space-y-2 p-4">
                    {filteredConversations.map((conversation) => (
                      <div
                        key={conversation.id}
                        className="flex cursor-pointer items-start justify-between gap-3 rounded-xl border px-4 py-3 transition-colors hover:bg-muted/50"
                        onClick={(e) => handleOpenConversation(conversation.id, e)}
                      >
                        <div className="flex min-w-0 flex-1 items-start gap-3">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <Icon icon={getChannelIcon(conversation.channel)} className="h-4 w-4 text-muted-foreground" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <div className="mb-1 flex flex-wrap items-center gap-2">
                              <p className="text-sm font-medium">Conversation #{conversation.id}</p>
                              <Badge variant="secondary" className={`capitalize ${getStatusStyle(conversation.status)}`}>
                                {conversation.status}
                              </Badge>
                              <Badge variant="outline" className="font-normal">{conversation.language}</Badge>
                            </div>
                            <p className="line-clamp-2 text-sm text-muted-foreground">
                              {conversation.last_message || 'No messages yet'}
                            </p>
                            <div className="mt-1.5 flex items-center gap-4 text-xs text-muted-foreground">
                              <span className="tabular-nums">{conversation.message_count} messages</span>
                              <span>{new Date(conversation.started_at).toLocaleString()}</span>
                            </div>
                          </div>
                        </div>
                        <div className="flex shrink-0 gap-1.5">
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 w-7 p-0"
                            onClick={(e) => handleOpenConversation(conversation.id, e)}
                            title="Open conversation"
                            aria-label={`Open conversation ${conversation.id}`}
                          >
                            <Icon icon="solar:chat-round-dots-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 w-7 p-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={(e) => handleDeleteClick(conversation.id, e)}
                            title="Delete conversation"
                            aria-label={`Delete conversation ${conversation.id}`}
                          >
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                </ScrollArea>
              ) : (
                <div className="flex flex-col items-center gap-2 px-6 py-16 text-center text-muted-foreground">
                  <Icon icon="solar:chat-round-line-bold-duotone" className="h-12 w-12 opacity-30" />
                  <p className="text-sm font-medium">
                    {searchQuery ? 'No conversations match your search' : 'No conversations yet'}
                  </p>
                  <p className="text-xs">
                    {searchQuery ? 'Try a different search term.' : 'Start a conversation to see it here.'}
                  </p>
                  {!searchQuery && (
                    <HeaderActionButton
                      icon="solar:chat-round-call-bold-duotone"
                      className="mt-2"
                      onClick={handleNewChat}
                    >
                      Start First Conversation
                    </HeaderActionButton>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Settings Tab — configuration moved to Settings → General */}
        <TabsContent value="settings" className="space-y-4">
          <Card className="overflow-hidden rounded-xl">
            <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                  <Icon icon="solar:settings-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm font-medium">AI settings have moved</p>
                  <p className="text-xs text-muted-foreground">
                    Provider, model and API key (BYOK — Ollama local or Cerebras cloud) are configured
                    under Settings → General → AI Configuration.
                  </p>
                </div>
              </div>
              <HeaderActionButton variant="outline" icon="solar:alt-arrow-right-bold-duotone" asChild>
                <Link to="/settings?section=ai">Open AI Settings</Link>
              </HeaderActionButton>
            </CardContent>
          </Card>
        </TabsContent>

      </Tabs>

      {/* Chat Widget */}
      {selectedCustomerId && (
        <ChatWidget 
          key={chatKey} 
          customerId={selectedCustomerId}
          conversationId={selectedConversationId}
          initialModule={initialModule}
          autoOpen={chatAutoOpen}
          onConversationChange={rememberConversation}
        />
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Conversation</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this conversation? This action cannot be undone.
              All messages in this conversation will be permanently deleted.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteConfirm}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
