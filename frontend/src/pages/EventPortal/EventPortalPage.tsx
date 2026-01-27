import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { EnhancedCard, EnhancedCardContent, EnhancedCardHeader, EnhancedCardTitle } from '@/components/ui/enhanced-card'
import { ResponsiveGrid, ResponsiveContainer, ResponsiveStack } from '@/components/ui/responsive-grid'
import { ResponsiveText, Heading1, BodyText } from '@/components/ui/responsive-text'
import { AccessibleButton } from '@/components/ui/accessible-button'
import { DashboardSkeleton, LoadingSpinner } from '@/components/ui/skeleton'
import { useBreakpoint } from '@/hooks/useBreakpoint'
import { formatCurrency, formatDate } from '@/lib/utils'
import { Calendar, MapPin, Users, Ticket, Search, Filter, RefreshCw } from 'lucide-react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'

interface Event {
  id: number
  title: string
  description: string
  date: string
  location: string
  capacity: number
  available_tickets: number
  price: number
  category: string
  image_url?: string
  status: 'upcoming' | 'ongoing' | 'completed'
}

export function EventPortalPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [selectedCategory, setSelectedCategory] = useState<string>('all')
  const { isMobile } = useBreakpoint()

  const { data: eventsResponse, isLoading, error } = useQuery({
    queryKey: ['public-events'],
    queryFn: async () => {
      const response = await api.get('/events/public')
      return response.data
    },
    retry: 2,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })

  // Extract the events array from the response
  const events = eventsResponse?.data || []

  const categories = ['all', 'Concert', 'Conference', 'Exhibition', 'Sports']

  const filteredEvents = events?.filter((event: Event) => {
    const matchesSearch = event.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
                         event.description.toLowerCase().includes(searchQuery.toLowerCase())
    const matchesCategory = selectedCategory === 'all' || event.category === selectedCategory
    return matchesSearch && matchesCategory
  })

  if (isLoading) {
    return (
      <ResponsiveContainer maxWidth="2xl" className="space-y-6">
        <Heading1>Event Portal</Heading1>
        <DashboardSkeleton />
      </ResponsiveContainer>
    )
  }

  if (error) {
    return (
      <ResponsiveContainer maxWidth="2xl" className="space-y-6">
        <div className="space-y-2">
          <Heading1 className="text-3xl md:text-4xl">Event Portal</Heading1>
          <BodyText size="lg" variant="muted">
            Browse and book tickets for upcoming events
          </BodyText>
        </div>
        
        <EnhancedCard>
          <EnhancedCardContent className="py-12 text-center">
            <Calendar className="h-12 w-12 mx-auto text-red-500 mb-4" />
            <ResponsiveText size="lg" weight="semibold" className="mb-2 text-red-600">
              Unable to load events
            </ResponsiveText>
            <BodyText variant="muted" className="mb-4">
              We're having trouble connecting to our servers. Please try again later.
            </BodyText>
            <AccessibleButton
              onClick={() => window.location.reload()}
              variant="outline"
              icon={RefreshCw}
            >
              Retry
            </AccessibleButton>
          </EnhancedCardContent>
        </EnhancedCard>
      </ResponsiveContainer>
    )
  }

  return (
    <ResponsiveContainer maxWidth="2xl" className="space-y-6">
      {/* Header */}
      <div className="space-y-2">
        <Heading1 className="text-3xl md:text-4xl">Event Portal</Heading1>
        <BodyText size="lg" variant="muted">
          Browse and book tickets for upcoming events
        </BodyText>
      </div>

      {/* Search and Filter */}
      <EnhancedCard>
        <EnhancedCardContent className="pt-6">
          <ResponsiveStack
            direction={{ mobile: 'col', tablet: 'row', desktop: 'row' }}
            gap={{ mobile: '1rem', tablet: '1rem', desktop: '1rem' }}
            align="center"
          >
            <div className="relative flex-1 w-full">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search events..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-10"
              />
            </div>
            <ResponsiveStack
              direction={{ mobile: 'row', tablet: 'row', desktop: 'row' }}
              gap={{ mobile: '0.5rem', tablet: '0.5rem', desktop: '0.5rem' }}
              className="w-full md:w-auto"
            >
              {categories.map((category) => (
                <AccessibleButton
                  key={category}
                  variant={selectedCategory === category ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setSelectedCategory(category)}
                  className="flex-1 md:flex-none"
                >
                  {category.charAt(0).toUpperCase() + category.slice(1)}
                </AccessibleButton>
              ))}
            </ResponsiveStack>
          </ResponsiveStack>
        </EnhancedCardContent>
      </EnhancedCard>

      {/* Events Grid */}
      <ResponsiveGrid
        columns={{
          mobile: 1,
          tablet: 2,
          desktop: 3,
          wide: 3
        }}
        gap={{
          mobile: '1.5rem',
          tablet: '1.5rem',
          desktop: '1.5rem',
          wide: '2rem'
        }}
      >
        {filteredEvents?.map((event: Event) => (
          <EnhancedCard key={event.id} variant="elevated" interactive className="hover:shadow-xl transition-all duration-300">
            <EnhancedCardHeader>
              <div className="flex items-start justify-between">
                <EnhancedCardTitle className="flex-1">
                  <ResponsiveText size="lg" weight="semibold" className="line-clamp-2">
                    {event.title}
                  </ResponsiveText>
                </EnhancedCardTitle>
                <Badge variant={event.available_tickets > 0 ? 'default' : 'destructive'}>
                  {event.available_tickets > 0 ? 'Available' : 'Sold Out'}
                </Badge>
              </div>
            </EnhancedCardHeader>
            <EnhancedCardContent className="space-y-4">
              <BodyText size="sm" variant="muted" className="line-clamp-3">
                {event.description}
              </BodyText>

              <div className="space-y-2">
                <div className="flex items-center gap-2 text-sm">
                  <Calendar className="h-4 w-4 text-muted-foreground" />
                  <ResponsiveText size="sm">{formatDate(event.date)}</ResponsiveText>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <MapPin className="h-4 w-4 text-muted-foreground" />
                  <ResponsiveText size="sm" className="line-clamp-1">{event.location}</ResponsiveText>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <Users className="h-4 w-4 text-muted-foreground" />
                  <ResponsiveText size="sm">
                    {event.available_tickets} / {event.capacity} tickets available
                  </ResponsiveText>
                </div>
              </div>

              <div className="flex items-center justify-between pt-4 border-t">
                <div>
                  <BodyText size="xs" variant="muted">Price</BodyText>
                  <ResponsiveText size="xl" weight="bold" className="text-primary">
                    {formatCurrency(event.price)}
                  </ResponsiveText>
                </div>
                <AccessibleButton
                  variant="default"
                  size="sm"
                  icon={Ticket}
                  disabled={event.available_tickets === 0}
                  ariaLabel={`Book tickets for ${event.title}`}
                >
                  Book Now
                </AccessibleButton>
              </div>
            </EnhancedCardContent>
          </EnhancedCard>
        ))}
      </ResponsiveGrid>

      {filteredEvents?.length === 0 && (
        <EnhancedCard>
          <EnhancedCardContent className="py-12 text-center">
            <Calendar className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <ResponsiveText size="lg" weight="semibold" className="mb-2">
              No events found
            </ResponsiveText>
            <BodyText variant="muted">
              Try adjusting your search or filter criteria
            </BodyText>
          </EnhancedCardContent>
        </EnhancedCard>
      )}
    </ResponsiveContainer>
  )
}
