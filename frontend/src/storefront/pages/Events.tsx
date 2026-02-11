import { useState, useEffect } from 'react';
import { Calendar, MapPin, Users, Ticket } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { EVENTS, Event } from '@/storefront/data/events';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';

interface CountdownProps {
  targetDate: Date;
  large?: boolean;
}

const Countdown = ({ targetDate, large }: CountdownProps) => {
  const [timeLeft, setTimeLeft] = useState({ days: 0, hours: 0, minutes: 0, seconds: 0 });

  useEffect(() => {
    const calculateTimeLeft = () => {
      const difference = targetDate.getTime() - new Date().getTime();
      if (difference > 0) {
        setTimeLeft({
          days: Math.floor(difference / (1000 * 60 * 60 * 24)),
          hours: Math.floor((difference / (1000 * 60 * 60)) % 24),
          minutes: Math.floor((difference / 1000 / 60) % 60),
          seconds: Math.floor((difference / 1000) % 60),
        });
      }
    };

    calculateTimeLeft();
    const timer = setInterval(calculateTimeLeft, 1000);
    return () => clearInterval(timer);
  }, [targetDate]);

  return (
    <div className="flex gap-3">
      {Object.entries(timeLeft).map(([unit, value]) => (
        <div key={unit} className="text-center">
          <div
            className={`rounded-xl bg-muted flex items-center justify-center ${
              large ? 'w-16 h-16 md:w-20 md:h-20' : 'w-14 h-14'
            }`}
          >
            <span
              className={`font-display font-bold text-primary ${
                large ? 'text-2xl md:text-3xl' : 'text-xl'
              }`}
            >
              {value.toString().padStart(2, '0')}
            </span>
          </div>
          <span className="text-xs text-muted-foreground mt-2 block capitalize">
            {unit}
          </span>
        </div>
      ))}
    </div>
  );
};

const Events = () => {
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [showRegistration, setShowRegistration] = useState(false);
  const [formData, setFormData] = useState({ name: '', email: '', phone: '' });
  const [queueNumber, setQueueNumber] = useState<number | null>(null);

  const handleRegister = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Get next queue number from localStorage
    const currentQueue = parseInt(localStorage.getItem('eventQueueNumber') || '0');
    const newQueueNumber = currentQueue + 1;
    localStorage.setItem('eventQueueNumber', newQueueNumber.toString());
    
    setQueueNumber(newQueueNumber);
    toast.success('Registration successful!', {
      description: `Your queue number is #${newQueueNumber.toString().padStart(4, '0')}`,
    });
  };

  const closeModal = () => {
    setShowRegistration(false);
    setSelectedEvent(null);
    setFormData({ name: '', email: '', phone: '' });
    setQueueNumber(null);
  };

  return (
    <Layout>
      <div className="container py-8 md:py-12">
        {/* Header */}
        <div className="text-center mb-12">
          <h1 className="text-4xl md:text-5xl font-display font-bold mb-4">
            Upcoming <span className="gradient-text">Events</span>
          </h1>
          <p className="text-lg text-muted-foreground max-w-2xl mx-auto">
            Join exclusive fan experiences, meet fellow K-pop enthusiasts, and create unforgettable memories.
          </p>
        </div>

        {/* Events List */}
        <div className="space-y-8">
          {EVENTS.map((event, index) => (
            <div
              key={event.id}
              className="group glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              <div className="grid md:grid-cols-2 gap-6">
                {/* Image */}
                <div className="relative aspect-video md:aspect-auto overflow-hidden">
                  <img
                    src={event.image}
                    alt={event.title}
                    className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                    loading="lazy"
                  />
                  <div className="absolute inset-0 bg-gradient-to-r from-background/60 to-transparent md:hidden" />
                  
                  {/* Spots Badge */}
                  <div className="absolute top-4 right-4">
                    <Badge variant={event.spotsLeft < 50 ? 'limited' : 'default'}>
                      {event.spotsLeft} spots left
                    </Badge>
                  </div>
                </div>

                {/* Content */}
                <div className="p-6 md:p-8 flex flex-col">
                  <h2 className="text-2xl md:text-3xl font-display font-bold mb-3 group-hover:text-primary transition-colors">
                    {event.title}
                  </h2>
                  <p className="text-muted-foreground mb-6">
                    {event.description}
                  </p>

                  {/* Countdown */}
                  <div className="mb-6">
                    <p className="text-sm text-muted-foreground mb-3">Event starts in:</p>
                    <Countdown targetDate={event.date} />
                  </div>

                  {/* Details */}
                  <div className="space-y-3 text-sm text-muted-foreground mb-6">
                    <div className="flex items-center gap-3">
                      <Calendar className="h-5 w-5 text-primary" />
                      <span>
                        {event.date.toLocaleDateString('en-SG', {
                          weekday: 'long',
                          day: 'numeric',
                          month: 'long',
                          year: 'numeric',
                          hour: '2-digit',
                          minute: '2-digit',
                        })}
                      </span>
                    </div>
                    <div className="flex items-center gap-3">
                      <MapPin className="h-5 w-5 text-primary" />
                      <span>{event.venue}</span>
                    </div>
                    <div className="flex items-center gap-3">
                      <Users className="h-5 w-5 text-primary" />
                      <span>{event.spotsLeft} / {event.totalSpots} spots available</span>
                    </div>
                  </div>

                  {/* Price & CTA */}
                  <div className="mt-auto flex items-center justify-between">
                    <div>
                      <span className="text-3xl font-display font-bold">
                        {event.price === 0 ? 'FREE' : `SGD ${event.price.toFixed(2)}`}
                      </span>
                    </div>
                    <Button
                      variant="hero"
                      onClick={() => {
                        setSelectedEvent(event);
                        setShowRegistration(true);
                      }}
                    >
                      <Ticket className="h-5 w-5 mr-2" />
                      Register Now
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Registration Modal */}
      <Dialog open={showRegistration} onOpenChange={closeModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-xl font-display">
              {queueNumber ? 'Registration Complete!' : `Register for ${selectedEvent?.title}`}
            </DialogTitle>
          </DialogHeader>

          {queueNumber ? (
            <div className="text-center py-6">
              <div className="w-32 h-32 mx-auto mb-6 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center">
                <Ticket className="h-16 w-16 text-primary-foreground" />
              </div>
              <p className="text-muted-foreground mb-2">Your queue number is</p>
              <p className="text-4xl font-display font-bold gradient-text mb-4">
                #{queueNumber.toString().padStart(4, '0')}
              </p>
              <p className="text-sm text-muted-foreground mb-6">
                A confirmation email has been sent to {formData.email}
              </p>
              <Button variant="neon" onClick={closeModal}>
                Done
              </Button>
            </div>
          ) : (
            <form onSubmit={handleRegister} className="space-y-4">
              <div>
                <Label htmlFor="name">Full Name</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  required
                  placeholder="Enter your full name"
                />
              </div>
              <div>
                <Label htmlFor="email">Email</Label>
                <Input
                  id="email"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  required
                  placeholder="Enter your email"
                />
              </div>
              <div>
                <Label htmlFor="phone">Phone Number</Label>
                <Input
                  id="phone"
                  type="tel"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  required
                  placeholder="+65 XXXX XXXX"
                />
              </div>
              <div className="flex justify-between items-center pt-4">
                <span className="font-display font-bold text-lg">
                  {selectedEvent?.price === 0 ? 'FREE' : `SGD ${selectedEvent?.price.toFixed(2)}`}
                </span>
                <Button variant="neon" type="submit">
                  Complete Registration
                </Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </Layout>
  );
};

export default Events;
