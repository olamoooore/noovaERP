import React from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Calendar, MapPin, Search, Filter, Ticket } from 'lucide-react';
import { Link } from 'react-router-dom';

/**
 * BrowseEventsPage
 * 
 * Displays a list of available events for browsing and booking.
 */
const BrowseEventsPage: React.FC = () => {
  // Mock featured events for display
  const featuredEvents = [
    {
      id: 1,
      title: 'Summer Music Festival 2026',
      date: '2026-07-15',
      location: 'Central Park',
      price: '$75',
      image: 'https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=400',
    },
    {
      id: 2,
      title: 'Tech Conference 2026',
      date: '2026-08-20',
      location: 'Convention Center',
      price: '$150',
      image: 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=400',
    },
    {
      id: 3,
      title: 'Jazz Night',
      date: '2026-03-10',
      location: 'Blue Note Jazz Club',
      price: '$45',
      image: 'https://images.unsplash.com/photo-1415201364774-f6f0bb35f28f?w=400',
    },
  ];

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">Browse Events</Heading1>
        <BodyText variant="muted">Discover and book tickets for upcoming events</BodyText>
      </div>

      {/* Search and Filters */}
      <div className="flex flex-col md:flex-row gap-4 mb-8">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground h-4 w-4" />
          <input 
            type="text" 
            placeholder="Search events..." 
            className="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
          />
        </div>
        <Button variant="outline" className="flex items-center gap-2">
          <Filter className="h-4 w-4" />
          Filters
        </Button>
      </div>

      {/* Featured Events */}
      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        {featuredEvents.map((event) => (
          <Card key={event.id} className="overflow-hidden hover:shadow-lg transition-shadow">
            <div 
              className="h-48 bg-cover bg-center" 
              style={{ backgroundImage: `url(${event.image})` }}
            />
            <CardContent className="p-4">
              <h3 className="font-semibold text-lg mb-2">{event.title}</h3>
              <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                <Calendar className="h-4 w-4" />
                <span>{new Date(event.date).toLocaleDateString()}</span>
              </div>
              <div className="flex items-center gap-2 text-muted-foreground text-sm mb-3">
                <MapPin className="h-4 w-4" />
                <span>{event.location}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="font-bold text-lg">{event.price}</span>
                <Link to={`/events/${event.id}`}>
                  <Button size="sm" className="flex items-center gap-2">
                    <Ticket className="h-4 w-4" />
                    Book Now
                  </Button>
                </Link>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Empty state message */}
      <div className="text-center py-12 text-muted-foreground">
        <Calendar className="h-16 w-16 mx-auto mb-4 opacity-50" />
        <p>More events coming soon...</p>
        <p className="text-sm">Check back frequently for new events in your area</p>
      </div>
    </div>
  );
};

export default BrowseEventsPage;
