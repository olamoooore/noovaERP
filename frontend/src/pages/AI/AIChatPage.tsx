import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
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
  const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null)
  const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [chatKey, setChatKey] = useState(0) // Force new chat widget
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [conversationToDelete, setConversationToDelete] = useState<number | null>(null)
  const { toast } = useToast()
  const user = useAuthStore((state) => state.user)
  
  // Use current user's ID as customer_id
  const currentCustomerId = user?.id || 1

  // Get AI service status with error handling
  const { data: aiStatus, isError: aiStatusError, error: aiError, isLoading: aiStatusLoading } = useQuery({
    queryKey: ['ai-status'],
    queryFn: async () => {
      const response = await api.get('/ai/health')
      return response.data
    },
    refetchInterval: 15000, // Refresh every 15 seconds
    retry: 1,
  })

  // Get all conversations
  const { data: conversations, isLoading, refetch } = useQuery({
    queryKey: ['ai-conversations', currentCustomerId],
    queryFn: async () => {
      // Fetch conversations for the current logged-in user
      const response = await api.get('/ai/conversations', {
        params: { customer_id: currentCustomerId },
      })
      return response.data.conversations as Conversation[]
    },
  })

  const handleNewChat = () => {
    // Force create a new chat by incrementing the key
    setChatKey(prev => prev + 1)
    setSelectedCustomerId(currentCustomerId)
    setSelectedConversationId(null) // New conversation, no history
    // Refetch conversations after a short delay to show the new one
    setTimeout(() => refetch(), 1000)
  }

  const handleOpenConversation = (conversationId: number, e: React.MouseEvent) => {
    e.stopPropagation()
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
      
      // Refetch conversations
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

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'active':
        return 'bg-green-500'
      case 'closed':
        return 'bg-gray-500'
      case 'transferred':
        return 'bg-blue-500'
      default:
        return 'bg-gray-500'
    }
  }

  const getChannelIcon = (channel: string) => {
    switch (channel) {
      case 'whatsapp':
        return 'mdi:whatsapp'
      case 'telegram':
        return 'mdi:telegram'
      default:
        return 'mdi:web'
    }
  }

  const filteredConversations = conversations?.filter(conv => 
    !searchQuery || 
    conv.last_message?.toLowerCase().includes(searchQuery.toLowerCase()) ||
    conv.id.toString().includes(searchQuery)
  )

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">AI Chatbot</h1>
          <p className="text-muted-foreground">
            Manage AI-powered customer conversations
          </p>
        </div>
        
        {/* AI Status Indicator */}
        <Card className="w-72">
          <CardContent className="pt-4 pb-4">
            <div className="flex items-center gap-3">
              {aiStatusLoading ? (
                <>
                  <div className="w-3 h-3 rounded-full bg-yellow-500 animate-pulse" />
                  <div>
                    <p className="text-sm font-medium text-yellow-600">Checking...</p>
                    <p className="text-xs text-muted-foreground">Connecting to AI service</p>
                  </div>
                </>
              ) : aiStatusError ? (
                <>
                  <div className="w-3 h-3 rounded-full bg-red-500" />
                  <div>
                    <p className="text-sm font-medium text-red-600">Offline</p>
                    <p className="text-xs text-muted-foreground">
                      {(aiError as any)?.response?.status === 401 ? 'Not authenticated' : 
                       (aiError as any)?.response?.status === 503 ? 'Service unavailable' :
                       (aiError as any)?.message?.includes('Network') ? 'Network error' :
                       'Connection failed'}
                    </p>
                  </div>
                </>
              ) : aiStatus?.status === 'healthy' ? (
                <>
                  <div className="w-3 h-3 rounded-full bg-green-500 animate-pulse" />
                  <div>
                    <p className="text-sm font-medium text-green-600">Online</p>
                    <p className="text-xs text-muted-foreground">
                      {aiStatus.service || 'AI ready'}
                    </p>
                  </div>
                </>
              ) : (
                <>
                  <div className="w-3 h-3 rounded-full bg-orange-500" />
                  <div>
                    <p className="text-sm font-medium text-orange-600">Degraded</p>
                    <p className="text-xs text-muted-foreground">
                      Service may be slow
                    </p>
                  </div>
                </>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Conversations</CardTitle>
            <Icon icon="mdi:chat" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{conversations?.length || 0}</div>
            <p className="text-xs text-muted-foreground">All time</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Active Chats</CardTitle>
            <Icon icon="mdi:chat-processing" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {conversations?.filter(c => c.status === 'active').length || 0}
            </div>
            <p className="text-xs text-muted-foreground">Currently active</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Messages Today</CardTitle>
            <Icon icon="mdi:message" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {conversations?.reduce((sum, c) => sum + c.message_count, 0) || 0}
            </div>
            <p className="text-xs text-muted-foreground">Total messages</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">AI Status</CardTitle>
            <Icon icon="mdi:robot" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {aiStatus?.service.status === 'running' ? 'Healthy' : 'Down'}
            </div>
            <p className="text-xs text-muted-foreground">Service status</p>
          </CardContent>
        </Card>
      </div>

      {/* Main Content */}
      <Tabs defaultValue="conversations" className="space-y-4">
        <TabsList>
          <TabsTrigger value="conversations">Conversations</TabsTrigger>
          <TabsTrigger value="settings">Settings</TabsTrigger>
        </TabsList>

        {/* Conversations Tab */}
        <TabsContent value="conversations" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>Recent Conversations</CardTitle>
                <div className="flex gap-2">
                  <Input
                    placeholder="Search conversations..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-64"
                  />
                  <Button onClick={() => refetch()} variant="outline" size="sm">
                    <Icon icon="mdi:refresh" className="h-4 w-4" />
                  </Button>
                  <Button onClick={handleNewChat} size="sm">
                    <Icon icon="mdi:plus" className="h-4 w-4 mr-2" />
                    New Chat
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {isLoading ? (
                <div className="text-center py-8">
                  <Icon icon="mdi:loading" className="h-8 w-8 animate-spin mx-auto" />
                  <p className="text-sm text-muted-foreground mt-2">Loading conversations...</p>
                </div>
              ) : filteredConversations && filteredConversations.length > 0 ? (
                <ScrollArea className="h-[400px]">
                  <div className="space-y-3">
                    {filteredConversations.map((conversation) => (
                      <Card 
                        key={conversation.id} 
                        className="hover:bg-muted/50 cursor-pointer transition-colors"
                        onClick={(e) => handleOpenConversation(conversation.id, e)}
                      >
                        <CardContent className="pt-6">
                          <div className="flex items-start justify-between">
                            <div className="flex items-start gap-3 flex-1">
                              <Icon
                                icon={getChannelIcon(conversation.channel)}
                                className="h-5 w-5 mt-1"
                              />
                              <div className="flex-1">
                                <div className="flex items-center gap-2 mb-1">
                                  <p className="font-medium">Conversation #{conversation.id}</p>
                                  <Badge
                                    variant="secondary"
                                    className={`${getStatusColor(conversation.status)} text-white`}
                                  >
                                    {conversation.status}
                                  </Badge>
                                  <Badge variant="outline">{conversation.language}</Badge>
                                </div>
                                <p className="text-sm text-muted-foreground line-clamp-2">
                                  {conversation.last_message || 'No messages yet'}
                                </p>
                                <div className="flex items-center gap-4 mt-2 text-xs text-muted-foreground">
                                  <span>{conversation.message_count} messages</span>
                                  <span>
                                    {new Date(conversation.started_at).toLocaleString()}
                                  </span>
                                </div>
                              </div>
                            </div>
                            <div className="flex gap-1">
                              <Button 
                                variant="ghost" 
                                size="sm"
                                onClick={(e) => handleOpenConversation(conversation.id, e)}
                                title="Open conversation"
                              >
                                <Icon icon="mdi:message-text" className="h-4 w-4" />
                              </Button>
                              <Button 
                                variant="ghost" 
                                size="sm"
                                onClick={(e) => handleDeleteClick(conversation.id, e)}
                                className="text-destructive hover:text-destructive hover:bg-destructive/10"
                                title="Delete conversation"
                              >
                                <Icon icon="mdi:delete" className="h-4 w-4" />
                              </Button>
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                </ScrollArea>
              ) : (
                <div className="text-center py-8">
                  <Icon icon="mdi:chat-outline" className="h-12 w-12 mx-auto text-muted-foreground" />
                  <p className="text-sm text-muted-foreground mt-2 mb-4">
                    {searchQuery ? 'No conversations found' : 'No conversations yet'}
                  </p>
                  {!searchQuery && (
                    <Button onClick={handleNewChat} size="sm">
                      <Icon icon="mdi:chat" className="h-4 w-4 mr-2" />
                      Start First Conversation
                    </Button>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Settings Tab */}
        <TabsContent value="settings" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>AI Configuration</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {aiStatus && (
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Provider</span>
                    <span className="text-sm text-muted-foreground">
                      {aiStatus.service.provider}
                    </span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Model</span>
                    <span className="text-sm text-muted-foreground">
                      {aiStatus.service.current_model}
                    </span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Status</span>
                    <Badge variant={aiStatus.service.status === 'running' ? 'default' : 'destructive'}>
                      {aiStatus.service.status}
                    </Badge>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Available Models</span>
                    <span className="text-sm text-muted-foreground">
                      {aiStatus.service.available_models?.length || 0} models
                    </span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Function Calling</span>
                    <Badge variant={aiStatus?.features?.function_calling ? 'default' : 'secondary'}>
                      {aiStatus?.features?.function_calling ? 'Enabled' : 'Disabled'}
                    </Badge>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-sm font-medium">Multi-language</span>
                    <Badge variant={aiStatus?.features?.function_calling ? 'default' : 'secondary'}>
                      {aiStatus?.features?.function_calling ? 'Enabled' : 'Disabled'}
                    </Badge>
                  </div>
                </div>
              )}
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
          onConversationEnd={() => refetch()}
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
