import React, { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { AccessibleButton } from '@/components/ui/AccessibleButton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ResponsiveText, Heading1, Heading3, BodyText } from '@/components/ui/responsive-text';
import { EnhancedCard, EnhancedCardContent, EnhancedCardHeader, EnhancedCardTitle } from '@/components/ui/enhanced-card';
import { EnhancedBadge, getSemanticColor } from '@/components/ui/EnhancedBadge';
import { AnimatedMetrics } from '@/components/ui/AnimatedMetrics';
import { 
  Calendar, 
  MapPin, 
  Clock, 
  Download, 
  Share2, 
  Ticket,
  CheckCircle,
  AlertCircle
} from 'lucide-react';
import { format } from 'date-fns';
import { useCustomerDashboard, useDownloadTicket } from '@/hooks/queries';
import { LoadingSpinner } from '@/components/ui/skeleton';
import { useToast } from '@/hooks/use-toast';

/**
 * MyEventsDashboard Component
 * 
 * Comprehensive customer event hub with personalized dashboard,
 * event management, and recommendations.
 * 
 * Requirements: 16.1, 16.2, 16.3
 */
const MyEventsDashboard: React.FC = () => {
  // UI state only - server data now managed by React Query
  const [activeTab, setActiveTab] = useState('upcoming');
  const { toast } = useToast();
  
  // React Query hooks for server state
  const { 
    data: dashboardData, 
    isLoading, 
    error 
  } = useCustomerDashboard();
  
  // Mutations
  const downloadTicketMutation = useDownloadTicket();

  // Handle ticket download with React Query mutation
  const handleDownloadTicket = async (bookingId: number) => {
    try {
      await downloadTicketMutation.mutateAsync(bookingId);
      
      toast({
        title: "Success",
        description: "Ticket downloaded successfully",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to download tickets. Please try again.",
        variant: "destructive",
      });
    }
  };

  const handleShareEvent = async (eventId: number, eventTitle: string) => {
    try {
      if (navigator.share) {
        await navigator.share({
          title: eventTitle,
          text: `Check out this amazing event: ${eventTitle}`,
          url: `${window.location.origin}/events/${eventId}`,
        });
        toast({
          title: "Success",
          description: "Event shared successfully",
        });
      } else {
        // Fallback to clipboard
        const url = `${window.location.origin}/events/${eventId}`;
        await navigator.clipboard.writeText(url);
        toast({
          title: "Success",
          description: "Event link copied to clipboard",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to share event. Please try again.",
        variant: "destructive",
      });
    }
  };

  // Loading and error states
  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-8">
              <AlertCircle className="mx-auto h-12 w-12 text-red-400 mb-4" />
              <Heading3 className="mb-2">Failed to Load Dashboard</Heading3>
              <BodyText variant="muted" className="mb-4">
                There was an error loading your dashboard. Please try again.
              </BodyText>
              <AccessibleButton 
                onClick={() => window.location.reload()}
                ariaLabel="Retry loading dashboard"
                screenReaderText="Reload the page to retry loading your dashboard"
              >
                Try Again
              </AccessibleButton>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!dashboardData) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-8">
              <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
              <Heading3 className="mb-2">No Data Available</Heading3>
              <BodyText variant="muted">No dashboard data is currently available.</BodyText>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">My Events</Heading1>
        <BodyText variant="muted">Manage your bookings, discover new events, and track your experience</BodyText>
      </div>

      {/* Statistics Cards - Using available data */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.upcoming_events.length}
              format="number"
              label="Upcoming Events"
              icon={Calendar}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.bookings.length}
              format="number"
              label="Total Bookings"
              icon={Ticket}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.tickets.length}
              format="number"
              label="Total Tickets"
              icon={CheckCircle}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>
      </div>

      {/* Main Content Tabs - Simplified to match API */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-2">
          <TabsTrigger value="upcoming">Upcoming Events</TabsTrigger>
          <TabsTrigger value="bookings">My Bookings</TabsTrigger>
        </TabsList>

        {/* Upcoming Events Tab */}
        <TabsContent value="upcoming" className="space-y-6">
          {dashboardData.upcoming_events.length === 0 ? (
            <EnhancedCard variant="outlined">
              <EnhancedCardContent className="pt-6">
                <div className="text-center py-8">
                  <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <Heading3 className="mb-2">No Upcoming Events</Heading3>
                  <BodyText variant="muted" className="mb-4">No upcoming events have been booked yet.</BodyText>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.upcoming_events.map((event) => (
                <EnhancedCard key={event.id} variant="elevated" className="overflow-hidden group hover:shadow-xl transition-all duration-300">
                  <EnhancedCardContent className="p-6">
                    <EnhancedCardHeader className="px-0 pb-4">
                      <EnhancedCardTitle className="text-xl">{event.name}</EnhancedCardTitle>
                    </EnhancedCardHeader>
                    
                    <div className="space-y-3 mb-4">
                      <div className="flex items-center text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-blue-500" />
                        <ResponsiveText size="sm" weight="medium">{format(new Date(event.start_date), 'PPP')}</ResponsiveText>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <Clock className="h-4 w-4 mr-2 text-green-500" />
                        <ResponsiveText size="sm">{format(new Date(event.start_date), 'p')}</ResponsiveText>
                      </div>
                      {event.venue && (
                        <div className="flex items-center text-gray-600">
                          <MapPin className="h-4 w-4 mr-2 text-red-500" />
                          <ResponsiveText size="sm">{event.venue}</ResponsiveText>
                        </div>
                      )}
                    </div>

                    <div className="flex space-x-2">
                      <AccessibleButton 
                        size="sm" 
                        variant="outline"
                        onClick={() => handleShareEvent((event as any)._eventId ?? event.id, event.name)}
                        className="hover:bg-gray-50"
                        ariaLabel="Share event"
                        screenReaderText={`Share ${event.name} with others`}
                      >
                        <Share2 className="h-4 w-4 mr-2" />
                        Share
                      </AccessibleButton>
                    </div>
                  </EnhancedCardContent>
                </EnhancedCard>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Bookings Tab */}
        <TabsContent value="bookings" className="space-y-6">
          {dashboardData.bookings.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <div className="text-center py-8">
                  <Ticket className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Bookings Yet</h3>
                  <p className="text-gray-600">Bookings made on the storefront will appear here.</p>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.bookings.map((booking) => {
                const b = booking as any;
                return (
                  <Card key={booking.id} className="overflow-hidden">
                    <CardContent className="p-6">
                      <div className="flex justify-between items-start mb-3">
                        <div className="flex-1 min-w-0 mr-3">
                          <h3 className="text-base font-semibold text-gray-900 truncate">
                            {b.event_title ?? `Event #${booking.event_id}`}
                          </h3>
                          <ResponsiveText size="sm" variant="muted">
                            Ref: {b.booking_reference ?? `#${booking.id}`}
                          </ResponsiveText>
                        </div>
                        <EnhancedBadge
                          variant="status"
                          color={getSemanticColor(booking.status, 'status')}
                          size="sm"
                        >
                          {booking.status}
                        </EnhancedBadge>
                      </div>

                      <div className="space-y-2 mb-4 text-sm">
                        {b.buyer_name && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Customer:</span>
                            <span className="font-medium">{b.buyer_name}</span>
                          </div>
                        )}
                        {b.buyer_email && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Email:</span>
                            <span className="font-medium text-xs">{b.buyer_email}</span>
                          </div>
                        )}
                        {b.event_venue && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Venue:</span>
                            <span className="font-medium">{b.event_venue}</span>
                          </div>
                        )}
                        {b.event_date && (
                          <div className="flex justify-between">
                            <span className="text-gray-500">Event Date:</span>
                            <span className="font-medium">
                              {format(new Date(b.event_date), 'PP')}
                            </span>
                          </div>
                        )}
                        <div className="flex justify-between">
                          <span className="text-gray-500">Booked:</span>
                          <span className="font-medium">
                            {booking.booking_date ? format(new Date(booking.booking_date), 'PP') : '—'}
                          </span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-500">Amount:</span>
                          <span className="font-bold">
                            {booking.total_amount === 0 ? 'FREE' : `SGD ${Number(booking.total_amount).toFixed(2)}`}
                          </span>
                        </div>
                      </div>

                      <AccessibleButton
                        size="sm"
                        onClick={() => handleDownloadTicket(booking.id)}
                        className="w-full"
                        ariaLabel="Download tickets"
                        screenReaderText={`Download tickets for booking ${booking.id}`}
                      >
                        <Download className="h-4 w-4 mr-2" />
                        Download Ticket
                      </AccessibleButton>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default MyEventsDashboard;