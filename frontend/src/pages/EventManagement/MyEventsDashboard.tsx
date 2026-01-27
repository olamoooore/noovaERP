import React, { useState, useEffect } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AccessibleButton } from '@/components/ui/accessible-button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { ResponsiveText, Heading1, Heading3, BodyText, SmallText } from '@/components/ui/responsive-text';
import { useBreakpoint } from '@/hooks/useBreakpoint';
import { EnhancedCard, EnhancedCardContent, EnhancedCardHeader, EnhancedCardTitle } from '@/components/ui/enhanced-card';
import { EnhancedBadge, getSemanticColor } from '@/components/ui/enhanced-badge';
import { AnimatedMetrics } from '@/components/ui/animated-metrics';
import { 
  Calendar, 
  MapPin, 
  Clock, 
  Download, 
  Share2, 
  Star, 
  Users, 
  TrendingUp,
  Bell,
  Heart,
  Ticket,
  CheckCircle,
  AlertCircle,
  Sparkles,
  Activity
} from 'lucide-react';
import { format } from 'date-fns';

interface Event {
  id: number;
  title: string;
  start_date: string;
  end_date: string;
  venue: {
    name: string;
    address: string;
  };
  artists: Array<{
    id: number;
    name: string;
    image_url?: string;
  }>;
  image_url?: string;
}

interface UpcomingEvent {
  booking_id: number;
  booking_reference: string;
  event: Event;
  tickets: Array<{
    id: number;
    ticket_number: string;
    type: string;
    price: number;
    status: string;
  }>;
  total_amount: number;
  status: string;
  can_check_in: boolean;
  can_cancel: boolean;
}

interface PastEvent {
  booking_id: number;
  booking_reference: string;
  event: Event;
  attended: boolean;
  can_review: boolean;
}

interface Recommendation {
  recommendation_id: number;
  event: Event & {
    price_range: {
      min: number;
      max: number;
    };
  };
  recommendation_type: string;
  confidence_score: number;
  factors: string[];
}

interface Statistics {
  total_bookings: number;
  total_spent: number;
  events_attended: number;
  favorite_artists: Array<{
    id: number;
    name: string;
    booking_count: number;
  }>;
  member_since: string;
}

interface DashboardData {
  upcoming_events: UpcomingEvent[];
  past_events: PastEvent[];
  recommendations: Recommendation[];
  recent_activity: Array<{
    id: number;
    interaction_type: string;
    event_title: string;
    event_id: number;
    interaction_at: string;
  }>;
  statistics: Statistics;
}

/**
 * MyEventsDashboard Component
 * 
 * Comprehensive customer event hub with personalized dashboard,
 * event management, and recommendations.
 * 
 * Requirements: 16.1, 16.2, 16.3
 */
const MyEventsDashboard: React.FC = () => {
  const [dashboardData, setDashboardData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState('upcoming');
  const { isMobile } = useBreakpoint();

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async () => {
    try {
      setLoading(true);
      // Use mock data for now until backend endpoint is deployed
      // TODO: Replace with actual API call when backend is ready
      const mockData: DashboardData = {
        upcoming_events: [],
        past_events: [],
        statistics: {
          total_bookings: 0,
          total_spent: 0,
          events_attended: 0,
          favorite_artists: [],
          member_since: new Date().toISOString(),
        },
        recommendations: [],
        recent_activity: [],
      };
      setDashboardData(mockData);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  const handleDownloadTicket = async (bookingId: number) => {
    try {
      const response = await fetch(`/api/bookings/${bookingId}/tickets/download`, {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'X-Tenant-ID': localStorage.getItem('tenantId') || '',
        },
      });

      if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `tickets-${bookingId}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
      }
    } catch (err) {
      console.error('Failed to download tickets:', err);
    }
  };

  const handleShareEvent = async (eventId: number, eventTitle: string) => {
    if (navigator.share) {
      try {
        await navigator.share({
          title: eventTitle,
          text: `Check out this amazing event: ${eventTitle}`,
          url: `${window.location.origin}/events/${eventId}`,
        });
      } catch (err) {
        console.error('Error sharing:', err);
      }
    } else {
      // Fallback to clipboard
      const url = `${window.location.origin}/events/${eventId}`;
      await navigator.clipboard.writeText(url);
      alert('Event link copied to clipboard!');
    }
  };

  const handleTrackRecommendation = async (recommendationId: number, interactionType: string) => {
    try {
      await fetch(`/api/customer/dashboard/recommendations/${recommendationId}/track`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${localStorage.getItem('token')}`,
          'X-Tenant-ID': localStorage.getItem('tenantId') || '',
        },
        body: JSON.stringify({ interaction_type: interactionType }),
      });
    } catch (err) {
      console.error('Failed to track recommendation:', err);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-32 w-32 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <Card className="w-full max-w-md">
          <CardContent className="pt-6">
            <div className="text-center">
              <AlertCircle className="mx-auto h-12 w-12 text-red-500 mb-4" />
              <h3 className="text-lg font-semibold text-gray-900 mb-2">Error Loading Dashboard</h3>
              <p className="text-gray-600 mb-4">{error}</p>
              <Button onClick={fetchDashboardData}>Try Again</Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (!dashboardData) {
    return null;
  }

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">My Events</Heading1>
        <BodyText variant="muted">Manage your bookings, discover new events, and track your experience</BodyText>
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.statistics.total_bookings}
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
              value={dashboardData.statistics.events_attended}
              format="number"
              label="Events Attended"
              icon={CheckCircle}
              size="md"
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <AnimatedMetrics
              value={dashboardData.statistics.total_spent}
              format="currency"
              label="Total Spent"
              icon={TrendingUp}
              size="md"
              precision={0}
            />
          </EnhancedCardContent>
        </EnhancedCard>

        <EnhancedCard variant="elevated" size="sm">
          <EnhancedCardContent>
            <div className="flex items-center">
              <Users className="h-8 w-8 text-purple-600" />
              <div className="ml-4">
                <ResponsiveText size="sm" weight="medium" variant="muted">Member Since</ResponsiveText>
                <ResponsiveText size="xl" weight="bold">
                  {dashboardData.statistics.member_since ? 
                    format(new Date(dashboardData.statistics.member_since), 'MMM yyyy') : 
                    'N/A'
                  }
                </ResponsiveText>
              </div>
            </div>
          </EnhancedCardContent>
        </EnhancedCard>
      </div>

      {/* Main Content Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="grid w-full grid-cols-4">
          <TabsTrigger value="upcoming">Upcoming Events</TabsTrigger>
          <TabsTrigger value="past">Past Events</TabsTrigger>
          <TabsTrigger value="recommendations">Recommendations</TabsTrigger>
          <TabsTrigger value="activity">Activity</TabsTrigger>
        </TabsList>

        {/* Upcoming Events Tab */}
        <TabsContent value="upcoming" className="space-y-6">
          {dashboardData.upcoming_events.length === 0 ? (
            <EnhancedCard variant="outlined">
              <EnhancedCardContent className="pt-6">
                <div className="text-center py-8">
                  <Calendar className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <Heading3 className="mb-2">No Upcoming Events</Heading3>
                  <BodyText variant="muted" className="mb-4">You don't have any upcoming events. Discover new events below!</BodyText>
                  <AccessibleButton 
                    onClick={() => setActiveTab('recommendations')} 
                    className="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700"
                    size={isMobile ? "default" : "lg"}
                    icon={Sparkles}
                    ariaLabel="View event recommendations"
                    screenReaderText="Switch to recommendations tab to discover new events"
                  >
                    <Sparkles className="w-4 h-4 mr-2" />
                    View Recommendations
                  </AccessibleButton>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.upcoming_events.map((booking) => (
                <EnhancedCard key={booking.booking_id} variant="elevated" className="overflow-hidden group hover:shadow-xl transition-all duration-300">
                  <div className="relative">
                    {booking.event.image_url && (
                      <div className="relative overflow-hidden">
                        <img 
                          src={booking.event.image_url} 
                          alt={booking.event.title}
                          className="w-full h-48 object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent" />
                      </div>
                    )}
                    <div className="absolute top-4 right-4">
                      <EnhancedBadge 
                        variant="status" 
                        color={getSemanticColor(booking.status, 'status')}
                        size="sm"
                      >
                        {booking.status}
                      </EnhancedBadge>
                    </div>
                  </div>
                  
                  <EnhancedCardContent className="p-6">
                    <EnhancedCardHeader className="px-0 pb-4">
                      <EnhancedCardTitle className="text-xl">{booking.event.title}</EnhancedCardTitle>
                    </EnhancedCardHeader>
                    
                    <div className="space-y-3 mb-4">
                      <div className="flex items-center text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-blue-500" />
                        <ResponsiveText size="sm" weight="medium">{format(new Date(booking.event.start_date), 'PPP')}</ResponsiveText>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <Clock className="h-4 w-4 mr-2 text-green-500" />
                        <ResponsiveText size="sm">{format(new Date(booking.event.start_date), 'p')}</ResponsiveText>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <MapPin className="h-4 w-4 mr-2 text-red-500" />
                        <ResponsiveText size="sm">{booking.event.venue.name}</ResponsiveText>
                      </div>
                    </div>

                    {/* Artists */}
                    {booking.event.artists.length > 0 && (
                      <div className="mb-4">
                        <ResponsiveText size="sm" weight="medium" className="mb-2 flex items-center">
                          <Star className="w-4 h-4 mr-1 text-yellow-500" />
                          Artists
                        </ResponsiveText>
                        <div className="flex flex-wrap gap-2">
                          {booking.event.artists.map((artist) => (
                            <div key={artist.id} className="flex items-center space-x-2 bg-gray-50 rounded-full px-3 py-1">
                              <Avatar className="h-6 w-6">
                                <AvatarImage src={artist.image_url} />
                                <AvatarFallback className="text-xs">{artist.name.charAt(0)}</AvatarFallback>
                              </Avatar>
                              <ResponsiveText size="sm" weight="medium">{artist.name}</ResponsiveText>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    {/* Enhanced Tickets Section with Progress Indicators */}
                    <div className="mb-4">
                      <ResponsiveText size="sm" weight="medium" className="mb-3 flex items-center justify-between">
                        <span className="flex items-center">
                          <Ticket className="w-4 h-4 mr-1 text-purple-500" />
                          Tickets ({booking.tickets.length})
                        </span>
                        <SmallText variant="muted">
                          ${booking.total_amount.toFixed(2)}
                        </SmallText>
                      </ResponsiveText>
                      <div className="space-y-2">
                        {booking.tickets.map((ticket) => (
                          <div key={ticket.id} className="flex justify-between items-center p-2 bg-gray-50 rounded-lg">
                            <div className="flex items-center space-x-2">
                              <div className={`w-2 h-2 rounded-full ${
                                ticket.status === 'confirmed' ? 'bg-green-500' :
                                ticket.status === 'pending' ? 'bg-yellow-500' : 'bg-gray-400'
                              }`} />
                              <ResponsiveText size="sm" weight="medium">{ticket.type}</ResponsiveText>
                            </div>
                            <EnhancedBadge 
                              variant="status" 
                              color={getSemanticColor(ticket.status, 'status')}
                              size="xs"
                            >
                              {ticket.status}
                            </EnhancedBadge>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Enhanced Actions */}
                    <div className="flex space-x-2">
                      <AccessibleButton 
                        size="sm" 
                        onClick={() => handleDownloadTicket(booking.booking_id)}
                        className="flex-1 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700"
                        icon={Download}
                        ariaLabel="Download tickets"
                        screenReaderText={`Download tickets for ${booking.event.title}`}
                      >
                        <Download className="h-4 w-4 mr-2" />
                        Download Tickets
                      </AccessibleButton>
                      <AccessibleButton 
                        size="sm" 
                        variant="outline"
                        onClick={() => handleShareEvent(booking.event.id, booking.event.title)}
                        className="hover:bg-gray-50"
                        icon={Share2}
                        ariaLabel="Share event"
                        screenReaderText={`Share ${booking.event.title} with others`}
                      >
                        <Share2 className="h-4 w-4" />
                      </AccessibleButton>
                    </div>

                    {booking.can_check_in && (
                      <div className="mt-3">
                        <AccessibleButton 
                          size="sm" 
                          className="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700" 
                          variant="secondary"
                          icon={CheckCircle}
                          ariaLabel="Check in to event"
                          screenReaderText={`Check in to ${booking.event.title} - check-in is now available`}
                        >
                          <CheckCircle className="h-4 w-4 mr-2" />
                          Check In Available
                        </AccessibleButton>
                      </div>
                    )}
                  </EnhancedCardContent>
                </EnhancedCard>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Past Events Tab */}
        <TabsContent value="past" className="space-y-6">
          {dashboardData.past_events.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <div className="text-center py-8">
                  <Clock className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Past Events</h3>
                  <p className="text-gray-600">You haven't attended any events yet.</p>
                </div>
              </CardContent>
            </Card>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.past_events.map((booking) => (
                <Card key={booking.booking_id} className="overflow-hidden">
                  <div className="relative">
                    {booking.event.image_url && (
                      <img 
                        src={booking.event.image_url} 
                        alt={booking.event.title}
                        className="w-full h-48 object-cover"
                      />
                    )}
                    <div className="absolute top-4 right-4">
                      <Badge variant={booking.attended ? 'default' : 'secondary'}>
                        {booking.attended ? 'Attended' : 'Missed'}
                      </Badge>
                    </div>
                  </div>
                  
                  <CardContent className="p-6">
                    <h3 className="text-xl font-semibold text-gray-900 mb-2">{booking.event.title}</h3>
                    
                    <div className="space-y-2 mb-4">
                      <div className="flex items-center text-gray-600">
                        <Calendar className="h-4 w-4 mr-2" />
                        <span>{format(new Date(booking.event.start_date), 'PPP')}</span>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <MapPin className="h-4 w-4 mr-2" />
                        <span>{booking.event.venue.name}</span>
                      </div>
                    </div>

                    {booking.can_review && (
                      <Button size="sm" className="w-full">
                        <Star className="h-4 w-4 mr-2" />
                        Write Review
                      </Button>
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Recommendations Tab */}
        <TabsContent value="recommendations" className="space-y-6">
          {dashboardData.recommendations.length === 0 ? (
            <EnhancedCard variant="outlined">
              <EnhancedCardContent className="pt-6">
                <div className="text-center py-8">
                  <Heart className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Recommendations</h3>
                  <p className="text-gray-600">We're learning your preferences. Check back soon for personalized recommendations!</p>
                </div>
              </EnhancedCardContent>
            </EnhancedCard>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {dashboardData.recommendations.map((rec) => (
                <EnhancedCard key={rec.recommendation_id} variant="elevated" className="overflow-hidden group hover:shadow-xl transition-all duration-300">
                  <div className="relative">
                    {rec.event.image_url && (
                      <div className="relative overflow-hidden">
                        <img 
                          src={rec.event.image_url} 
                          alt={rec.event.title}
                          className="w-full h-48 object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/20 to-transparent" />
                      </div>
                    )}
                    <div className="absolute top-4 right-4">
                      <EnhancedBadge 
                        variant="metric" 
                        color="primary"
                        size="sm"
                        icon={Sparkles}
                      >
                        {Math.round(rec.confidence_score * 100)}% Match
                      </EnhancedBadge>
                    </div>
                  </div>
                  
                  <EnhancedCardContent className="p-6">
                    <EnhancedCardHeader className="px-0 pb-4">
                      <EnhancedCardTitle className="text-xl">{rec.event.title}</EnhancedCardTitle>
                    </EnhancedCardHeader>
                    
                    <div className="space-y-3 mb-4">
                      <div className="flex items-center text-gray-600">
                        <Calendar className="h-4 w-4 mr-2 text-blue-500" />
                        <span className="font-medium">{format(new Date(rec.event.start_date), 'PPP')}</span>
                      </div>
                      <div className="flex items-center text-gray-600">
                        <MapPin className="h-4 w-4 mr-2 text-red-500" />
                        <span>{rec.event.venue.name}</span>
                      </div>
                    </div>

                    {/* Enhanced Recommendation Factors */}
                    <div className="mb-4">
                      <p className="text-sm font-medium text-gray-700 mb-2 flex items-center">
                        <Activity className="w-4 h-4 mr-1 text-green-500" />
                        Why we recommend this:
                      </p>
                      <div className="bg-gradient-to-r from-blue-50 to-purple-50 rounded-lg p-3">
                        <ul className="text-sm text-gray-700 space-y-1">
                          {rec.factors.map((factor, index) => (
                            <li key={index} className="flex items-start">
                              <span className="text-blue-600 mr-2 font-bold">•</span>
                              <span>{factor}</span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    </div>

                    {/* Enhanced Price Range */}
                    {rec.event.price_range && (
                      <div className="mb-4">
                        <div className="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                          <span className="text-sm font-medium text-gray-700">Price Range:</span>
                          <span className="text-lg font-bold text-green-600">
                            ${rec.event.price_range.min} - ${rec.event.price_range.max}
                          </span>
                        </div>
                      </div>
                    )}

                    <div className="flex space-x-2">
                      <Button 
                        size="sm" 
                        className="flex-1 bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700"
                        onClick={() => {
                          handleTrackRecommendation(rec.recommendation_id, 'clicked');
                          window.location.href = `/events/${rec.event.id}`;
                        }}
                      >
                        <Star className="h-4 w-4 mr-2" />
                        View Event
                      </Button>
                      <Button 
                        size="sm" 
                        variant="outline"
                        onClick={() => handleTrackRecommendation(rec.recommendation_id, 'dismissed')}
                        className="hover:bg-gray-50"
                      >
                        Not Interested
                      </Button>
                    </div>
                  </EnhancedCardContent>
                </EnhancedCard>
              ))}
            </div>
          )}
        </TabsContent>

        {/* Activity Tab */}
        <TabsContent value="activity" className="space-y-6">
          <EnhancedCard variant="elevated">
            <EnhancedCardHeader>
              <EnhancedCardTitle className="flex items-center gap-2">
                <Bell className="w-5 h-5" />
                Recent Activity
              </EnhancedCardTitle>
            </EnhancedCardHeader>
            <EnhancedCardContent>
              {dashboardData.recent_activity.length === 0 ? (
                <div className="text-center py-8">
                  <Bell className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-semibold text-gray-900 mb-2">No Recent Activity</h3>
                  <p className="text-gray-600">Your activity will appear here as you interact with events.</p>
                </div>
              ) : (
                <div className="space-y-4">
                  {dashboardData.recent_activity.map((activity, index) => (
                    <div key={activity.id} className="relative">
                      {/* Timeline line */}
                      {index < dashboardData.recent_activity.length - 1 && (
                        <div className="absolute left-6 top-8 w-0.5 h-8 bg-gradient-to-b from-blue-200 to-gray-200" />
                      )}
                      
                      <div className="flex items-start space-x-4 p-4 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                        <div className="flex-shrink-0 relative">
                          <div className="w-3 h-3 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full shadow-sm" />
                          <div className="absolute inset-0 w-3 h-3 bg-blue-400 rounded-full animate-ping opacity-20" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-gray-900 flex items-center">
                              <Activity className="w-4 h-4 mr-2 text-blue-500" />
                              {activity.interaction_type.charAt(0).toUpperCase() + activity.interaction_type.slice(1)} 
                              <span className="mx-1 text-gray-500">•</span>
                              <span className="text-blue-600 hover:text-blue-800 cursor-pointer">
                                {activity.event_title}
                              </span>
                            </p>
                            <EnhancedBadge variant="status" color="info" size="xs">
                              {format(new Date(activity.interaction_at), 'MMM d')}
                            </EnhancedBadge>
                          </div>
                          <p className="text-sm text-gray-500 mt-1">
                            {format(new Date(activity.interaction_at), 'PPp')}
                          </p>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </EnhancedCardContent>
          </EnhancedCard>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default MyEventsDashboard;