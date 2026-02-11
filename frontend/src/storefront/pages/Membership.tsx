import { useState } from 'react';
import { Crown, Star, Gift, Percent, Zap, Heart, Check } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

const tiers = [
  {
    name: 'Free',
    price: 0,
    discount: 0,
    color: 'from-muted to-muted/80',
    textColor: 'text-foreground',
    benefits: [
      'Newsletter updates',
      'Basic member account',
      'Event notifications',
    ],
  },
  {
    name: 'Bronze',
    price: 9.90,
    discount: 5,
    color: 'from-amber-700 to-amber-500',
    textColor: 'text-white',
    benefits: [
      '5% off all purchases',
      'Early access to sales',
      'Birthday reward',
      'Points on purchases',
    ],
  },
  {
    name: 'Silver',
    price: 19.90,
    discount: 10,
    color: 'from-slate-400 to-slate-300',
    textColor: 'text-slate-900',
    benefits: [
      '10% off all purchases',
      '24-hour early access',
      'Double birthday reward',
      '2x points multiplier',
      'Free local delivery',
    ],
  },
  {
    name: 'Gold',
    price: 49.90,
    discount: 15,
    color: 'from-yellow-500 to-amber-300',
    textColor: 'text-amber-900',
    popular: true,
    benefits: [
      '15% off all purchases',
      '48-hour early access',
      'Triple birthday reward',
      '3x points multiplier',
      'Free express delivery',
      'VIP event access',
      'Exclusive merchandise',
    ],
  },
];

const benefits = [
  { icon: Percent, title: 'Exclusive Discounts', description: 'Save up to 15% on every purchase' },
  { icon: Zap, title: 'Early Access', description: 'Be the first to shop new drops' },
  { icon: Gift, title: 'Birthday Rewards', description: 'Special treats on your special day' },
  { icon: Star, title: 'Points System', description: 'Earn points with every purchase' },
  { icon: Heart, title: 'Member Events', description: 'Access to exclusive fan events' },
  { icon: Crown, title: 'VIP Treatment', description: 'Priority support and services' },
];

const Membership = () => {
  const [showJoinModal, setShowJoinModal] = useState(false);
  const [selectedTier, setSelectedTier] = useState<typeof tiers[0] | null>(null);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    birthday: '',
  });

  const handleJoin = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Store member data in localStorage
    localStorage.setItem('memberData', JSON.stringify({
      ...formData,
      tier: selectedTier?.name || 'Free',
      joinDate: new Date().toISOString(),
    }));

    toast.success(`Welcome to INFINITI Club!`, {
      description: `You're now a ${selectedTier?.name || 'Free'} member!`,
    });
    
    setShowJoinModal(false);
    setFormData({ name: '', email: '', phone: '', birthday: '' });
  };

  return (
    <Layout>
      {/* Hero */}
      <section className="relative py-16 md:py-24 overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-b from-primary/10 via-transparent to-transparent" />
        <div className="absolute top-0 left-1/4 w-96 h-96 rounded-full bg-primary/20 blur-3xl" />
        <div className="absolute bottom-0 right-1/4 w-80 h-80 rounded-full bg-accent/15 blur-3xl" />

        <div className="container relative text-center">
          <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/20 text-primary mb-6">
            <Crown className="h-4 w-4" />
            <span className="text-sm font-semibold">Join 10,000+ Members</span>
          </div>

          <h1 className="text-4xl md:text-5xl lg:text-6xl font-display font-bold mb-6">
            Become an{' '}
            <span className="gradient-text">INFINITI</span>{' '}
            Member
          </h1>
          
          <p className="text-lg md:text-xl text-muted-foreground max-w-2xl mx-auto mb-10">
            Unlock exclusive discounts, early access to drops, VIP events, and more. Choose the tier that fits your fandom level.
          </p>
        </div>
      </section>

      {/* Benefits Grid */}
      <section id="benefits" className="py-16 bg-muted/30">
        <div className="container">
          <h2 className="text-3xl font-display font-bold text-center mb-12">
            Member <span className="gradient-text">Benefits</span>
          </h2>

          <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            {benefits.map((benefit, index) => (
              <div
                key={benefit.title}
                className="p-6 rounded-xl glass card-hover animate-fade-in"
                style={{ animationDelay: `${index * 100}ms` }}
              >
                <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-primary/20 to-accent/20 flex items-center justify-center mb-4">
                  <benefit.icon className="h-6 w-6 text-primary" />
                </div>
                <h3 className="font-display font-semibold text-lg mb-2">{benefit.title}</h3>
                <p className="text-muted-foreground">{benefit.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Pricing Tiers */}
      <section className="py-16 md:py-24">
        <div className="container">
          <h2 className="text-3xl font-display font-bold text-center mb-4">
            Choose Your <span className="gradient-text">Tier</span>
          </h2>
          <p className="text-center text-muted-foreground mb-12">
            Select the membership level that matches your fandom
          </p>

          <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
            {tiers.map((tier, index) => (
              <div
                key={tier.name}
                className={cn(
                  "relative rounded-2xl overflow-hidden animate-fade-in",
                  tier.popular && "ring-2 ring-primary"
                )}
                style={{ animationDelay: `${index * 100}ms` }}
              >
                {tier.popular && (
                  <div className="absolute top-0 left-0 right-0 bg-primary text-primary-foreground text-center py-1 text-xs font-semibold">
                    MOST POPULAR
                  </div>
                )}

                <div className={cn("p-6 bg-gradient-to-br", tier.color, tier.popular && "pt-8")}>
                  <h3 className={cn("text-xl font-display font-bold", tier.textColor)}>
                    {tier.name}
                  </h3>
                  <div className={cn("mt-2", tier.textColor)}>
                    <span className="text-3xl font-display font-bold">
                      {tier.price === 0 ? 'FREE' : `SGD ${tier.price}`}
                    </span>
                    {tier.price > 0 && <span className="text-sm opacity-80">/month</span>}
                  </div>
                  {tier.discount > 0 && (
                    <p className={cn("text-sm mt-1", tier.textColor, "opacity-90")}>
                      {tier.discount}% off all purchases
                    </p>
                  )}
                </div>

                <div className="p-6 bg-card">
                  <ul className="space-y-3 mb-6">
                    {tier.benefits.map((benefit) => (
                      <li key={benefit} className="flex items-start gap-2 text-sm">
                        <Check className="h-4 w-4 text-primary mt-0.5 shrink-0" />
                        <span>{benefit}</span>
                      </li>
                    ))}
                  </ul>

                  <Button
                    variant={tier.popular ? 'hero' : 'outline'}
                    className="w-full"
                    onClick={() => {
                      setSelectedTier(tier);
                      setShowJoinModal(true);
                    }}
                  >
                    {tier.price === 0 ? 'Join Free' : 'Subscribe'}
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Join Modal */}
      <Dialog open={showJoinModal} onOpenChange={setShowJoinModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-xl font-display">
              Join as {selectedTier?.name} Member
            </DialogTitle>
          </DialogHeader>

          <form onSubmit={handleJoin} className="space-y-4">
            <div>
              <Label htmlFor="join-name">Full Name</Label>
              <Input
                id="join-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                required
                placeholder="Enter your full name"
              />
            </div>
            <div>
              <Label htmlFor="join-email">Email</Label>
              <Input
                id="join-email"
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                required
                placeholder="Enter your email"
              />
            </div>
            <div>
              <Label htmlFor="join-phone">Phone Number</Label>
              <Input
                id="join-phone"
                type="tel"
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                required
                placeholder="+65 XXXX XXXX"
              />
            </div>
            <div>
              <Label htmlFor="join-birthday">Birthday</Label>
              <Input
                id="join-birthday"
                type="date"
                value={formData.birthday}
                onChange={(e) => setFormData({ ...formData, birthday: e.target.value })}
                required
              />
            </div>

            <div className="pt-4 border-t border-border">
              <div className="flex justify-between items-center mb-4">
                <span className="text-muted-foreground">Membership</span>
                <span className="font-display font-bold">
                  {selectedTier?.price === 0 ? 'FREE' : `SGD ${selectedTier?.price}/mo`}
                </span>
              </div>
              <Button variant="neon" type="submit" className="w-full">
                Complete Registration
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </Layout>
  );
};

export default Membership;
